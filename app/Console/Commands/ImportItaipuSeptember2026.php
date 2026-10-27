<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportItaipuSeptember2026 extends Command
{
    protected $signature = 'energy:import-itaipu-september-2026 {--file= : Captura HTML del informe oficial de Itaipú}';

    protected $description = 'Importa seis referencias mensuales y acumuladas de Itaipú a septiembre de 2026';

    public function handle(): int
    {
        $path = $this->option('file') ?: storage_path('app/energiapy/itaipu-2026-09.html');
        if (! is_file($path)) {
            throw new RuntimeException('Falta la captura del informe oficial de Itaipú de septiembre de 2026.');
        }
        $html = file_get_contents($path);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $paragraphs = [];
        foreach ($xpath->query('//p[contains(concat(" ",normalize-space(@class)," ")," wp-block-paragraph ")]') as $paragraph) {
            $paragraphs[] = preg_replace('/\s+/u', ' ', trim(html_entity_decode($paragraph->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        $text = implode(' ', $paragraphs);
        $checks = [
            '/21\.619\s+GWh.*enero\s+a\s+setiembre/iu',
            '/generaci[oó]n\s+del\s+mes\s+de\s+setiembre\s+fue\s+de\s+7\.225\s+GWh.*3\.693\s+GWh.*50\s+Hz.*2\.342\s+GWh.*ANDE/iu',
            '/total\s+generada\s+durante\s+los\s+nueve\s+meses.*58\.370\s+GWh/iu',
            '/disponibilidad.*setiembre.*96,22%/iu',
        ];
        foreach ($checks as $check) {
            if (! preg_match($check, $text)) {
                throw new RuntimeException('El informe no coincide con las cifras, el período o las definiciones revisadas.');
            }
        }
        if (! str_contains($html, 'Publicado en') || ! str_contains($html, '07/10/2026') || ! str_contains($html, 'setiembre de 2026')) {
            throw new RuntimeException('La captura no contiene la fecha o el año esperados.');
        }
        $checksum = hash('sha256', $html);
        $url = 'https://www.itaipu.gov.py/noticias/energia/itaipu-suministro-21-619-gwh-de-energia-electrica-a-la-ande-de-enero-a-setiembre-de-2026';
        $rows = [
            ['ENERGY_TO_PY', 2342000, 'MWh', 'month', '2026-09-01', 'Energía suministrada a ANDE en septiembre; 2.342 GWh publicados × 1.000.'],
            ['ITAIPU_TOTAL_GENERATION', 7225, 'GWh', 'month', '2026-09-01', 'Generación total de septiembre.'],
            ['ITAIPU_50HZ_GENERATION', 3693, 'GWh', 'month', '2026-09-01', 'Generación del sistema de 50 Hz en septiembre.'],
            ['ITAIPU_SUPPLY_TO_PY', 21619, 'GWh', 'period', '2026-01-01', 'Energía suministrada a ANDE acumulada de enero a septiembre.'],
            ['ITAIPU_TOTAL_GENERATION', 58370, 'GWh', 'period', '2026-01-01', 'Generación total acumulada de enero a septiembre.'],
            ['ITAIPU_UNIT_AVAILABILITY', 96.22, 'percent', 'period', '2026-01-01', 'Índice acumulado de disponibilidad de unidades generadoras hasta septiembre.'],
        ];
        $collected = gmdate('Y-m-d H:i:s', filemtime($path));
        $before = DB::table('observations')->count();
        DB::transaction(function () use ($rows, $checksum, $url, $collected) {
            foreach ($rows as [$metric, $value, $unit, $grain, $start, $definition]) {
                $identity = hash('sha256', implode('|', ['ITAIPU-2026-09', $metric, $grain, $start, (string) $value]));
                DB::table('observations')->updateOrInsert(['identity'=>$identity], [
                    'variable_code'=>$metric, 'asset_code'=>'ITAIPU', 'value'=>$value, 'unit'=>$unit, 'grain'=>$grain,
                    'period_start'=>$start.' 00:00:00', 'period_end'=>'2026-09-30 00:00:00',
                    'measured_at'=>null, 'published_at'=>null, 'collected_at'=>$collected,
                    'source_code'=>'ITAIPU', 'source_url'=>$url, 'quality'=>'OFFICIAL_AGGREGATE', 'authority'=>'OFFICIAL_PRIMARY',
                    'priority'=>150, 'parser_confidence'=>1, 'parser_version'=>'itaipu-september-reference-1',
                    'raw_checksum'=>$checksum, 'fetch_id'=>null,
                    'provenance'=>json_encode(['source_file'=>'app/energiapy/itaipu-2026-09.html', 'publication_date'=>'2026-10-07', 'import_method'=>'Importación manual del HTML oficial conservado', 'source_report_code'=>'ITAIPU_MONTHLY', 'period_basis'=>$grain==='month'?'Septiembre 2026':'Enero–septiembre 2026', 'definition'=>$definition, 'original_unit'=>$unit==='percent'?'percent':'GWh', 'original_value'=>$metric==='ENERGY_TO_PY'?$value/1000:$value, 'note'=>'Valores de energía y disponibilidad publicados; no son potencia instantánea.'],JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
        Cache::forget('energy-live');
        $this->info('Seis referencias oficiales de Itaipú verificadas; '.(DB::table('observations')->count()-$before).' nuevas observaciones.');
        return self::SUCCESS;
    }
}
