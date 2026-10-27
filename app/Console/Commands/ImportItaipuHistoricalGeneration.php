<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportItaipuHistoricalGeneration extends Command
{
    protected $signature = 'energy:import-itaipu-historical-generation';
    protected $description = 'Completa la generación anual histórica de Itaipú con publicaciones oficiales';

    public function handle(): int
    {
        $path = storage_path('app/energiapy/itaipu-generation-2003-2019.json');
        $contents = file_get_contents($path);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $reports = $manifest['reports'] ?? [];
        if (array_column($reports, 'year') !== array_merge(range(2003, 2015), [2017, 2018, 2019])) {
            throw new RuntimeException('La secuencia de datos históricos de Itaipú no es válida.');
        }
        $checksum = hash('sha256', $contents);
        $before = DB::table('observations')->count();
        DB::transaction(function () use ($reports, $checksum, $manifest) {
            foreach ($reports as $report) {
                $year = $report['year'];
                $gwh = $report['gwh'];
                $host = parse_url($report['url'], PHP_URL_HOST);
                if (! in_array($host, ['www.itaipu.gov.py', 'itaipu.gov.br'], true) || ! is_numeric($gwh) || $gwh <= 0) {
                    throw new RuntimeException('La referencia o el valor histórico no son válidos.');
                }
                $identity = hash('sha256', 'ITAIPU-HISTORICAL-GENERATION|'.$year);
                DB::table('observations')->updateOrInsert(['identity' => $identity], [
                    'variable_code' => 'ITAIPU_GROSS_GENERATION', 'asset_code' => 'ITAIPU',
                    'value' => $gwh * 1000, 'unit' => 'MWh', 'grain' => 'year',
                    'period_start' => "$year-01-01 00:00:00", 'period_end' => "$year-12-31 00:00:00",
                    'measured_at' => null, 'published_at' => null, 'collected_at' => $manifest['reviewed_at'].' 00:00:00',
                    'source_code' => 'ITAIPU', 'source_url' => $report['url'],
                    'quality' => 'OFFICIAL_AGGREGATE_APPROXIMATE', 'authority' => 'OFFICIAL_PRIMARY',
                    'priority' => 100, 'parser_confidence' => 1, 'parser_version' => 'itaipu-historical-reviewed-1',
                    'raw_checksum' => $checksum, 'fetch_id' => null,
                    'provenance' => json_encode([
                        'source_file' => 'app/energiapy/itaipu-generation-2003-2019.json',
                        'source_page' => $report['page'], 'import_method' => 'Transcripción manual contrastada con publicación oficial',
                        'definition' => 'Generación total anual de Itaipú', 'original_value' => $gwh, 'original_unit' => 'GWh',
                        'note' => 'Cifra publicada redondeada; conversión de GWh a MWh sin añadir precisión.',
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        });
        $this->info('Referencias históricas: '.count($reports).'; nuevas observaciones: '.(DB::table('observations')->count() - $before).'.');
        return self::SUCCESS;
    }
}
