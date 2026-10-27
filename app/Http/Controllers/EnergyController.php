<?php

namespace App\Http\Controllers;

use App\Services\EnergyData;
use App\Services\GisService;
use App\Services\HistoryCatalog;
use App\Services\OperationalMetrics;
use App\Services\TariffHistoryService;
use App\Services\TerritorialEnergyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EnergyController extends Controller
{
    public function read(Request $r, EnergyData $d)
    {
        $path = $r->path();
        $r->validate(['metric' => 'nullable|string|max:100', 'asset' => 'nullable|string|max:100', 'unit' => 'nullable|string|max:100', 'dataset' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_]+$/', 'q' => 'nullable|string|max:200', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'grain' => ['nullable', 'string', Rule::in(array_unique(array_merge(['raw', 'instant', 'last_hour', 'hour', 'day', 'month', 'year', 'season'], DB::table('observations')->distinct()->pluck('grain')->all())))], 'date' => 'nullable|date_format:Y-m-d', 'page' => 'nullable|integer|min:1|max:10000', 'period' => 'nullable|in:today,month,year', 'source' => 'nullable|string|max:100', 'voltage' => 'nullable|integer|in:66,220,500']);
        $result = match ($path) {
            'api/monitoring/summary' => ['hydrology' => DB::table('v5_records')->where('dataset','energiapy_hydrology_current_v5')->orderBy('row_number')->get()->map(fn ($row) => json_decode($row->payload,true))->all(), 'baselines' => DB::table('v5_records')->where('dataset','energiapy_current_baselines_v5')->orderBy('row_number')->get()->map(fn ($row) => json_decode($row->payload,true) + ['state'=>'HISTORICAL','stale'=>true,'measured_at'=>null,'parser_version'=>'v5-import-1.0','provenance'=>['record_id'=>$row->id,'raw_checksum'=>$row->checksum]])->all(), 'notice'=>'Informes históricos guardados; no son lecturas en vivo.'],
            'api/live' => Cache::remember('energy-live', 60, fn () => $d->live()),
            'api/live/plants' => ['data' => $d->plants()],
            'api/live/flows' => ['data' => app(GisService::class)->flows(), 'notice' => 'Solo flujos con geometría y medición verificadas.'],
            'api/data/period' => app(OperationalMetrics::class)->period($r->query('metric', 'DEMAND_MW'), $r->query('asset', 'PY_SIN'), $r->query('period', 'today'), $r->query('date')),
            'api/citizenship/history' => ['data' => app(TariffHistoryService::class)->series()],
            'api/climate/enso' => ['data' => DB::table('observations')->where('source_code', 'NOAA_ENSO_PROB')->orderByDesc('collected_at')->get()->unique(fn ($o) => $o->asset_code.'|'.$o->variable_code)->map(fn ($o) => $d->envelope($o))->values(), 'baselines' => $d->csv('enso_outlook')],
            'api/observations/latest' => ['data' => DB::table('observations')->when($r->query('source'), fn ($q, $source) => $q->where('source_code', $source))->orderByDesc('period_start')->orderByDesc('collected_at')->get()->unique(fn ($o) => $o->asset_code.'|'.$o->variable_code)->take(250)->map(fn ($o) => $d->envelope($o))->values()],
            'api/gis/grid' => app(GisService::class)->collection('grid', $r->has('voltage') ? (int) $r->query('voltage') : null),
            'api/gis/substations' => app(GisService::class)->collection('substations'),
            'api/data/summary' => ['baselines' => $d->csv('current_baselines'), 'metrics' => DB::table('observations')->select('variable_code', 'unit', 'grain')->distinct()->get(), 'hydrology' => $d->csv('hydrology_current'), 'quality' => $d->csv('grid_quality_current'), 'climate' => $d->csv('climate_station_snapshot'), 'infrastructure' => $d->csv('infrastructure'), 'notice' => 'Base histórica V5; no son lecturas en vivo.'],
            'api/history' => $d->history($r->query()),
            'api/history/catalog' => app(HistoryCatalog::class)->catalog(),
            'api/history/replay' => $d->replay($r->query('date', now('America/Asuncion')->format('Y-m-d'))),
            'api/projections' => ['plans' => $d->csv('planning_baselines'), 'baseline' => $d->history(['metric' => 'PY_CONSUMPTION_ENERGY', 'grain' => 'year'])['data'], 'model_version' => 'scenario-1.0', 'notice' => 'Simulación exploratoria con tasas elegidas por el usuario; no es un pronóstico oficial.'],
            'api/citizenship/tariffs' => ['bands' => $d->csv('retail_tariff_bands'), 'tariffs' => $d->csv('ande_retail_tariff_current'), 'household' => $d->csv('household_context'), 'history' => $d->csv('ande_retail_tariff_history'), 'notice' => 'Base V5, pliego fechado 27/11/2024. Verificar vigencia; estimación sin impuestos ni otros conceptos.'],
            'api/economics/summary' => ['terms' => $d->csv('economic_terms'), 'transactions' => $d->csv('economic_transactions'), 'macro' => $d->csv('macro_current'), 'prices' => $d->csv('prices'), 'notice' => 'Los mecanismos económicos y sus unidades se presentan por separado.'],
            'api/sources/status' => ['data' => $d->sources(), 'generated_at' => now('UTC')->toIso8601String()],
            'api/weather/current' => ['data' => DB::table('observations')->where('source_code', 'DMH_EMAS')->orderByDesc('measured_at')->get()->unique(fn ($o) => $o->asset_code.'|'.$o->variable_code)->map(fn ($o) => $d->envelope($o))->values(), 'quality' => 'OFFICIAL_ORIENTATIVE'],
            'api/hydrology/current' => ['data' => $d->csv('hydrology_current'), 'state' => 'HISTORICAL'],
            'api/data/territorial-demand' => app(TerritorialEnergyService::class)->collection(),
            default => $d->gis(basename($path)),
        };

        if ($path === 'api/live') {
            // The cached payload may straddle an hour boundary; always calculate the
            // model for the requested local hour and its current source month.
            $result['system']['demand_reference'] = $d->demandMonthlyReference();
            $result['system']['demand_estimate'] = $d->demandHourlyEstimate();
        }

        return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)->header('Cache-Control', $path === 'api/live' ? 'no-store' : 'public, max-age=60')->header('X-Content-Type-Options', 'nosniff');
    }
}
