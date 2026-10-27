<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportV5 extends Command
{
    protected $signature = 'energy:import-v5';

    protected $description = 'Importación idempotente de todos los CSV V5, conservando originales y alternativas';

    public function handle(): int
    {
        $total = 0;
        foreach (glob(storage_path('app/energiapy/base_v5/*.csv')) as $file) {
            $dataset = basename($file, '.csv');
            $checksum = hash_file('sha256', $file);
            $h = fopen($file, 'r');
            $keys = fgetcsv($h, 0, ',', '"', '');
            $keys[0] = ltrim($keys[0], "\xEF\xBB\xBF");
            $line = 0;
            DB::transaction(function () use ($h, $keys, $dataset, $checksum, &$line, &$total) {
                while (($values = fgetcsv($h, 0, ',', '"', '')) !== false) {
                    $line++;
                    $payload = count($keys) === count($values) ? array_combine($keys, $values) : ['original_cells' => $values];
                    DB::table('v5_records')->updateOrInsert(['dataset' => $dataset, 'row_number' => $line], ['checksum' => $checksum, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
                    $total++;
                    if ($dataset !== 'energiapy_seed_measurements_v5' || ! isset($payload['value_normalized']) || ! is_numeric($payload['value_normalized'])) {
                        continue;
                    }
                    $r = $payload;
                    $utc = fn ($v) => $v ? CarbonImmutable::parse($v, 'UTC')->utc()->format('Y-m-d H:i:s') : null;
                    $identity = hash('sha256', 'V5|'.$r['record_id'].'|'.$checksum);
                    DB::table('observations')->insertOrIgnore(['identity' => $identity, 'variable_code' => $r['variable_code'], 'asset_code' => $r['asset_code'], 'value' => (float) $r['value_normalized'], 'unit' => $r['unit_normalized'], 'grain' => $r['grain'], 'period_start' => $utc($r['period_start']), 'period_end' => $utc($r['period_end']), 'measured_at' => null, 'published_at' => $utc($r['source_published_at']), 'collected_at' => $utc($r['collected_at']) ?? now('UTC'), 'source_code' => $r['source_code'], 'source_url' => $r['source_url'], 'quality' => $r['quality_class'], 'authority' => $r['authority_class'], 'priority' => (int) $r['revision_priority'] + (($r['canonical_flag'] ?? '') === '1' ? 100 : 0), 'parser_confidence' => 1, 'parser_version' => 'v5-import-1.0', 'raw_checksum' => $checksum, 'fetch_id' => null, 'provenance' => json_encode(['original' => $r, 'dataset' => $dataset, 'row' => $line, 'timestamp_note' => 'Naive package capture timestamps interpreted as UTC; original retained.'])]);
                }
            });
            fclose($h);
            $this->line($dataset.': '.$line.' filas');
        }$this->info($total.' registros conservados; '.DB::table('observations')->count().' observaciones.');

        return self::SUCCESS;
    }
}
