<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

trait AdditionalParsers
{
    private function xpath(string $raw): \DOMXPath
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$raw, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    public function parseBcp(string $raw): array
    {
        $xp = $this->xpath($raw);
        $date = $xp->evaluate('string(//input[@id="fecha"]/@value)');
        if (! preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
            throw new RuntimeException('BCP: fecha de la planilla ausente.');
        }
        $day = CarbonImmutable::createFromFormat('!d/m/Y', $date)->format('Y-m-d');
        $rows = [];
        foreach ($xp->query('//tr[td]') as $tr) {
            $cells = $xp->query('./td', $tr);
            if ($cells->length !== 4) {
                continue;
            }
            $currency = trim($cells->item(1)->textContent);
            if (! in_array($currency, ['USD', 'BRL', 'ARS', 'EUR'])) {
                continue;
            }
            $text = trim($cells->item(3)->textContent);
            if (! preg_match('/^\d[\d.]*,\d+$/', $text)) {
                throw new RuntimeException('BCP: formato numérico cambiado.');
            }
            $value = $this->number($text);
            if ($value <= 0) {
                throw new RuntimeException('BCP: cotización no positiva.');
            }
            $rows[] = ['metric' => 'FX_PYG_'.$currency, 'asset' => $currency, 'value' => $value,
                'unit' => 'PYG/'.$currency, 'grain' => 'day', 'measured_at' => null,
                'period_start' => $day.' 00:00:00', 'period_end' => $day.' 23:59:59',
                'quality' => 'OFFICIAL_DAILY_REFERENCE', 'provenance' => ['publication_date' => $day,
                    'original_date' => $date, 'column' => 'Guaraníes por unidad de moneda extranjera',
                    'timestamp_note' => 'Fecha de referencia; sin hora de medición publicada.']];
        }
        if (count(array_filter($rows, fn ($r) => $r['asset'] === 'USD')) !== 1) {
            throw new RuntimeException('BCP: USD ausente o duplicado.');
        }

        return $rows;
    }

    public function parseNoaa(string $raw): array
    {
        $xp = $this->xpath($raw);
        $text = $xp->evaluate('string(//body)');
        if (! preg_match('/Issued\s+([A-Za-z]+)\s+(\d{4})/', $text, $m)) {
            throw new RuntimeException('NOAA: mes de emisión no encontrado.');
        }
        $issue = CarbonImmutable::parse('1 '.$m[1].' '.$m[2], 'UTC');
        $seasonMonths = ['DJF' => 12, 'JFM' => 1, 'FMA' => 2, 'MAM' => 3, 'AMJ' => 4, 'MJJ' => 5, 'JJA' => 6, 'JAS' => 7, 'ASO' => 8, 'SON' => 9, 'OND' => 10, 'NDJ' => 11];
        $result = [];
        $year = $issue->year;
        $previousMonth = null;
        foreach ($xp->query('//table[@id="probabilities-table"]//tr[td]') as $tr) {
            $season = substr(trim($xp->evaluate('string(./th)', $tr)), 0, 3);
            if (! isset($seasonMonths[$season])) {
                throw new RuntimeException('NOAA: estación trimestral desconocida.');
            }
            $month = $seasonMonths[$season];
            if ($previousMonth !== null && $month < $previousMonth) {
                $year++;
            }
            if ($previousMonth === null && $month > $issue->month + 6) {
                $year--;
            }
            $previousMonth = $month;
            $from = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');
            $cells = $xp->query('./td', $tr);
            if ($cells->length !== 3) {
                throw new RuntimeException('NOAA: columnas modificadas.');
            }
            $values = [];
            foreach ($cells as $cell) {
                $v = trim($cell->textContent);
                if (! is_numeric($v) || $v < 0 || $v > 100) {
                    throw new RuntimeException('NOAA: probabilidad inválida.');
                }$values[] = (float) $v;
            }
            if (abs(array_sum($values) - 100) > 1) {
                throw new RuntimeException('NOAA: probabilidades no suman 100%.');
            }
            foreach (['LA_NINA', 'NEUTRAL', 'EL_NINO'] as $i => $phase) {
                $result[] = ['metric' => 'ENSO_PROB_'.$phase, 'asset' => 'ENSO_'.$season.'_'.$year, 'value' => $values[$i], 'unit' => 'percent', 'grain' => 'season', 'measured_at' => null, 'period_start' => $from->format('Y-m-d H:i:s'), 'period_end' => $from->addMonths(3)->subSecond()->format('Y-m-d H:i:s'), 'quality' => 'OFFICIAL_FORECAST', 'provenance' => ['issue_month' => $issue->format('Y-m'), 'season' => $season, 'phase' => $phase, 'model' => 'NOAA CPC ENSO RONI', 'forecast' => true, 'uncertainty' => 'Probabilidad publicada por NOAA; no predice por sí sola impactos en Paraguay.']];
            }
        }
        if (count($result) < 3) {
            throw new RuntimeException('NOAA: tabla de probabilidades vacía.');
        }

        return $result;
    }

