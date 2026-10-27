<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/** Reads the units and cached numeric cells of CAMMESA's original workbook. */
class CammesaBinationalWorkbook
{
    public const SHEET = 'CENTRALES HI BINACIONALES';
    public const URL = 'https://cammesaweb.cammesa.com/download/hidro-binacional/';

    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir la planilla Hidro Binacional.');
        }
        try {
            $strings = [];
            if (($shared = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xml = $this->xml($shared);
                foreach ($xml->xpath('//*[local-name()="si"]') as $item) {
                    $strings[] = $this->textNodes($item);
                }
            }
            $workbook = $this->xml($zip->getFromName('xl/workbook.xml'));
            $sheetId = null;
            foreach ($workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheet) {
                if ((string) $sheet['name'] === self::SHEET) {
                    $sheetId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                }
            }
            $target = null;
            foreach ($this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'))->xpath('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') as $relation) {
                if ((string) $relation['Id'] === $sheetId) {
                    $target = (string) $relation['Target'];
                }
            }
            if (! $target) {
                throw new RuntimeException('No se encontró la hoja Hidro Binacional esperada.');
            }
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            $sheet = $this->xml($zip->getFromName($target));
            $rows = [];
            foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                $cells = [];
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    $address = (string) $cell['r'];
                    preg_match('/^[A-Z]+/', $address, $match);
                    $value = (string) $cell->v;
                    if ((string) $cell['t'] === 's') {
                        $value = $strings[(int) $value] ?? '';
                    } elseif ((string) $cell['t'] === 'inlineStr') {
                        $value = $this->textNodes($cell->is);
                    }
                    $cells[$match[0]] = $value;
                }
                $rows[(int) $row['r']] = $cells;
            }
        } finally {
            $zip->close();
        }

        $headerRow = null;
        foreach ($rows as $number => $row) {
            if (($row['A'] ?? '') === 'AÑO' && ($row['C'] ?? '') === 'MES') {
                $headerRow = $number;
            }
        }
        if (! $headerRow || ! str_contains($rows[$headerRow]['D'] ?? '', 'Generada NETA YACY')
            || ! str_contains($rows[$headerRow]['E'] ?? '', 'recibida YAC AR')
            || ! str_contains($rows[$headerRow]['F'] ?? '', 'recibida YAC PY')) {
            throw new RuntimeException('Las columnas de energía de CAMMESA no coinciden con las definiciones esperadas.');
        }
        foreach (['D', 'E', 'F'] as $column) {
            if (($rows[$headerRow - 1][$column] ?? '') !== 'MWh') {
                throw new RuntimeException('La unidad de energía CAMMESA debe estar expresada en MWh.');
            }
        }
        $monthly = [];
        foreach ($rows as $number => $row) {
            if ($number <= $headerRow || ! is_numeric($row['C'] ?? null) || (float) $row['C'] < 40000) {
                continue;
            }
            $start = CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addDays((int) $row['C']);
            if ($start->day !== 1 || (int) ($row['A'] ?? 0) !== $start->year || (int) ($row['B'] ?? 0) !== $start->daysInMonth) {
                throw new RuntimeException('El período mensual CAMMESA no coincide con su año o cantidad de días.');
            }
            foreach (['D', 'E', 'F'] as $column) {
                if (! is_numeric($row[$column] ?? null) || (float) $row[$column] < 0) {
                    throw new RuntimeException('Falta una energía mensual CAMMESA válida en '.$column.$number.'.');
                }
            }
            if (abs((float) $row['D'] - (float) $row['E'] - (float) $row['F']) > .02) {
                throw new RuntimeException('La generación neta CAMMESA no coincide con las entregas en la fila '.$number.'.');
            }
            $period = $start->format('Y-m');
            if (isset($monthly[$period])) {
                throw new RuntimeException('El mes '.$period.' está duplicado en la planilla CAMMESA.');
            }
            $monthly[$period] = ['row' => $number, 'start' => $start->format('Y-m-d'), 'end' => $start->endOfMonth()->format('Y-m-d'), 'cells' => $row];
        }
        if (! $monthly) {
            throw new RuntimeException('La planilla CAMMESA no contiene meses válidos para importar.');
        }
        ksort($monthly);
        $annual = [];
        foreach ($rows as $number => $row) {
            if ($number >= $headerRow || ! is_numeric($row['C'] ?? null) || (int) $row['C'] < 1900 || (int) $row['C'] > 2100) {
                continue;
            }
            $year = (int) $row['C'];
            $months = array_filter($monthly, fn ($month) => str_starts_with($month['start'], $year.'-'));
            if (! $months) {
                continue;
            }
            $end = end($months)['end'];
            $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'UTC');
            $days = (int) $start->diffInDays(CarbonImmutable::parse($end, 'UTC')->addDay());
            if ($days !== (int) ($row['B'] ?? 0)) {
                throw new RuntimeException('La cobertura del resumen anual CAMMESA no coincide con los meses disponibles.');
            }
            $annual[] = ['row' => $number, 'start' => $start->format('Y-m-d'), 'end' => $end, 'grain' => str_ends_with($end, '-12-31') ? 'year' : 'YTD', 'cells' => $row];
        }

        return ['monthly' => array_values($monthly), 'annual' => $annual];
    }

    private function xml(string|false $xml): SimpleXMLElement
    {
        if ($xml === false || ($node = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET)) === false) {
            throw new RuntimeException('La estructura XML de CAMMESA no es válida.');
        }
        return $node;
    }

    private function textNodes(SimpleXMLElement $node): string
    {
        $node->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        return implode('', array_map(fn ($text) => (string) $text, $node->xpath('.//m:t')));
    }
}
