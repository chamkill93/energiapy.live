<?php

namespace App\Http\Controllers;

use App\Services\EnergyData;
use App\Services\ProjectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectionController extends Controller
{
    public function store(Request $r, EnergyData $data, ProjectionService $service)
    {
        $v = $r->validate(['growth' => 'required|numeric|min:-10|max:25', 'capacity' => 'required|numeric|min:1|max:10000000', 'scenario' => 'required|string|in:Conservador,Base,Alto,Electrointensivo,Nueva generación']);
        $baseline = collect($data->history(['metric' => 'PY_CONSUMPTION_ENERGY', 'grain' => 'year'])['data'])->filter(fn ($x) => $x['asset_code'] === 'PY_SIN' && $x['unit'] === 'MWh')->last();
        abort_unless($baseline, 422, 'Sin base anual comparable.');
        $result = $service->calculate($baseline['value'] / 1000, (int) substr($baseline['period_start'], 0, 4), $v['growth'] / 100, $v['capacity']);
        $issued = now('UTC');
        $id = DB::table('forecast_runs')->insertGetId(['model_version' => 'scenario-1.0', 'scenario' => $v['scenario'], 'issued_at' => $issued, 'inputs' => json_encode(['user_assumptions' => $v, 'input_observation_ids' => [$baseline['id']], 'source_url' => $baseline['source_url']]), 'result' => json_encode($result)]);

        return response()->json(['id' => $id, 'model_version' => 'scenario-1.0', 'issued_at' => $issued->toIso8601String(), 'scenario' => $v['scenario'], 'result' => $result]);
    }
}
