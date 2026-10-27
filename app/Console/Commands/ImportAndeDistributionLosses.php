<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportAndeDistributionLosses extends Command
{
    protected $signature = 'energy:import-ande-distribution-losses';

    protected $description = 'Importa pérdidas anuales de distribución publicadas por ANDE';

    public function handle(): int
    {
        $path = storage_path('app/energiapy/ande-losses-2023-2025-excerpt.txt');
        if (! is_file($path)) {
            throw new RuntimeException('Falta el extracto consultado de ANDE.');
        }
        $text = file_get_contents($path);
        foreach (['23,4 % a 21,9 %', 'cierre del año 2024', '23,4% en 2023', '20,03% en 2025'] as $expected) {
            if (! str_contains($text, $expected)) {
                throw new RuntimeException('El extracto ANDE no contiene las cifras y períodos esperados.');
            }
        }
        $checksum = hash_file('sha256', $path);
        $rows = [[2023, 23.4, 'https://www.ande.gov.py/interna.php?id=15116', '2026-03-18'],
            [2024, 21.9, 'https://www.ande.gov.py/interna.php?id=14122', '2025-08-04'],
            [2025, 20.03, 'https://www.ande.gov.py/interna.php?id=15116', '2026-03-18']];
        DB::transaction(function () use ($rows, $checksum) {
            foreach ($rows as [$year, $value, $url, $published]) {
                DB::table('observations')->updateOrInsert(['identity' => hash('sha256', 'ANDE-DISTRIBUTION-LOSS|'.$year.'|'.$checksum)], [
                    'variable_code' => 'DISTRIBUTION_LOSS_RATE', 'asset_code' => 'PY_GRID', 'value' => $value, 'unit' => 'percent', 'grain' => 'year',
                    'period_start' => $year.'-01-01 00:00:00', 'period_end' => $year.'-12-31 00:00:00', 'measured_at' => null,
                    'published_at' => $published.' 00:00:00', 'collected_at' => now('UTC'), 'source_code' => 'ANDE', 'source_url' => $url,
                    'quality' => 'OFFICIAL_RELEASE', 'authority' => 'OFFICIAL_PRIMARY', 'priority' => 150, 'parser_confidence' => 1,
                    'parser_version' => 'ande-distribution-loss-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                    'provenance' => json_encode(['reference_excerpt_path' => 'app/energiapy/ande-losses-2023-2025-excerpt.txt',
                        'definition' => 'Pérdidas técnicas y comerciales en distribución; porcentaje publicado por ANDE.',
                        'rounding' => $year < 2025 ? 'Publicado con un decimal.' : 'Publicado con dos decimales.',
                        'notice' => 'Extracto consultado en web; no es el HTML original.'], JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
        $this->info('Tres referencias anuales oficiales de pérdidas de distribución importadas.');

        return self::SUCCESS;
    }
}
