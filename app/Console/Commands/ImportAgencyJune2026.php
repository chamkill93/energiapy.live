<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportAgencyJune2026 extends Command
{
    protected $signature = 'energy:import-agency-june-2026';
    protected $description = 'Importa los consumos de junio de 2025 y 2026 comunicados por Agencia IP con datos de ANDE';

    public function handle(): int
    {
        $path = storage_path('app/energiapy/agency-june-2026.json');
        $contents = file_get_contents($path);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $url = $manifest['url'];
        $values = $manifest['values_mwh'];
        if (! str_starts_with($url, 'https://www.ip.gov.py/ip/2026/07/24/') || array_keys($values) !== [2025, 2026]) {
            throw new \RuntimeException('Referencia de Agencia IP no válida.');
        }
        $checksum = hash('sha256', $contents);
        $before = DB::table('observations')->count();
        DB::transaction(function () use ($url, $values, $manifest, $checksum) {
            foreach ($values as $year => $value) {
                if (! is_int($value) || $value <= 0) {
                    throw new \RuntimeException('Consumo mensual no válido.');
                }
                DB::table('observations')->updateOrInsert(['identity' => hash('sha256', 'AGENCIA-IP-CONSUMO-JUNIO-2026|'.$year)], [
                    'variable_code' => 'PY_CONSUMPTION_ENERGY', 'asset_code' => 'PY_SIN', 'value' => $value,
                    'unit' => 'MWh', 'grain' => 'month', 'period_start' => "$year-06-01 00:00:00", 'period_end' => "$year-06-30 00:00:00",
                    'measured_at' => null, 'published_at' => $manifest['published_at'].' 00:00:00', 'collected_at' => $manifest['reviewed_at'].' 00:00:00',
                    'source_code' => 'AGENCIA_IP_ANDE', 'source_url' => $url, 'quality' => 'OFFICIAL_AGGREGATE',
                    'authority' => 'OFFICIAL_SECONDARY', 'priority' => 110, 'parser_confidence' => 1,
                    'parser_version' => 'agency-june-2026-reviewed-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                    'provenance' => json_encode([
                        'source_file' => 'app/energiapy/agency-june-2026.json', 'import_method' => 'Transcripción manual contrastada con publicación oficial de Agencia IP',
                        'attribution' => 'Agencia IP informa datos de ANDE; fuente secundaria oficial',
                        'definition' => 'Consumo nacional de junio; el artículo compara 2026 con 2025.',
                        'note' => 'La referencia 2025 revisa el dato preliminar del Viceministerio.',
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        });
        $this->info('Consumos de junio revisados; nuevas observaciones: '.(DB::table('observations')->count() - $before).'.');
        return self::SUCCESS;
    }
}
