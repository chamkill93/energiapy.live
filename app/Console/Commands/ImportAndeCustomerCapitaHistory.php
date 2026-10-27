<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportAndeCustomerCapitaHistory extends Command
{
    protected $signature = 'energy:import-ande-customer-capita-history';
    protected $description = 'Importa clientes y energía facturada por habitante publicados por ANDE para 2003–2023';

    public function handle(): int
    {
        $path = storage_path('app/energiapy/ande-customer-capita-2003-2023.json');
        $contents = file_get_contents($path);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $reports = $manifest['reports'] ?? [];
        $url = 'https://www.ande.gov.py/finanzas/2.%20COMPILACION%20ESTADISTICA%202003-2023/ANDE%20-%20Compilacion%20Estadistica%202003-2023.pdf';
        if (($manifest['source_url'] ?? '') !== $url || array_column($reports, 'year') !== range(2003, 2023)) {
            throw new RuntimeException('La fuente o la secuencia histórica de ANDE no es válida.');
        }
        $checksum = hash('sha256', $contents);
        $before = DB::table('observations')->count();
        DB::transaction(function () use ($reports, $manifest, $url, $checksum) {
            foreach ($reports as $report) {
                $year = $report['year'];
                foreach ([
                    ['ANDE_BILLED_CUSTOMERS_NATIONAL', 'customers', 'count'],
                    ['ANDE_BILLED_KWH_PER_CAPITA', 'billed_kwh_per_capita', 'kWh/person/year'],
                ] as [$metric, $key, $unit]) {
                    $value = $report[$key] ?? null;
                    if (! is_int($value) || $value <= 0) throw new RuntimeException('Valor histórico ANDE no válido.');
                    $isCustomer = $key === 'customers';
                    $note = $isCustomer
                        ? 'Hasta 2016: clientes facturados al 31 de diciembre; desde 2017: promedio anual. En 2023 la transición a facturación electrónica redujo los registros de diciembre.'
                        : 'Energía facturada nacional dividida por población; desde 2022 cambia la base poblacional. La estimación 2022–2023 excluye Boquerón, Alto Paraguay, comunidades indígenas y viviendas colectivas.';
                    DB::table('observations')->updateOrInsert(['identity' => hash('sha256', "ANDE-CUSTOMERS-CAPITA|$metric|$year")], [
                        'variable_code' => $metric, 'asset_code' => 'PY_SIN', 'value' => $value, 'unit' => $unit,
                        'grain' => 'year', 'period_start' => "$year-01-01 00:00:00", 'period_end' => "$year-12-31 00:00:00",
                        'measured_at' => null, 'published_at' => null, 'collected_at' => $manifest['reviewed_at'].' 00:00:00',
                        'source_code' => 'ANDE', 'source_url' => $url, 'quality' => $isCustomer ? 'OFFICIAL_AGGREGATE' : 'OFFICIAL_AGGREGATE_APPROXIMATE',
                        'authority' => 'OFFICIAL_PRIMARY', 'priority' => 140, 'parser_confidence' => 1,
                        'parser_version' => 'ande-customer-capita-reviewed-1', 'raw_checksum' => $checksum, 'fetch_id' => null,
                        'provenance' => json_encode([
                            'source_file' => 'app/energiapy/ande-customer-capita-2003-2023.json',
                            'source_page' => $isCustomer ? 36 : 42, 'import_method' => 'Transcripción manual contrastada con tabla oficial',
                            'definition' => $isCustomer ? 'Clientes facturados del mercado nacional' : 'Energía facturada del mercado nacional por habitante',
                            'methodology_break_year' => $isCustomer ? 2017 : 2022, 'note' => $note,
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        });
        $this->info('Referencias anuales ANDE: '.count($reports).'; nuevas observaciones: '.(DB::table('observations')->count() - $before).'.');
        return self::SUCCESS;
    }
}
