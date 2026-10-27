<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/** Retains the original cells of all workbook sheets, including uncached formulas. */
class ArchiveWorkbookV5Sheets extends Command
{
    protected $signature = 'energy:archive-workbook-v5 {--dry-run : Revisar hojas sin modificar la base}';

    protected $description = 'Conserva las celdas y fórmulas de las 36 hojas del Excel maestro';

    private const SHEETS = ['README', 'Coverage', 'Sources', 'Source_Documents', 'Assets', 'Measurements', 'Canonical',
        'Prices', 'Retail_Tariff_History', 'Retail_Tariff_Current', 'Retail_Tariff_Summary', 'Macro_Annual', 'Macro_Current',
        'Tariff_Normalized', 'Macro_Notes', 'Economic_Terms', 'Economic_Transactions', 'Infrastructure', 'Metric_Definitions',
        'Forecast_Scenarios', 'Quality_Issues', 'Import_Queue', 'News_References', 'KPI_Preview', 'Chart_Data',
        'Current_Baselines', 'ANDE_Customers_2025', 'Hydrology_Current', 'ENSO_Outlook', 'Climate_Snapshot',
        'Precip_Snapshot', 'Household_Context', 'Grid_Quality', 'Planning_Baselines', 'Source_Ingestion_Status', 'V5_KPI_Preview'];

    public function handle(): int
    {
        $path = storage_path('app/energiapy/base_v5/EnergiaPY_Base_Maestra_v5.xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el Excel maestro V5.');
        }
        try {
            $strings = [];
            if (($content = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                foreach ($this->xml($content)->xpath('//*[local-name()="si"]') as $node) {
                    $strings[] = $this->text($node);
                }
            }
            $relations = [];
            foreach ($this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'))->xpath('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') as $r) {
                $relations[(string) $r['Id']] = (string) $r['Target'];
            }
            $sheets = [];
            foreach ($this->xml($zip->getFromName('xl/workbook.xml'))->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheet) {
                $name = (string) $sheet['name'];
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $target = $relations[$id] ?? '';
                if (! $target) {
                    throw new RuntimeException('No se encontró la hoja '.$name.'.');
                }
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                $rows = [];
                foreach ($this->xml($zip->getFromName($target))->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                    $cells = [];
                    foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                        $address = (string) $cell['r'];
                        $type = (string) $cell['t'];
                        $valueNodes = $cell->xpath('./*[local-name()="v"]');
                        $formulaNodes = $cell->xpath('./*[local-name()="f"]');
                        $inline = $cell->xpath('./*[local-name()="is"]');
                        if (! $valueNodes && ! $formulaNodes && ! $inline) {
                            continue;
                        }
                        $value = $valueNodes ? (string) $valueNodes[0] : null;
                        if ($type === 's' && $value !== null) {
                            $value = $strings[(int) $value] ?? null;
                        } elseif ($type === 'inlineStr' && $inline) {
                            $value = $this->text($inline[0]);
                        } elseif ($type === 'b' && $value !== null) {
                            $value = $value === '1';
                        }
                        $entry = ['value' => $value, 'type' => $type ?: 'n'];
                        if (isset($cell['s'])) {
                            $entry['style'] = (string) $cell['s'];
                        }
                        if ($formulaNodes) {
                            $formula = $formulaNodes[0];
                            $entry['formula'] = (string) $formula;
                            $entry['formula_attributes'] = new \stdClass;
                            foreach ($formula->attributes() as $key => $attribute) {
                                $entry['formula_attributes']->{$key} = (string) $attribute;
                            }
                        }
                        $cells[$address] = $entry;
                    }
                    if ($cells) {
                        $rows[(int) $row['r']] = ['workbook' => basename($path), 'sheet' => $name, 'excel_row' => (int) $row['r'], 'cells' => $cells];
                    }
                }
                $sheets[$name] = $rows;
            }
            foreach (self::SHEETS as $name) {
                if (! array_key_exists($name, $sheets)) {
                    throw new RuntimeException('Falta la hoja '.$name.' en el Excel maestro.');
                }
            }
        } finally {
            $zip->close();
        }
        $checksum = hash_file('sha256', $path);
        if (! $this->option('dry-run')) {
            DB::transaction(function () use ($sheets, $checksum) {
                $names = [];
                foreach ($sheets as $name => $rows) {
                    $dataset = 'workbook_v5_'.Str::slug($name, '_');
                    if (isset($names[$dataset])) {
                        throw new RuntimeException('Dos hojas del Excel tienen el mismo identificador.');
                    }
                    $names[$dataset] = true;
                    if (DB::table('v5_records')->where('dataset', $dataset)->where('checksum', '!=', $checksum)->exists()) {
                        throw new RuntimeException('La hoja '.$name.' ya existe con otra versión; conservá la versión anterior antes de actualizarla.');
                    }
                    foreach ($rows as $number => $payload) {
                        DB::table('v5_records')->updateOrInsert(['dataset' => $dataset, 'row_number' => $number], [
                            'checksum' => $checksum, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            });
        }
        foreach ($sheets as $name => $rows) {
            $this->line($name.': '.count($rows).' filas conservadas'.($this->option('dry-run') ? ' (sin cambios)' : '').'.');
        }

        return self::SUCCESS;
    }

    private function xml(string|false $value): SimpleXMLElement
    {
        if ($value === false || ($xml = simplexml_load_string($value, SimpleXMLElement::class, LIBXML_NONET)) === false) {
            throw new RuntimeException('El Excel maestro contiene XML no válido.');
        }

        return $xml;
    }

    private function text(SimpleXMLElement $node): string
    {
        return implode('', array_map(fn ($t) => (string) $t, $node->xpath('.//*[local-name()="t"]')));
    }
}
