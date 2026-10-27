<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OperationalMetrics
{
    public function period(string $metric, string $asset, string $period, ?string $date = null): array
    {
        $now = $date ? CarbonImmutable::parse($date, 'America/Asuncion')->endOfDay() : CarbonImmutable::now('America/Asuncion');
        $from = match ($period) {
            'month' => $now->startOfMonth(),'year' => $now->startOfYear(),default => $now->startOfDay()
        };
        $until = $date ? $now->addDay()->startOfDay() : $now->startOfHour();
        $rows = DB::table('observations')->where('variable_code', $metric)->where('asset_code', $asset)->where('unit', 'MW')->where('grain', 'hour')->where('quality', 'not like', '%UNVERIFIED%')->whereNotNull('measured_at')->where('measured_at', '>=', $from->utc()->format('Y-m-d H:i:s'))->where('measured_at', '<', $until->utc()->format('Y-m-d H:i:s'))->orderBy('measured_at')->orderByDesc('priority')->orderByDesc('id')->get()->unique('measured_at')->values();
        $expected = (int) $from->diffInHours($until);
        $sameSource = $rows->pluck('source_code')->unique()->count() === 1;
        $contiguous = $expected > 0 && $rows->count() === $expected && $sameSource;
        foreach ($rows as $i => $r) {
            if (CarbonImmutable::parse($r->measured_at, 'UTC')->getTimestamp() !== $from->utc()->addHours($i)->getTimestamp()) {
                $contiguous = false;
            }
        }
        $data = app(EnergyData::class);
        $values = $rows->map(fn ($o) => $data->envelope($o))->all();

        return ['asset' => $asset, 'metric' => $metric, 'period' => $period, 'from' => $from->toIso8601String(), 'to' => $until->toIso8601String(), 'expected_hours' => $expected, 'observed_hours' => $rows->count(), 'coverage' => $expected ? $rows->count() / $expected : 0, 'complete' => $contiguous, 'energy_mwh' => $contiguous ? $rows->sum('value') : null, 'peak_mw' => $contiguous ? $rows->max('value') : null, 'load_factor' => $contiguous && $rows->max('value') > 0 ? $rows->avg('value') / $rows->max('value') : null, 'source' => $sameSource ? $rows->first()->source_code : null, 'source_url' => $sameSource ? $rows->first()->source_url : null, 'measured_at' => $rows->last()->measured_at ?? null, 'collected_at' => $rows->max('collected_at'), 'quality' => $contiguous ? 'DERIVED_COMPLETE_HOURLY' : 'UNAVAILABLE', 'stale' => ! $contiguous || collect($values)->contains(fn ($o) => $o['stale']), 'parser_version' => 'period-integration-1.0', 'provenance' => ['formula' => 'sum(mean_hourly_MW * 1 hour); peak=max(hourly_MW); factor=mean/peak', 'input_observation_ids' => $rows->pluck('id')->all(), 'coverage_gate' => '100% completed hourly intervals, exact hour boundaries, one source'], 'data' => $values];
    }
}
