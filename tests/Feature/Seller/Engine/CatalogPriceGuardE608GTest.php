<?php

namespace Tests\Feature\Seller\Engine;

use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CatalogPriceGuardE608GTest extends TestCase
{
    private DeterministicGuard $g;

    protected function setUp(): void
    {
        $data = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $data[$file] = Yaml::parseFile(__DIR__.'/../Knowledge/Fixtures/data/'.$file.'.yaml');
        }
        $this->g = new DeterministicGuard(new KnowledgeSnapshot($data, 'fixture', 'fixture'));
    }

    public function test_catalog_price_not_from_check_stock_blocked_when_enabled(): void
    {
        $r = $this->g->check('Этот салют стоит 1200 руб.', new Goal('answer_and_ask'), new LeadState,
            ['catalog_prices_enabled' => true, 'catalog_known_prices' => [900.0]]);
        $this->assertSame('regenerate', $r['verdict']);
    }

    public function test_catalog_price_matching_check_stock_result_ok(): void
    {
        $r = $this->g->check('Этот салют стоит 900 руб.', new Goal('answer_and_ask'), new LeadState,
            ['catalog_prices_enabled' => true, 'catalog_known_prices' => [900.0]]);
        $this->assertSame('ok', $r['verdict'], implode('; ', $r['issues']));
    }

    public function test_catalog_price_ignored_when_disabled(): void
    {
        $r = $this->g->check('Этот салют стоит 1200 руб.', new Goal('answer_and_ask'), new LeadState, []);
        $this->assertSame('ok', $r['verdict'], implode('; ', $r['issues']));
    }
}
