<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GisService
{
    public const QUALITIES = ['OFFICIAL_GIS', 'OFFICIAL_REGIONAL_GIS', 'DIGITIZED_FROM_OFFICIAL_MAP'];

    public function validateFeature(array $f): void
    {
        $p = $f['properties'] ?? [];
        $g = $f['geometry'] ?? [];
        if (! in_array($p['quality'] ?? '', self::QUALITIES) || ! filter_var($p['source_url'] ?? '', FILTER_VALIDATE_URL) || empty($p['code']) || empty($p['collected_at'])) {
            throw new InvalidArgumentException('Cada activo necesita code, quality oficial, source_url y collected_at.');
        }
        if (! in_array($g['type'] ?? '', ['Point', 'LineString', 'MultiLineString'])) {
            throw new InvalidArgumentException('Geometría no admitida.');
        }
        $lines = match ($g['type']) {
            'Point' => [[$g['coordinates']]],'LineString' => [$g['coordinates']],default => $g['coordinates']
        };
        foreach ($lines as $line) {
            if ($g['type'] !== 'Point' && count($line) < 2) {
                throw new InvalidArgumentException('Línea sin recorrido.');
            }foreach ($line as $c) {
                if (count($c) < 2 || ! is_numeric($c[0]) || ! is_numeric($c[1]) || $c[0] < -70 || $c[0] > -45 || $c[1] < -35 || $c[1] > -10) {
                    throw new InvalidArgumentException('Coordenadas fuera del ámbito regional EPSG:4326.');
                }
            }
        }
    }

    public function collection(string $kind, ?int $voltage = null): array
    {
        $features = [];
        foreach (DB::table('grid_assets')->where('kind', $kind)->whereIn('quality', self::QUALITIES)->get() as $r) {
            $p = json_decode($r->properties, true);
            if ($voltage !== null && (int) ($p['voltage_kv'] ?? 0) !== $voltage) {
                continue;
            }
            $features[] = ['type' => 'Feature', 'geometry' => json_decode($r->geometry, true), 'properties' => $p + ['code' => $r->code, 'quality' => $r->quality, 'source_url' => $r->source_url, 'raw_checksum' => $r->checksum, 'collected_at' => $r->collected_at]];
        }

        return ['type' => 'FeatureCollection', 'features' => $features, 'meta' => ['status' => $features ? 'ok' : 'unavailable', 'notice' => $features ? 'Geometría con trazabilidad oficial.' : 'No hay geometría oficial validada para esta capa.']];
    }

    public function flows(): array
    {
        $out = [];
        $data = app(EnergyData::class);
        foreach (DB::table('flows')->get() as $f) {
            $o = DB::table('observations')->find($f->observation_id);
            $context = json_decode($f->context, true);
            $g = DB::table('grid_assets')->where('code', $context['geometry_code'] ?? '')->whereIn('quality', self::QUALITIES)->first();
            if (! $o || ! $g || $o->unit !== 'MW' || ! $o->measured_at || str_contains($o->quality, 'UNVERIFIED')) {
                continue;
            }
            $geometry = json_decode($g->geometry, true);
            if ($geometry['type'] !== 'LineString') {
                continue;
            }
            $value = $data->envelope($o);
            $out[] = $value + ['code' => $context['code'] ?? $g->code, 'mw' => $value['value'], 'direction' => $context['direction'] ?? 'forward', 'coordinates' => array_map(fn ($c) => [$c[1], $c[0]], $geometry['coordinates']), 'color' => $context['color'] ?? '#2ea3ff', 'geometry_provenance' => ['quality' => $g->quality, 'source_url' => $g->source_url, 'checksum' => $g->checksum], 'notice' => 'Representa la última medición oficial; no estima el flujo entre lecturas.'];
        }

        return $out;
    }
}
