<?php

namespace Tests\Feature;

use App\Services\AgencyMonthlyParser;
use App\Services\EnergyData;
use App\Services\SourceCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgencyMonthlyCollectorTest extends TestCase
{
    use RefreshDatabase;

    private function feed(string $body): string
    {
        return json_encode([['id'=>17,'date'=>'2026-04-10T10:00:00','link'=>'https://www.ip.gov.py/ip/2026/04/10/informe-anual/','content'=>['rendered'=>'<p>Según ANDE.</p><p>'.$body.'</p>']]], JSON_UNESCAPED_UNICODE);
    }

    public function test_collector_preserves_monthly_secondary_source_and_failure_keeps_last_value(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));
        $raw = $this->feed('La demanda de marzo en Paraguay alcanzando un total de 3.271.101 MWh, volumen cubierto por Itaipú, con 2.671.573 MWh y la Central Acaray, con 78.428 MWh.');
        Http::fake(['www.ip.gov.py/*' => Http::sequence()->push($raw, 200)->push('<html>challenge</html>', 200)]);
        $collector = app(SourceCollector::class);
        $result = $collector->run('AGENCIA_IP_ANDE');
        $this->assertSame('ok', $result['status']);
        $row = DB::table('observations')->where('source_code', 'AGENCIA_IP_ANDE')->where('asset_code', 'ACARAY')->first();
        $this->assertSame('2026-03-01 00:00:00', $row->period_start);
        $this->assertSame('https://www.ip.gov.py/ip/2026/04/10/informe-anual/', $row->source_url);
        $this->assertEquals(78428, $row->value);
        $this->assertSame('OFFICIAL_ORIENTATIVE', $row->authority);
        $this->assertSame('AGENCIA_IP_ANDE', app(EnergyData::class)->latestMonthly('ENERGY_TO_PY', 'ACARAY')['source']);
        $this->assertSame('error', $collector->run('AGENCIA_IP_ANDE')['status']);
        $this->assertEquals(78428, app(EnergyData::class)->latestMonthly('ENERGY_TO_PY', 'ACARAY')['value']);
        foreach ([$raw, '<html>challenge</html>'] as $testResponse) {
            $path = storage_path('app/energiapy/raw/AGENCIA_IP_ANDE/'.hash('sha256', $testResponse).'.txt');
            if (is_file($path)) {
                unlink($path);
            }
        }
        CarbonImmutable::setTestNow();
    }

    public function test_parser_rejects_ambiguous_or_conflicting_period_and_source(): void
    {
        $parser = new AgencyMonthlyParser;
        foreach ([
            $this->feed('La demanda de marzo de 2025 alcanzó un total de 3.271.101 MWh, volumen cubierto por la Central Acaray, con 78.428 MWh.'),
            $this->feed('La demanda de marzo alcanzó un total de 3.271.101 MWh. La Central Acaray aportó 78.428 MWh.'),
            str_replace('Según ANDE.', 'Sin atribución.', $this->feed('La demanda de marzo alcanzó un total de 3.271.101 MWh, volumen cubierto por la Central Acaray, con 78.428 MWh.')),
        ] as $raw) {
            try {
                $parser->parse($raw);
                $this->fail('No se debe aceptar un dato ambiguo.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('no se encontró', $e->getMessage());
            }
        }
    }

    public function test_primary_source_wins_for_the_same_month(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));
        $common = ['variable_code'=>'ENERGY_TO_PY','asset_code'=>'ACARAY','unit'=>'MWh','grain'=>'month','period_start'=>'2026-03-01 00:00:00','period_end'=>'2026-03-31 00:00:00','collected_at'=>'2026-04-10 00:00:00','quality'=>'OFFICIAL_AGGREGATE','source_url'=>'https://example.test','parser_version'=>'test','raw_checksum'=>str_repeat('0',64),'provenance'=>'{}'];
        DB::table('observations')->insert($common + ['identity'=>hash('sha256','primary'), 'value'=>100, 'source_code'=>'ANDE', 'authority'=>'OFFICIAL_PRIMARY', 'priority'=>1]);
        DB::table('observations')->insert($common + ['identity'=>hash('sha256','secondary'), 'value'=>200, 'source_code'=>'AGENCIA_IP_ANDE', 'authority'=>'OFFICIAL_ORIENTATIVE', 'priority'=>999]);
        $row=app(EnergyData::class)->latestMonthly('ENERGY_TO_PY','ACARAY');
        $this->assertSame('ANDE',$row['source']);
        $this->assertEquals(100,$row['value']);
        CarbonImmutable::setTestNow();
    }
}
