<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportAndeBalance2024 extends Command
{
    protected $signature = 'energy:import-ande-balance-2024';
    protected $description = 'Importa el origen de la energía bruta ANDE en 2024';

    public function handle(): int
    {
        $path = storage_path('app/energiapy/ande-balance-2024.json');
        $contents = file_get_contents($path);
        $reference = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (($reference['year'] ?? null) !== 2024 || ($reference['unit'] ?? null) !== 'MWh'
            || ($reference['source_url'] ?? '') !== 'https://www.ande.gov.py/finanzas/ANDE%20-%20Resumen%20Estad%C3%ADstico%202020%20-%202024.pdf'
            || ! is_int($reference['own_generation'] ?? null) || ! is_int($reference['binational_energy'] ?? null)
            || $reference['own_generation'] <= 0 || $reference['binational_energy'] <= 0
            || $reference['own_generation'] + $reference['binational_energy'] !== ($reference['total'] ?? null)) {
            throw new RuntimeException('Los valores de balance de ANDE no coinciden con el cuadro revisado.');
        }
        $checksum = hash('sha256', $contents);
        $before = DB::table('observations')->count();
        DB::transaction(function () use ($reference, $checksum) {
            foreach (['PY_GROSS_OWN_GENERATION' => 'own_generation', 'PY_PURCHASED_ENERGY' => 'binational_energy'] as $metric => $field) {
                DB::table('observations')->updateOrInsert(['identity' => hash('sha256', "ANDE-BALANCE-2024|$metric")], [
                    'variable_code' => $metric, 'asset_code' => 'PY_SIN', 'value' => $reference[$field],
                    'unit' => 'MWh', 'grain' => 'year', 'period_start' => '2024-01-01 00:00:00',
                    'period_end' => '2024-12-31 00:00:00', 'measured_at' => null, 'published_at' => null,
                    'collected_at' => $reference['reviewed_at'].' 00:00:00', 'source_code' => 'ANDE',
                    'source_url' => $reference['source_url'], 'quality' => 'OFFICIAL_AGGREGATE',
                    'authority' => 'OFFICIAL_PRIMARY', 'priority' => 150, 'parser_confidence' => 1,
                    'parser_version' => 'ande-balance-2024-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                    'provenance' => json_encode([
                        'source_file' => 'app/energiapy/ande-balance-2024.json',
                        'import_method' => 'Transcripción manual del cuadro 1.1 del resumen estadístico ANDE',
                        'definition' => $metric === 'PY_GROSS_OWN_GENERATION' ? 'Generación bruta propia de ANDE: Acaray y térmicas' : 'Energía comprada por ANDE a las binacionales Itaipú y Yacyretá',
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        });
        Cache::forget('energy-live');
        $this->info('Balance ANDE 2024: '.(DB::table('observations')->count() - $before).' observaciones nuevas.');
        return self::SUCCESS;
    }
}
