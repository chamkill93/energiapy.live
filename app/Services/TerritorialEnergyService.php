<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TerritorialEnergyService
{
    private const REGIONS = [
        'METROPOLITANO' => ['ASUNCIÓN', 'CENTRAL', 'PRESIDENTE HAYES', 'CORDILLERA', 'PARAGUARÍ'],
        'ESTE' => ['ALTO PARANÁ', 'CANINDEYÚ'],
        'CENTRAL' => ['CAAGUAZÚ', 'CAAZAPÁ', 'SAN PEDRO', 'GUAIRÁ'],
        'SUR' => ['MISIONES', 'ÑEEMBUCÚ', 'ITAPÚA'],
        'NORTE' => ['AMAMBAY', 'CONCEPCIÓN'],
        'OESTE' => ['ALTO PARAGUAY', 'BOQUERÓN'],
    ];

    public function collection(): array
    {
        $path = storage_path('app/gis/web/departments.geojson');
        if (! is_file($path)) {
            return ['type' => 'FeatureCollection', 'features' => [], 'meta' => ['status' => 'unavailable', 'notice' => 'No está disponible la cartografía departamental.']];
        }
        $map = json_decode(file_get_contents($path), true);
        $latestYear = DB::table('observations')->where('variable_code', 'PY_REGION_ENERGY')->max('period_start');
        $values = DB::table('observations')->where('variable_code', 'PY_REGION_ENERGY')->where('period_start', $latestYear)->get()->keyBy(fn ($row) => str_replace('PY_REGION_', '', $row->asset_code));
        $features = [];
        foreach ($map['features'] as $feature) {
            $department = $feature['properties']['DPTO_DESC'] ?? '';
            $region = collect(self::REGIONS)->search(fn ($departments) => in_array($department, $departments, true));
            $observation = $region ? $values->get($region) : null;
            if (! $observation) {
                continue;
            }
            $feature['properties'] = [
                'name' => $department,
                'region' => ucfirst(strtolower($region)),
                'value_mwh' => (float) $observation->value,
                'unit' => $observation->unit,
                'period_year' => substr($observation->period_start, 0, 4),
                'source_url' => $observation->source_url,
                'quality' => $observation->quality,
                'scope_notice' => 'El valor corresponde al total del sistema regional, no al departamento individual.',
            ];
            $features[] = $feature;
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'meta' => [
                'status' => $features ? 'historical_reference' : 'unavailable',
                'period_year' => $latestYear ? substr($latestYear, 0, 4) : null,
                'notice' => $features ? 'Referencia anual ANDE por sistema regional; no representa consumo en vivo.' : 'No hay consumo territorial publicado para vincular con la cartografía.',
                'region_source_url' => 'https://www.ande.gov.py/documentos/plan_maestro/RP49741%20ANEXO%20III%20%20Plan%20Maestro%20de%20Distribucion%202024-2033.pdf',
            ],
        ];
    }
}
