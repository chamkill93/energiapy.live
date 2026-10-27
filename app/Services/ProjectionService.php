<?php

namespace App\Services;

class ProjectionService
{
    public function calculate(float $base, int $year, float $rate, float $capacity): array
    {
        if ($base <= 0 || $capacity <= 0 || $rate <= -1) {
            throw new \InvalidArgumentException('Base y disponibilidad deben ser positivas.');
        }
        $cross = function (float $g) use ($base, $year, $capacity) {
            if ($capacity <= $base) {
                return $year;
            }if ($g <= 0) {
                return null;
            }$years = ceil(log($capacity / $base) / log(1 + $g));

            return $years > 100 ? null : $year + (int) $years;
        };

        return ['earliest_year' => $cross($rate + .01), 'base_year' => $cross($rate), 'latest_year' => $cross($rate - .01), 'series' => array_map(fn ($i) => ['year' => $year + $i, 'energy_gwh' => $base * pow(1 + $rate, $i)], range(0, 20)), 'formula' => 'E(t)=E0*(1+g)^t', 'range' => 'g ± 1 percentage point; not a confidence interval', 'maximum_horizon_years' => 100, 'quality' => 'DERIVED_SCENARIO', 'assumptions' => ['base_gwh' => $base, 'initial_year' => $year, 'annual_growth' => $rate, 'available_gwh' => $capacity]];
    }
}
