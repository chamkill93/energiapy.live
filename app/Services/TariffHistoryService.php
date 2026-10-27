<?php

namespace App\Services;

class TariffHistoryService
{
    public function series(): array
    {
        $data = app(EnergyData::class);
        $macro = collect($data->csv('macro_annual'))->keyBy('Año');
        $base = $macro->get('2025');
        $result = [];
        foreach ($data->csv('prices') as $p) {
            if ($p['price_code'] !== 'ANDE_TARIFF_RESIDENTIAL' || $p['grain'] !== 'year' || $p['unit'] !== 'Gs/kWh') {
                continue;
            }
            $year = substr($p['period_start'], 0, 4);
            $m = $macro->get($year);
            $cpi = $m['IPC (2010=100)'] ?? null;
            $fx = $m['USD/PYG promedio'] ?? null;
            $baseCpi = $base['IPC (2010=100)'] ?? null;
            $nominal = (float) $p['value'];
            $result[] = ['year' => (int) $year, 'nominal_gs_kwh' => $nominal,
                'constant_2025_gs_kwh' => is_numeric($cpi) && $cpi > 0 && is_numeric($baseCpi) ? $nominal * $baseCpi / $cpi : null,
                'usd_mwh' => is_numeric($fx) && $fx > 0 ? $nominal * 1000 / $fx : null,
                'source' => $p['source_code'], 'source_url' => $p['source_url'], 'quality' => 'DERIVED_FROM_OFFICIAL_PROVISIONAL_MACRO',
                'measured_at' => null, 'collected_at' => $p['collected_at'], 'stale' => true, 'state' => 'HISTORICAL', 'parser_version' => 'tariff-normalization-1.0',
                'provenance' => ['price_id' => $p['price_id'], 'price_checksum' => $p['provenance']['raw_checksum'], 'macro_checksum' => $m['provenance']['raw_checksum'] ?? null, 'cpi_source_url' => $m['URL IPC'] ?? null, 'fx_source_url' => $m['URL FX'] ?? null, 'macro_quality' => $m['Calidad'] ?? 'UNAVAILABLE', 'cpi' => $cpi, 'base_cpi_2025' => $baseCpi, 'annual_fx_pyg_usd' => $fx, 'formula_constant' => 'nominal * CPI_2025 / CPI_year', 'formula_usd' => 'nominal * 1000 / annual_PYG_per_USD', 'notice' => 'Tarifa media anual observada; no es el precio del pliego residencial. Macro V5 provisional pendiente de reconciliación BCP.']];
        }
        usort($result, fn ($a, $b) => $a['year'] <=> $b['year']);

        return collect($result)->unique(fn ($r) => $r['year'].'|'.$r['nominal_gs_kwh'])->values()->all();
    }
}