    public function parseInternational(string $raw): array
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, $raw);
        rewind($h);
        $keys = fgetcsv($h, 0, ';', '"', '');
        $keys[0] = ltrim($keys[0], "\xEF\xBB\xBF");
        foreach (['din_instante', 'nom_paisdestino', 'val_intercambiomwmed'] as $key) {
            if (! in_array($key, $keys)) {
                throw new RuntimeException('ONS intercambio: falta '.$key);
            }
        }
        $rows = [];
        while (($v = fgetcsv($h, 0, ';', '"', '')) !== false) {
            if (count($keys) !== count($v)) {
                throw new RuntimeException('ONS intercambio: fila incompleta.');
            }
            $r = array_combine($keys, $v);
            if (trim($r['nom_paisdestino']) !== 'Paraguai') {
                continue;
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $r['din_instante'])) {
                throw new RuntimeException('ONS intercambio: fecha inválida.');
            }
            if ($r['val_intercambiomwmed'] === '') {
                continue;
            }
            if (! is_numeric($r['val_intercambiomwmed']) || abs((float) $r['val_intercambiomwmed']) > 15000) {
                throw new RuntimeException('ONS intercambio: potencia inválida.');
            }
            $rows[] = ['metric' => 'INTERCHANGE_BR_PY_MW', 'asset' => 'BR_PY_CONVERTERS', 'value' => (float) $r['val_intercambiomwmed'], 'unit' => 'MW', 'grain' => 'hour', 'measured_at' => null, 'quality' => 'OFFICIAL_HOURLY_TIMEZONE_UNVERIFIED', 'confidence' => .85, 'provenance' => ['source_local_time' => $r['din_instante'], 'timezone_status' => 'UNVERIFIED', 'raw_row' => $r, 'positive_direction' => 'BR_TO_PY', 'negative_direction' => 'PY_TO_BR', 'definition' => 'Intercambio verificado en convertidor(es) fronterizos; no generación de Acaray.', 'dictionary_url' => 'https://ons-aws-prod-opendata.s3.amazonaws.com/dataset/intercambio_internacional_ho/DicionarioDados_Intercambio_Internacional.json']];
        }
        fclose($h);
        if (! $rows) {
            throw new RuntimeException('ONS 2026 no publica filas Paraguay en esta captura; no se sustituyen con intercambios Argentina/Brasil.');
        }

        return array_slice($rows, -24 * 35);
    }

    public function parseEbyHydrology(string $raw): array
    {
        $xp = $this->xpath($raw);
        $title = trim($xp->evaluate('string(//h2[contains(@class,"page-title")])'));
        if (! preg_match('/Cota:\s*(\d{2}[.,]\d+)\s*msnm.*?(\d{2}-\d{2}-\d{4})/u', $title, $m)) {
            throw new RuntimeException('EBY hidrología: título sin cota y fecha inequívocas.');
        }
        $day = CarbonImmutable::createFromFormat('!d-m-Y', $m[2])->format('Y-m-d');
        $value = (float) str_replace(',', '.', $m[1]);
        if ($value < 60 || $value > 100) {
            throw new RuntimeException('EBY: cota fuera de rango.');
        }

        return [['metric' => 'RESERVOIR_LEVEL_M', 'asset' => 'YACYRETA', 'value' => $value, 'unit' => 'm a.s.l.', 'grain' => 'day', 'measured_at' => null, 'period_start' => $day.' 00:00:00', 'period_end' => $day.' 23:59:59', 'quality' => 'OFFICIAL_DAILY_REPORT', 'provenance' => ['publication_date' => $day, 'report_title' => $title, 'timestamp_note' => 'Fecha del informe; hora de medición no publicada.']]];
    }
}
