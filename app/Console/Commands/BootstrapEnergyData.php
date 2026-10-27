<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BootstrapEnergyData extends Command
{
    protected $signature = 'energy:bootstrap-data';
    protected $description = 'Reconstruye series históricas y cartografía desde los archivos incluidos, sin borrar la base';

    public function handle(): int
    {
        $rawGis = storage_path('app/gis/raw');
        if (! is_dir($rawGis) && ! mkdir($rawGis, 0755, true) && ! is_dir($rawGis)) {
            $this->error('No se pudo crear el directorio para conservar la fuente cartográfica.');
            return self::FAILURE;
        }
        $commands = [
            ['energy:import-v5', []],
            ['energy:archive-workbook-v5', []],
            ['energy:import-workbook-history', []],
            ['energy:import-workbook-context', []],
            ['energy:import-cammesa-binational', []],
            ['energy:import-cammesa-annual', []],
            ['energy:import-ande-customer-capita-history', []],
            ['energy:import-worldbank-electricity', []],
            ['energy:import-ande-august-2026', []],
            ['energy:import-ande-distribution-losses', []],
            ['energy:import-ande-consumption-origin-2025', []],
            ['energy:import-ande-balance-2024', []],
            ['energy:import-itaipu-annual-reports', []],
            ['energy:import-itaipu-september-2026', []],
            ['energy:import-itaipu-historical-generation', []],
            ['energy:import-vmme-monthly-sin', []],
            ['energy:import-agency-june-2026', []],
            ['energy:import-mendeley-demand', []],
            ['energy:import-ons-itaipu-monthly', []],
            ['energy:import-gis', ['file' => storage_path('app/gis/verified-plants.geojson'), '--kind' => 'plants']],
            ['energy:sync-transmission-gis', []],
        ];
        foreach ($commands as [$command, $arguments]) {
            $this->line('Importando '.$command.'…');
            if ($this->call($command, $arguments) !== self::SUCCESS) {
                $this->error('No se pudo completar '.$command.'. Corregí el problema y volvé a ejecutar el comando.');
                return self::FAILURE;
            }
        }
        $this->info('Series y cartografía importadas. Las consultas en vivo se ejecutan por separado.');
        return self::SUCCESS;
    }
}
