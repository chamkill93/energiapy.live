<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportAndeConsumptionOrigin2025 extends Command
{
    protected $signature = 'energy:import-ande-consumption-origin-2025';
    protected $description = 'Incorpora la composición anual del consumo nacional publicada por ANDE para 2025';

    public function handle(): int
    {
        $path = storage_path('app/energiapy/ande-consumo-origen-2025.json');
        $contents = file_get_contents($path);
        $source = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $values = $source['sources'] ?? [];
        if (($source['year'] ?? null) !== 2025 || ($source['unit'] ?? null) !== 'GWh'
            || ($source['source_url'] ?? '') !== 'https://www.ande.gov.py/interna.php?id=14877'
            || array_keys($values) !== ['ITAIPU', 'YACYRETA', 'ACARAY']
            || array_sum($values) !== ($source['published_total'] ?? null)
            || count(array_filter($values, fn ($v) => is_int($v) && $v > 0)) !== 3) {
            throw new RuntimeException('La referencia ANDE de 2025 no tiene los datos esperados.');
        }
        $checksum = hash('sha256', $contents);
        $before = DB::table('observations')->count();
        DB::transaction(function () use ($source, $values, $checksum) {
            foreach ($values as $asset => $gwh) {
                DB::table('observations')->updateOrInsert([
                    'identity' => hash('sha256', "ANDE-CONSUMPTION-ORIGIN-2025|$asset"),
                ], [
                    'variable_code' => 'ENERGY_TO_PY', 'asset_code' => $asset, 'value' => $gwh * 1000,
                    'unit' => 'MWh', 'grain' => 'year', 'period_start' => '2025-01-01 00:00:00',
                    'period_end' => '2025-12-31 00:00:00', 'measured_at' => null,
                    'published_at' => $source['published_at'].' 00:00:00', 'collected_at' => $source['reviewed_at'].' 00:00:00',
                    'source_code' => 'ANDE', 'source_url' => $source['source_url'],
                    'quality' => 'OFFICIAL_AGGREGATE_APPROXIMATE', 'authority' => 'OFFICIAL_PRIMARY',
                    'priority' => 150, 'parser_confidence' => 1, 'parser_version' => 'ande-consumption-origin-2025-1',
                    'raw_checksum' => $checksum, 'fetch_id' => null,
                    'provenance' => json_encode([
                        'source_file' => 'app/energiapy/ande-consumo-origen-2025.json',
                        'import_method' => 'Transcripción manual de publicación oficial', 'original_value' => $gwh,
                        'original_unit' => 'GWh', 'definition' => 'Fuente que abasteció el consumo nacional de 2025 según ANDE',
                        'note' => 'Cifra publicada en GWh enteros; conversión a MWh sin añadir precisión.',
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        });
        Cache::forget('energy-live');
        $this->info('Composición anual ANDE 2025: '.(DB::table('observations')->count() - $before).' observaciones nuevas.');
        return self::SUCCESS;
    }
}
