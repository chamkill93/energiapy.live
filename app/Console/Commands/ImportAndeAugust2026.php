<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportAndeAugust2026 extends Command
{
    protected $signature = 'energy:import-ande-august-2026';

    protected $description = 'Importa referencias mensuales de ANDE desde la captura oficial conservada';

    public function handle(): int
    {
        $path = storage_path('app/energiapy/ande-2026-08-excerpt.txt');
        if (! is_file($path)) {
            throw new RuntimeException('Falta el extracto consultado de ANDE.');
        }
        $html = file_get_contents($path);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        if (! str_contains($text, 'agosto de 2026') || ! str_contains($text, '2.605.369 MWh') || ! str_contains($text, '2.244.268 MWh')
            || ! str_contains($text, '22.090.238 MWh') || ! str_contains($text, '18.730.630 MWh')
            || ! str_contains($text, '2.400.558 MWh de Itaipú') || ! str_contains($text, '153.325 MWh de Yacyretá')
            || ! str_contains($text, '58.485 MWh de Acaray') || ! str_contains($text, 'aproximadamente 31 MWh') || ! str_contains($text, 'acumulando 236 MWh')) {
            throw new RuntimeException('La captura ANDE no coincide con las cifras o el período esperados.');
        }
        $checksum = hash_file('sha256', $path);
        $url = 'https://www.ande.gov.py/interna.php?id=15615';
        $rows = [
            ['PY_CONSUMPTION_ENERGY', 'PY_SIN', 2605369, 'month', '2026-08-01', '2026-08-31', 'Consumo nacional de agosto de 2026', false],
            ['ENERGY_TO_PY', 'ACARAY', 58485, 'month', '2026-08-01', '2026-08-31', 'Aporte de Acaray a ANDE', false],
            ['ENERGY_TO_PY', 'ITAIPU', 2400558, 'month', '2026-08-01', '2026-08-31', 'Aporte de Itaipú a ANDE', false],
            ['ENERGY_TO_PY', 'YACYRETA', 153325, 'month', '2026-08-01', '2026-08-31', 'Aporte de Yacyretá a ANDE', false],
            ['ENERGY_TO_PY', 'SOLAR_PTO_ESPERANZA', 31, 'month', '2026-08-01', '2026-08-31', 'Aporte aproximado de Puerto Esperanza a ANDE', true],
            ['PY_CONSUMPTION_ENERGY', 'PY_SIN', 2244268, 'month', '2025-08-01', '2025-08-31', 'Consumo nacional de agosto de 2025 publicado como comparación', false],
            ['PY_CONSUMPTION_ENERGY', 'PY_SIN', 22090238, 'YTD', '2026-01-01', '2026-08-31', 'Consumo nacional acumulado de enero a agosto de 2026', false],
            ['PY_CONSUMPTION_ENERGY', 'PY_SIN', 18730630, 'YTD', '2025-01-01', '2025-08-31', 'Consumo nacional acumulado de enero a agosto de 2025 publicado como comparación', false],
            ['ENERGY_TO_PY', 'SOLAR_PTO_ESPERANZA', 236, 'YTD', '2026-01-01', '2026-08-31', 'Aporte acumulado de Puerto Esperanza a ANDE', false],
        ];
        DB::transaction(function () use ($rows, $checksum, $url) {
            foreach ($rows as [$metric, $asset, $value, $grain, $start, $end, $definition, $approximate]) {
                $legacy = $start === '2026-08-01' && in_array($asset, ['PY_SIN', 'ACARAY'], true);
                $identity = $legacy ? 'ANDE-2026-08|'.$metric.'|'.$asset.'|'.$checksum : 'ANDE-2026-08|'.$metric.'|'.$asset.'|'.$grain.'|'.$start.'|'.$checksum;
                DB::table('observations')->updateOrInsert(['identity' => hash('sha256', $identity)], [
                    'variable_code' => $metric, 'asset_code' => $asset, 'value' => $value, 'unit' => 'MWh', 'grain' => $grain,
                    'period_start' => $start.' 00:00:00', 'period_end' => $end.' 00:00:00', 'measured_at' => null,
                    'published_at' => null, 'collected_at' => now('UTC'), 'source_code' => 'ANDE', 'source_url' => $url,
                    'quality' => $approximate ? 'OFFICIAL_APPROXIMATE' : 'OFFICIAL_AGGREGATE', 'authority' => 'OFFICIAL_PRIMARY', 'priority' => 150,
                    'parser_confidence' => 1, 'parser_version' => 'ande-august-reference-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                    'provenance' => json_encode(['reference_excerpt_path' => 'app/energiapy/ande-2026-08-excerpt.txt', 'publication_date' => '2026-09-25', 'period_basis' => $start.' al '.$end, 'definition' => $definition, 'approximate' => $approximate, 'notice' => 'Extracto consultado en web, no HTML original; valores publicados por separado, no se infiere balance entre ellos.'], JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
        Cache::forget('energy-live');
        $this->info('Nueve referencias oficiales mensuales y acumuladas importadas.');
        return self::SUCCESS;
    }
}
