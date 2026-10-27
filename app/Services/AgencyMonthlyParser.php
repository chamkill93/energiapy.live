<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

/** Strictly accepts a single clearly attributed, same-paragraph monthly report. */
class AgencyMonthlyParser
{
    public function parse(string $raw): array
    {
        $posts = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($posts) || ! array_is_list($posts)) {
            throw new RuntimeException('Agencia IP: respuesta no es una lista de publicaciones.');
        }
        $months = ['enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12];
        $results = [];
        foreach ($posts as $post) {
            $url = $post['link'] ?? '';
            $date = $post['date'] ?? '';
            $html = $post['content']['rendered'] ?? '';
            if (! is_string($html) || ! is_string($url) || ! preg_match('~^https://www\.ip\.gov\.py/ip/\d{4}/\d{2}/\d{2}/[a-z0-9-]+/$~', $url) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $date)) {
                continue;
            }
            $published = CarbonImmutable::parse($date, 'America/Asuncion');
            $full = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! preg_match('/\b(?:ANDE|Administraci[oó]n Nacional de Electricidad)\b/iu', $full)) {
                continue;
            }
            preg_match_all('~<p\b[^>]*>(.*?)</p>~isu', $html, $paragraphs);
            foreach ($paragraphs[1] as $paragraph) {
                $text = preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                // A single month and both quantities in one sentence; no accumulation, forecast or interannual comparison.
                $pattern = '/\b(?:demanda|consumo)\s+(?:de\s+)?(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)\b(?:(?!\.(?:\s|$))[^!?]){0,100}?\b(?:alcanzando|alcanz[oó])\s+(?:un\s+total\s+de\s+|los?\s+)?([\d.]+)\s+MWh\s*,\s*volumen\s+cubierto\s+por(?:(?!\.(?:\s|$))[^!?]){0,300}?\bAcaray\s*,?\s*(?:con\s+)?([\d.]+)\s+MWh/iu';
                if (! preg_match($pattern, $text, $m) || preg_match('/\b(?:acumulad[oa]|previst[oa]|proyecci[oó]n)\b/iu', $text)) {
                    continue;
                }
                if (! preg_match('/^\d{1,3}(?:\.\d{3})+$/', $m[2]) || ! preg_match('/^\d{1,3}(?:\.\d{3})+$/', $m[3])) {
                    continue;
                }
                $period = $published->startOfMonth()->subMonth();
                if ($period->month !== $months[mb_strtolower($m[1])]) {
                    continue;
                }
                preg_match_all('/\b20\d{2}\b/', $m[0], $years);
                if (array_filter($years[0], fn ($year) => (int) $year !== $period->year)) {
                    continue;
                }
                $values = [(float) str_replace('.', '', $m[2]), (float) str_replace('.', '', $m[3])];
                if ($values[0] < 100000 || $values[0] > 10000000 || $values[1] < 0 || $values[1] > 200000) {
                    continue;
                }
                $key = $period->format('Y-m');
                $item = ['period' => $period, 'values' => $values, 'url' => $url, 'date' => $published->format('Y-m-d'), 'post_id' => $post['id'] ?? null];
                if (isset($results[$key]) && $results[$key]['values'] !== $values) {
                    throw new RuntimeException('Agencia IP: publicaciones contradictorias para '.$key);
                }
                $results[$key] = $item;
            }
        }
        if (! $results) {
            throw new RuntimeException('Agencia IP: no se encontró un informe mensual inequívoco atribuido a ANDE.');
        }
        krsort($results);
        $chosen = reset($results);
        $period = $chosen['period'];
        $rows = [];
        foreach ([['PY_CONSUMPTION_ENERGY', 'PY_SIN'], ['ENERGY_TO_PY', 'ACARAY']] as $i => [$metric, $asset]) {
            $rows[] = ['metric'=>$metric, 'asset'=>$asset, 'value'=>$chosen['values'][$i], 'unit'=>'MWh', 'grain'=>'month', 'period_start'=>$period->format('Y-m-d 00:00:00'), 'period_end'=>$period->endOfMonth()->format('Y-m-d 00:00:00'), 'measured_at'=>null, 'source_url'=>$chosen['url'], 'quality'=>'OFFICIAL_AGGREGATE', 'confidence'=>0.85, 'provenance'=>['publication_date'=>$chosen['date'], 'post_id'=>$chosen['post_id'], 'attribution'=>'Agencia IP informa datos de ANDE; fuente secundaria oficial', 'year_rule'=>'Mes calendario anterior a la publicación; se rechazan otros períodos.', 'definition'=>$i===0?'Consumo nacional mensual':'Aporte mensual de Acaray a Paraguay']];
        }
        return $rows;
    }
}
