<?php

namespace App\Console\Commands;

use App\Services\SourceCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CollectEnergy extends Command
{
    protected $signature = 'energy:collect {source?} {--file=} {--force}';

    protected $description = 'Recoge fuentes verificadas, preserva raw y último dato bueno';

    public function handle(SourceCollector $collector): int
    {
        $failed = false;
        foreach ($this->argument('source') ? [$this->argument('source')] : SourceCollector::ENABLED as $code) {
            $h = DB::table('source_health')->where('source_code', $code)->first();
            $cadence = config('energy.cadences.'.$code, 86400);
            $backoff = min(86400, $cadence * pow(2, min(4, $h->failures ?? 0)));
            if (! $this->option('force') && $h?->last_attempt && strtotime($h->last_attempt.' UTC') + $backoff > time()) {
                $this->line($code.': intervalo de captura aún no cumplido');

                continue;
            }$lock = Cache::lock('collect-'.$code, 600);
            if (! $lock->get()) {
                continue;
            }try {
                $r = $collector->run($code, $this->option('file'));
                $this->line(json_encode($r, JSON_UNESCAPED_UNICODE));
                $failed = $failed || $r['status'] === 'error';
            } finally {
                $lock->release();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
