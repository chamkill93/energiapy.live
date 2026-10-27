<?php

namespace App\Console\Commands;

use App\Services\EnergyData;
use App\Services\SourceCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SourceHealthCheck extends Command
{
    protected $signature = 'energy:health-check';

    protected $description = 'Comprueba accesibilidad HTTP, sin atribuir éxito a parsers no implementados';

    public function handle(EnergyData $data): int
    {
        $rows = [];
        foreach ($data->registry() as $source) {
            $url = $source['url'] ?? $source['base'] ?? null;
            if (! $url) {
                continue;
            }try {
                $response = Http::withUserAgent(config('energy.user_agent'))->connectTimeout(8)->timeout(15)->head($url);
                $row = ['source' => $source['code'], 'url' => $url, 'checked_at' => now('UTC')->toIso8601String(), 'http_status' => $response->status(), 'accessible' => $response->successful(), 'parser_validated' => in_array($source['code'], SourceCollector::ENABLED)];
            } catch (\Throwable $e) {
                $row = ['source' => $source['code'], 'url' => $url, 'checked_at' => now('UTC')->toIso8601String(), 'http_status' => null, 'accessible' => false, 'message' => $e->getMessage()];
            }$rows[] = $row;
            $this->line(json_encode($row, JSON_UNESCAPED_UNICODE));
        }file_put_contents(storage_path('app/energiapy/endpoint-health.json'), json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
