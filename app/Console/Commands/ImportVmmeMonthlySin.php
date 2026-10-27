<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportVmmeMonthlySin extends Command
{
    protected $signature = 'energy:import-vmme-monthly-sin';
    protected $description = 'Importa consumo mensual preliminar del SIN para 2024 y 2025';

    public function handle(): int
    {
        $path = storage_path('app/energiapy/vmme-monthly-sin-2024-2025.json');
        $contents = file_get_contents($path);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $reports = $manifest['reports'] ?? [];
        if (array_column($reports, 'month') !== range(1, 12)) {
            throw new RuntimeException('Faltan informes mensuales o los meses no son únicos.');
        }
        $checksum = hash('sha256', $contents);
        $before = DB::table('observations')->count();
        DB::transaction(function () use ($reports, $manifest, $checksum) {
            foreach ($reports as $report) {
                if (! str_starts_with($report['url'], 'https://minasyenergia.mopc.gov.py/pdf/electrico/2025/')) {
                    throw new RuntimeException('La fuente no pertenece a los informes oficiales esperados.');
                }
                foreach ([2024, 2025] as $year) {
                    $value = $report[(string) $year];
                    if (! is_int($value) || $value <= 0) {
                        throw new RuntimeException('Consumo mensual no válido.');
                    }
                    $start = sprintf('%04d-%02d-01', $year, $report['month']);
                    $end = date('Y-m-t', strtotime($start));
                    $identity = hash('sha256', 'VMME-SIN-MONTHLY|'.$start);
                    DB::table('observations')->updateOrInsert(['identity' => $identity], [
                        'variable_code' => 'PY_CONSUMPTION_ENERGY', 'asset_code' => 'PY_SIN', 'value' => $value,
                        'unit' => 'MWh', 'grain' => 'month', 'period_start' => $start.' 00:00:00', 'period_end' => $end.' 00:00:00',
                        'measured_at' => null, 'published_at' => null, 'collected_at' => $manifest['reviewed_at'].' 00:00:00',
                        'source_code' => 'VMME', 'source_url' => $report['url'], 'quality' => 'OFFICIAL_PRELIMINARY',
                        'authority' => 'OFFICIAL_PRIMARY', 'priority' => 90, 'parser_confidence' => 1,
                        'parser_version' => 'vmme-sin-monthly-reviewed-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                        'provenance' => json_encode([
                            'source_file' => 'app/energiapy/vmme-monthly-sin-2024-2025.json',
                            'import_method' => 'Transcripción manual contrastada con los informes mensuales oficiales',
                            'definition' => 'Consumo del Sistema Interconectado Nacional (SIN), sin exportación ANDE; no incluye el SI total.',
                            'note' => 'Cifras preliminares de partes de operación de ANDE; pueden diferir de balances definitivos.',
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        });
        $this->info('Informes mensuales revisados: '.count($reports).'; nuevas observaciones: '.(DB::table('observations')->count() - $before).'.');
        return self::SUCCESS;
    }
}
