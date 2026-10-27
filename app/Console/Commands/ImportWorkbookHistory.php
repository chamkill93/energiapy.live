<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Promotes dated numerical workbook records without changing the original V5 rows. */
class ImportWorkbookHistory extends Command
{
    protected $signature = 'energy:import-workbook-history';

    protected $description = 'Incorpora tarifas, infraestructura y transacciones fechadas del Excel maestro';

    public function handle(): int
    {
        $datasets = ['energiapy_prices_v5', 'energiapy_infrastructure_v5', 'energiapy_economic_transactions_v5'];
        $counts = [];
        DB::transaction(function () use ($datasets, &$counts) {
            foreach ($datasets as $dataset) {
                $counts[$dataset] = ['examined' => 0, 'imported' => 0, 'skipped' => 0];
                foreach (DB::table('v5_records')->where('dataset', $dataset)->orderBy('row_number')->get() as $stored) {
                    $counts[$dataset]['examined']++;
                    $p = json_decode($stored->payload, true);
                    if (! is_array($p) || ! is_numeric($p['value'] ?? null) || ! is_finite((float) $p['value'])) {
                        $counts[$dataset]['skipped']++;
                        continue;
                    }
                    if ($dataset === 'energiapy_infrastructure_v5') {
                        $year = (string) ($p['year'] ?? '');
                        $metric = $p['metric_code'] ?? '';
                        $asset = str_starts_with($metric, 'ACARAY_') ? 'ACARAY' : 'PY_GRID';
                        $start = preg_match('/^(19|20)\d{2}$/', $year) ? $year.'-01-01' : '';
                        $end = $start ? $year.'-12-31' : '';
                        $unit = $p['unit'] ?? '';
                        $grain = 'year';
                    } else {
                        $metric = $p[$dataset === 'energiapy_prices_v5' ? 'price_code' : 'transaction_code'] ?? '';
                        $asset = $p['asset_code'] ?? '';
                        $start = substr((string) ($p['period_start'] ?? ''), 0, 10);
                        $end = substr((string) ($p['period_end'] ?? ''), 0, 10);
                        $unit = $dataset === 'energiapy_prices_v5' ? ($p['unit'] ?? '') : ($p['currency'] ?? '');
                        $grain = $p['grain'] ?? '';
                    }
                    if (! $metric || ! $asset || ! $unit || ! $grain || ! $this->validDate($start) || ! $this->validDate($end) || $end < $start) {
                        $counts[$dataset]['skipped']++;
                        continue;
                    }
                    $counts[$dataset]['imported'] += DB::table('observations')->insertOrIgnore([
                        'identity' => hash('sha256', implode('|', ['V5-HISTORY', $dataset, $stored->row_number, $stored->checksum])),
                        'variable_code' => $metric, 'asset_code' => $asset, 'value' => (float) $p['value'],
                        'unit' => $unit, 'grain' => $grain, 'period_start' => $start.' 00:00:00', 'period_end' => $end.' 00:00:00',
                        'measured_at' => null, 'published_at' => null, 'collected_at' => now('UTC'),
                        'source_code' => $p['source_code'] ?? '', 'source_url' => $p['source_url'] ?? '',
                        'quality' => $p['quality_class'] ?? 'OFFICIAL_STATISTIC', 'authority' => $p['authority_class'] ?? '',
                        'priority' => is_numeric($p['revision_priority'] ?? null) ? (int) $p['revision_priority'] : 75,
                        'parser_confidence' => 1, 'parser_version' => 'v5-workbook-history-1', 'raw_checksum' => $stored->checksum,
                        'fetch_id' => null, 'provenance' => json_encode(['dataset' => $dataset, 'row' => $stored->row_number,
                            'original' => $p, 'import_note' => 'Fecha de captura corresponde a la importación local; no se supone fecha de medición.'], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }
        });
        Cache::forget('energy-live');
        foreach ($counts as $name => $count) {
            $this->line($name.': '.$count['examined'].' filas, '.$count['imported'].' nuevas, '.$count['skipped'].' sin serie fechada válida.');
        }

        return self::SUCCESS;
    }

    private function validDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }
}
