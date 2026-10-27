<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AndeBalance2024Test extends TestCase
{
    use RefreshDatabase;

    public function test_balance_preserves_published_categories_and_does_not_duplicate(): void
    {
        $this->artisan('energy:import-ande-balance-2024')->assertSuccessful();
        $this->artisan('energy:import-ande-balance-2024')->assertSuccessful();
        $rows = DB::table('observations')->get()->keyBy('variable_code');
        $this->assertCount(2, $rows);
        $this->assertEquals(768198, $rows['PY_GROSS_OWN_GENERATION']->value);
        $this->assertEquals(25490406, $rows['PY_PURCHASED_ENERGY']->value);
        $this->assertEquals(26258604, $rows->sum('value'));
        $this->assertSame('year', $rows['PY_PURCHASED_ENERGY']->grain);
    }
}
