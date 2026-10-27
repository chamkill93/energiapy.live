<?php

namespace App\Console\Commands;

use App\Services\GisService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportGis extends Command
{
    protected $signature = 'energy:import-gis {file} {--kind=grid}';

    protected $description = 'Importa activos GeoJSON con trazabilidad oficial; no genera geometrías';

    public function handle(GisService $gis): int
    {
        $kind = $this->option('kind');
        if (! in_array($kind, ['grid', 'substations', 'plants'])) {
            $this->error('Tipo inválido.');

            return self::FAILURE;
        }
        try {
            $raw = file_get_contents($this->argument('file'));
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (($json['type'] ?? null) !== 'FeatureCollection' || empty($json['features'])) {
                throw new \InvalidArgumentException('FeatureCollection vacía.');
            }
            foreach ($json['features'] as $f) {
                $gis->validateFeature($f);
            }
            $hash = hash('sha256', $raw);
            $path = storage_path('app/gis/raw/'.$hash.'.geojson');
            file_put_contents($path, $raw);
            DB::transaction(function () use ($json, $kind, $hash) {
                foreach ($json['features'] as $f) {
                    $p = $f['properties'];
                    DB::table('grid_assets')->updateOrInsert(['code' => $p['code']], ['kind' => $kind, 'geometry' => json_encode($f['geometry']), 'quality' => $p['quality'], 'source_url' => $p['source_url'], 'checksum' => $hash, 'collected_at' => CarbonImmutable::parse($p['collected_at'])->utc()->format('Y-m-d H:i:s'), 'properties' => json_encode($p)]);
                }
            });
            $this->info(count($json['features']).' activos importados.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
