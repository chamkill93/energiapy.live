<?php

namespace App\Console\Commands;

use App\Services\GisService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncTransmissionGis extends Command
{
    protected $signature = 'energy:sync-transmission-gis {--online : Descarga una copia actual del servicio UPME} {--lines-file=} {--substations-file=}';

    protected $description = 'Sincroniza líneas y subestaciones de Paraguay desde el servicio público regional de UPME';

    private const LINE_URL = 'https://geo.upme.gov.co/server/rest/services/Capas_EnergiaElectrica_LATAM/sistema_transmision_latam_lineas/FeatureServer/0/query';

    private const SUBSTATION_URL = 'https://geo.upme.gov.co/server/rest/services/Capas_EnergiaElectrica_LATAM/sistema_transmision_latam_subestaciones/FeatureServer/1/query';

    public function handle(GisService $gis): int
    {
        try {
            $collectedAt = CarbonImmutable::now('UTC');
            $linesFile = $this->option('online') ? null : ($this->option('lines-file') ?: storage_path('app/gis/seed/upme-transmission-paraguay.geojson'));
            $substationsFile = $this->option('online') ? null : ($this->option('substations-file') ?: storage_path('app/gis/seed/upme-substations-paraguay.geojson'));
            $lines = $this->fetch(self::LINE_URL, $linesFile);
            $substations = $this->fetch(self::SUBSTATION_URL, $substationsFile);
            $records = array_merge(
                $this->normalise($lines, 'grid', 'UPME-LINE-', self::LINE_URL, $collectedAt),
                $this->normalise($substations, 'substations', 'UPME-SUB-', self::SUBSTATION_URL, $collectedAt)
            );

            foreach ($records as $record) {
                $gis->validateFeature($record['feature']);
            }

            DB::transaction(function () use ($records) {
                foreach ($records as $record) {
                    $feature = $record['feature'];
                    $p = $feature['properties'];
                    DB::table('grid_assets')->updateOrInsert(
                        ['code' => $p['code']],
                        [
                            'kind' => $record['kind'],
                            'geometry' => json_encode($feature['geometry'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'quality' => $p['quality'],
                            'source_url' => $p['source_url'],
                            'checksum' => $record['checksum'],
                            'collected_at' => CarbonImmutable::parse($p['collected_at'])->format('Y-m-d H:i:s'),
                            'properties' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]
                    );
                }
            });

            $this->info(count($lines['features']).' líneas y '.count($substations['features']).' subestaciones sincronizadas.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function fetch(string $url, ?string $localFile = null): array
    {
        $json = $localFile
            ? json_decode(file_get_contents($localFile), true, 512, JSON_THROW_ON_ERROR)
            : Http::timeout(90)->retry(2, 500)->get($url, [
                'where' => "pais='Paraguay'",
                'outFields' => '*',
                'returnGeometry' => 'true',
                'outSR' => '4326',
                'f' => 'geojson',
            ])->throw()->json();
        if (($json['type'] ?? null) !== 'FeatureCollection' || empty($json['features'])) {
            throw new \RuntimeException('El servicio cartográfico respondió sin elementos para Paraguay.');
        }

        return $json;
    }

    private function normalise(array $collection, string $kind, string $prefix, string $sourceUrl, CarbonImmutable $collectedAt): array
    {
        $checksum = hash('sha256', json_encode($collection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return array_map(function (array $feature) use ($kind, $prefix, $sourceUrl, $collectedAt, $checksum) {
            $source = $feature['properties'] ?? [];
            $id = (string) ($source['OBJECTID'] ?? $feature['id']);
            $voltage = $source['tension_kv'] ?? null;
            $properties = [
                'code' => $prefix.$id,
                'name' => $source['nombre_linea'] ?? $source['nombre'] ?? 'Infraestructura eléctrica',
                'voltage_kv' => is_numeric($voltage) ? (int) $voltage : $voltage,
                'section' => $source['tramo'] ?? null,
                'origin' => $source['origen'] ?? null,
                'destination' => $source['destino'] ?? null,
                'operator' => $source['operador'] ?? $source['propietario'] ?? null,
                'status' => $source['estado'] ?? null,
                'length_km' => $source['longitud_km'] ?? null,
                'commissioned_year' => $source['anio_operacion'] ?? null,
                'quality' => 'OFFICIAL_REGIONAL_GIS',
                'source_authority' => 'UPME Colombia · cobertura latinoamericana',
                'source_url' => preg_replace('/\/query$/', '', $sourceUrl),
                'collected_at' => $collectedAt->toIso8601String(),
                'scope_notice' => 'Geometría pública regional para Paraguay; validar con ANDE para uso operativo.',
            ];

            return ['kind' => $kind, 'checksum' => $checksum, 'feature' => ['type' => 'Feature', 'geometry' => $feature['geometry'], 'properties' => $properties]];
        }, $collection['features']);
    }
}
