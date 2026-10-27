<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportItaipuAnnualReports extends Command
{
    protected $signature = 'energy:import-itaipu-annual-reports';
    protected $description = 'Importa referencias anuales de generación y suministro de Itaipú a ANDE';

    public function handle(): int
    {
        $path = storage_path('app/energiapy/itaipu-annual-2020-2025.json');
        $contents = file_get_contents($path);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $reports = $manifest['reports'] ?? [];
        if (count($reports) !== 6 || array_column($reports, 'year') !== range(2020, 2025)) {
            throw new RuntimeException('Los informes anuales no contienen la secuencia esperada.');
        }
        $checksum = hash('sha256', $contents);
        $collected = $manifest['reviewed_at'].' 00:00:00';
        $before = DB::table('observations')->count();
        DB::transaction(function () use ($reports, $checksum, $collected, $manifest) {
            foreach ($reports as $report) {
                $year = $report['year'];
                if (! str_starts_with($report['url'], 'https://www.itaipu.gov.py/noticias/energia/') || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $report['published_at'])) {
                    throw new RuntimeException('Referencia o fecha de publicación no válida.');
                }
                foreach (['ENERGY_TO_PY' => 'supply_gwh', 'ITAIPU_GROSS_GENERATION' => 'generation_gwh'] as $metric => $field) {
                    $gwh = $report[$field];
                    if (! is_int($gwh) || $gwh <= 0) {
                        throw new RuntimeException('Valor anual no válido.');
                    }
                    $identity = hash('sha256', implode('|', ['ITAIPU-ANNUAL-REPORT', $year, $metric]));
                    DB::table('observations')->updateOrInsert(['identity' => $identity], [
                        'variable_code' => $metric, 'asset_code' => 'ITAIPU', 'value' => $gwh * 1000,
                        'unit' => 'MWh', 'grain' => 'year', 'period_start' => "$year-01-01 00:00:00",
                        'period_end' => "$year-12-31 00:00:00", 'measured_at' => null,
                        'published_at' => $report['published_at'].' 00:00:00', 'collected_at' => $collected,
                        'source_code' => 'ITAIPU', 'source_url' => $report['url'], 'quality' => 'OFFICIAL_AGGREGATE_APPROXIMATE',
                        'authority' => 'OFFICIAL_PRIMARY', 'priority' => $metric === 'ENERGY_TO_PY' ? 196 : 100, 'parser_confidence' => 1,
                        'parser_version' => 'itaipu-annual-reviewed-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                        'provenance' => json_encode([
                            'source_file' => 'app/energiapy/itaipu-annual-2020-2025.json',
                            'import_method' => 'Transcripción manual contrastada con publicación oficial',
                            'period_basis' => "Enero–diciembre $year", 'original_value' => $gwh,
                            'original_unit' => 'GWh', 'note' => 'Cifra publicada en GWh enteros; se convierte a MWh sin añadir precisión.',
                            'definition' => $metric === 'ENERGY_TO_PY' ? 'Energía suministrada a Paraguay a través de ANDE' : 'Generación total de Itaipú',
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
            $precise = $manifest['precise_generation'];
            if ($precise['url'] !== 'https://www.itaipu.gov.py/noticias/energia/central-hidroelectrica-itaipu-registro-una-productividad-record-en-el-ano-2025' || array_keys($precise['values_mwh']) !== [2024, 2025]) {
                throw new RuntimeException('Referencia de generación precisa no válida.');
            }
            foreach ($precise['values_mwh'] as $year => $mwh) {
                if (! is_int($mwh) || $mwh <= 0) throw new RuntimeException('Generación precisa no válida.');
                DB::table('observations')->updateOrInsert(['identity' => hash('sha256', "ITAIPU-PRECISE-GENERATION|$year")], [
                    'variable_code' => 'ITAIPU_GROSS_GENERATION', 'asset_code' => 'ITAIPU', 'value' => $mwh,
                    'unit' => 'MWh', 'grain' => 'year', 'period_start' => "$year-01-01 00:00:00", 'period_end' => "$year-12-31 00:00:00",
                    'measured_at' => null, 'published_at' => null, 'collected_at' => $collected,
                    'source_code' => 'ITAIPU', 'source_url' => $precise['url'], 'quality' => 'OFFICIAL_AGGREGATE',
                    'authority' => 'OFFICIAL_PRIMARY', 'priority' => 200, 'parser_confidence' => 1,
                    'parser_version' => 'itaipu-annual-reviewed-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                    'provenance' => json_encode(['source_file' => 'app/energiapy/itaipu-annual-2020-2025.json', 'import_method' => 'Transcripción manual contrastada con publicación oficial', 'definition' => 'Generación total anual de Itaipú', 'original_value' => $mwh, 'original_unit' => 'MWh'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        });
        Cache::forget('energy-live');
        $this->info('Informes anuales verificados: '.count($reports).'; nuevas observaciones: '.(DB::table('observations')->count() - $before).'.');
        return self::SUCCESS;
    }
}
