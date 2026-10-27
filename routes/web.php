<?php

use App\Http\Controllers\EnergyController;
use App\Http\Controllers\ProjectionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('map', ['page' => 'mapa']));
Route::get('/monitoreo', fn () => view('map', ['page' => 'mapa']));
Route::post('/api/projections', [ProjectionController::class, 'store'])->middleware('throttle:20,1');
foreach (['historico', 'proyecciones', 'ciudadania', 'economia', 'fuentes', 'metodologia', 'sobre', 'contacto'] as $page) {
    Route::get('/'.$page, fn () => view('module', ['page' => $page]));
}
Route::prefix('api')->group(function () {
    foreach (['monitoring/summary', 'data/period', 'citizenship/history', 'climate/enso', 'observations/latest', 'live', 'live/plants', 'live/flows', 'data/summary', 'history', 'history/catalog', 'history/replay', 'projections', 'citizenship/tariffs', 'economics/summary', 'gis/departments', 'gis/grid', 'gis/substations', 'sources/status', 'weather/current', 'hydrology/current', 'data/territorial-demand'] as $path) {
        Route::get($path, [EnergyController::class, 'read']);
    }
});
