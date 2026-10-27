<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Promotes context from the workbook with explicit dates, definitions and quality. */
class ImportWorkbookContext extends Command
{
    protected $signature = 'energy:import-workbook-context';

    protected $description = 'Incorpora referencias fechadas de hogares, clientes, calidad y macroeconomía del Excel';

    public function handle(): int
    {
        $datasets = ['energiapy_current_baselines_v5', 'energiapy_household_context_v5', 'energiapy_grid_quality_current_v5', 'energiapy_ande_customers_2025_v5', 'energiapy_macro_annual_v5'];
        $counts = [];
        DB::transaction(function () use ($datasets, &$counts) {
            foreach ($datasets as $dataset) {
                $counts[$dataset] = ['rows' => 0, 'inserted' => 0, 'skipped' => 0];
                foreach (DB::table('v5_records')->where('dataset', $dataset)->orderBy('row_number')->get() as $raw) {
                    $counts[$dataset]['rows']++;
                    $p = json_decode($raw->payload, true);
                    if (! is_array($p)) {
                        $counts[$dataset]['skipped']++;
                        continue;
                    }
                    $entries = $this->entries($dataset, $p);
                    if (! $entries) {
                        $counts[$dataset]['skipped']++;
                    }
                    foreach ($entries as $entry) {
                        [$metric, $asset, $value, $unit, $grain, $start, $end, $source, $url, $quality, $authority, $calculation] = $entry;
                        if (! $metric || ! $asset || ! $unit || ! $grain || ! is_numeric($value) || ! is_finite((float) $value) || ! $this->validDate($start) || ! $this->validDate($end) || $end < $start) {
                            $counts[$dataset]['skipped']++;
                            continue;
                        }
                        $counts[$dataset]['inserted'] += DB::table('observations')->insertOrIgnore([
                            'identity' => hash('sha256', implode('|', ['V5-CONTEXT', $dataset, $raw->row_number, $metric, $raw->checksum])),
                            'variable_code' => $metric, 'asset_code' => $asset, 'value' => (float) $value, 'unit' => $unit, 'grain' => $grain,
                            'period_start' => $start.' 00:00:00', 'period_end' => $end.' 00:00:00',
                            'measured_at' => null, 'published_at' => null, 'collected_at' => now('UTC'),
                            'source_code' => $source, 'source_url' => $url, 'quality' => $quality, 'authority' => $authority,
                            'priority' => 25, 'parser_confidence' => 1, 'parser_version' => 'v5-workbook-context-1',
                            'raw_checksum' => $raw->checksum, 'fetch_id' => null,
                            'provenance' => json_encode(['dataset' => $dataset, 'row' => $raw->row_number, 'original' => $p,
                                'calculation' => $calculation, 'notice' => 'La fecha de captura corresponde a la importación local; se conserva el período y la calidad publicados.'], JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                }
            }
        });
        foreach ($counts as $dataset => $count) {
            $this->line($dataset.': '.$count['rows'].' filas, '.$count['inserted'].' observaciones nuevas, '.$count['skipped'].' filas o valores no incorporados.');
        }

        return self::SUCCESS;
    }

    private function entries(string $dataset, array $p): array
    {
        $year = (string) ($p['year'] ?? $p['Año'] ?? $p['period'] ?? '');
        $start = preg_match('/^(19|20)\d{2}$/', $year) ? $year.'-01-01' : '';
        $end = $start ? $year.'-12-31' : '';
        if ($dataset === 'energiapy_current_baselines_v5') {
            return [[$p['metric_code'] ?? '', $p['asset_code'] ?? '', $p['value'] ?? null, $p['unit'] ?? '', $p['grain'] ?? '',
                substr((string) ($p['period_start'] ?? ''), 0, 10), substr((string) ($p['period_end'] ?? ''), 0, 10),
                $p['source_code'] ?? '', $p['source_url'] ?? '', $p['quality_class'] ?? '', $p['authority_class'] ?? '', null]];
        }
        if ($dataset === 'energiapy_household_context_v5') {
            return [[$p['metric_code'] ?? '', 'PY_HOUSEHOLDS', $p['value'] ?? null, $p['unit'] ?? '', 'year', $start, $end,
                $p['source_code'] ?? '', $p['source_url'] ?? '', $p['quality_class'] ?? '', 'OFFICIAL_PRIMARY', null]];
        }
        if ($dataset === 'energiapy_grid_quality_current_v5') {
            return [[$p['metric'] ?? '', ($p['scope'] ?? '') === 'National' ? 'PY_SIN' : 'PY_GRID', $p['value'] ?? null, $p['unit'] ?? '', 'year', $start, $end,
                $p['source_code'] ?? '', $p['source_url'] ?? '', $p['quality_class'] ?? '', 'OFFICIAL_PRIMARY', null]];
        }
        if ($dataset === 'energiapy_ande_customers_2025_v5') {
            $category = strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', trim((string) ($p['category'] ?? ''))));
            $asset = $category ? 'ANDE_CUSTOMER_'.$category : '';
            return [
                ['ANDE_CUSTOMER_BILLED_ENERGY', $asset, $p['billed_kwh'] ?? null, 'kWh', 'year', $start, $end, 'ANDE', $p['source_url'] ?? '', $p['quality_class'] ?? '', 'OFFICIAL_PRIMARY', null],
                ['ANDE_CUSTOMER_COUNT', $asset, $p['customers'] ?? null, 'count', 'year', $start, $end, 'ANDE', $p['source_url'] ?? '', $p['quality_class'] ?? '', 'OFFICIAL_PRIMARY', null],
            ];
        }
        if ($dataset === 'energiapy_macro_annual_v5') {
            $inflation = $p['Inflación anual'] ?? null;
            return [
                ['CPI_INDEX_WDI', 'PY_ECONOMY', $p['IPC (2010=100)'] ?? null, 'index_2010_100', 'year', $start, $end, 'WDI_IMF', $p['URL IPC'] ?? '', 'PROVISIONAL', 'OFFICIAL_SECONDARY', null],
                ['CPI_ANNUAL_INFLATION_WDI', 'PY_ECONOMY', is_numeric($inflation) ? (float) $inflation * 100 : null, 'percent', 'year', $start, $end, 'WDI_IMF', $p['URL IPC'] ?? '', 'PROVISIONAL', 'OFFICIAL_SECONDARY', 'Fracción original × 100.'],
                ['FX_ANNUAL_PYG_USD_WDI', 'PY_ECONOMY', $p['USD/PYG promedio'] ?? null, 'PYG/USD', 'year', $start, $end, 'WDI_IMF', $p['URL FX'] ?? '', 'PROVISIONAL', 'OFFICIAL_SECONDARY', null],
            ];
        }

        return [];
    }

    private function validDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }
}
