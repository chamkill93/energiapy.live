<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_fetches', function (Blueprint $t) {
            $t->id();
            $t->string('source_code')->index();
            $t->text('source_url');
            $t->dateTime('collected_at');
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('checksum', 64)->nullable();
            $t->text('raw_path')->nullable();
            $t->string('parser_version');
            $t->string('status');
            $t->text('error')->nullable();
        });
        Schema::create('observations', function (Blueprint $t) {
            $t->id();
            $t->string('identity', 64)->unique();
            $t->string('variable_code');
            $t->string('asset_code');
            $t->double('value');
            $t->string('unit');
            $t->string('grain');
            $t->dateTime('period_start')->nullable();
            $t->dateTime('period_end')->nullable();
            $t->dateTime('measured_at')->nullable();
            $t->dateTime('published_at')->nullable();
            $t->dateTime('collected_at');
            $t->string('source_code');
            $t->text('source_url');
            $t->string('quality');
            $t->string('authority');
            $t->integer('priority')->default(50);
            $t->double('parser_confidence')->default(1);
            $t->string('parser_version');
            $t->string('raw_checksum', 64);
            $t->unsignedBigInteger('fetch_id')->nullable();
            $t->json('provenance');
            $t->index(['variable_code', 'measured_at']);
            $t->index(['asset_code', 'measured_at']);
            $t->index(['source_code', 'collected_at']);
            $t->index(['variable_code', 'grain', 'period_start']);
        });
        Schema::create('source_health', function (Blueprint $t) {
            $t->string('source_code')->primary();
            $t->string('status');
            $t->dateTime('last_attempt')->nullable();
            $t->dateTime('last_success')->nullable();
            $t->dateTime('last_measurement')->nullable();
            $t->string('parser_version')->nullable();
            $t->integer('failures')->default(0);
            $t->text('message')->nullable();
        });
        Schema::create('v5_records', function (Blueprint $t) {
            $t->id();
            $t->string('dataset');
            $t->string('checksum', 64);
            $t->integer('row_number');
            $t->json('payload');
            $t->unique(['dataset', 'row_number']);
        });
        Schema::create('grid_assets', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('kind');
            $t->json('geometry');
            $t->string('quality');
            $t->text('source_url');
            $t->string('checksum', 64);
            $t->dateTime('collected_at');
            $t->json('properties');
        });
        foreach (['flows', 'weather_observations', 'hydrology_observations'] as $name) {
            Schema::create($name, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('observation_id')->unique();
                $t->json('context');
            });
        }
        Schema::create('forecast_runs', function (Blueprint $t) {
            $t->id();
            $t->string('model_version');
            $t->string('scenario');
            $t->dateTime('issued_at');
            $t->json('inputs');
            $t->json('result');
        });
    }

    public function down(): void
    {
        foreach (['forecast_runs', 'hydrology_observations', 'weather_observations', 'flows', 'grid_assets', 'v5_records', 'source_health', 'observations', 'source_fetches'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
