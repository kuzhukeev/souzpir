<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DeterministicGuardTest extends TestCase
{
    private DeterministicGuard $g;

    protected function setUp(): void
    {
        $data = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $data[$file] = Yaml::parseFile(__DIR__.'/../../../Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml');
        }
        $this->g = new DeterministicGuard(new KnowledgeSnapshot($data, 'fixture', 'fixture'));
    }

    private function check(string $text, string $kind = 'answer_and_ask', ?LeadState $s = null): array
    {
        return $this->g->check($text, new Goal($kind), $s ?? new LeadState);
    }

    public function test_ok_reply_passes(): void
    {
        $r = $this->check('Воронеж, понял. Доставку посчитаем отдельно, когда определимся с составом. Пиротехника вам для себя или на продажу?');
        $this->assertSame('ok', $r['verdict'], implode('; ', $r['issues']));
    }

    public function test_two_questions_blocked(): void
    {
        $r = $this->check('Какой у вас бюджет? И какая точка?');
        $this->assertSame('regenerate', $r['verdict']);
    }

    public function test_deferred_promise_blocked(): void
    {
        $this->assertSame('regenerate', $this->check('Без проблем, дадим отсрочку на месяц.')['verdict']);
        $this->assertSame('ok', $this->check('Отсрочку новым клиентам не даём, работаем по предоплате. Зато скидку выставлю сразу под ваш объём.')['verdict']);
    }

    public function test_return_promise_blocked(): void
    {
        $this->assertSame('regenerate', $this->check('Если не продадите — заберём обратно, не переживайте.')['verdict']);
    }

    public function test_pct_not_in_conditions_blocked(): void
    {
        $r = $this->check('Сделаем вам 45% от базы.');
        $this->assertSame('regenerate', $r['verdict']);
        $this->assertSame('ok', $this->check('От ста тысяч обычно выходит около 20% от базы.')['verdict']);
    }

    public function test_stop_phrase_blocked(): void
    {
        $this->assertSame('regenerate', $this->check('Я не уполномочен разглашать данную информацию.')['verdict']);
    }

    public function test_list_and_emoji_blocked(): void
    {
        $this->assertSame('regenerate', $this->check("Условия такие:\n- предоплата\n- доставка ТК")['verdict']);
        $this->assertSame('regenerate', $this->check('Отличный выбор 🎆 Прайс отправлю.')['verdict']);
    }

    public function test_closed_topic_name_blocked(): void
    {
        $this->assertSame('regenerate', $this->check('Нашего директора зовут Дмитрий, но его контакты не дам.', 'closed_topic')['verdict']);
        $this->assertSame('regenerate', $this->check('Уточню и напишу вам.', 'closed_topic')['verdict']);
        $this->assertSame('ok', $this->check('Это не ко мне. А вот по заказу подскажу: что подбираете?', 'closed_topic')['verdict']);
    }

    public function test_off_topic_fulfilled_blocked(): void
    {
        $this->assertSame('regenerate', $this->check('<html><body>Лендинг</body></html>', 'decline_off_topic')['verdict']);
    }

    public function test_copy_paste_blocked(): void
    {
        $r = $this->check('Мы в Москве; склад и шоурум у метро ВДНХ, проспект Мира, 186, минут десять пешком от метро.');
        $this->assertSame('regenerate', $r['verdict'], implode('; ', $r['issues']));
    }

    public function test_question_on_filled_slot_blocked(): void
    {
        $s = new LeadState;
        $s->setSlot('city', 'Тула');
        $this->assertSame('regenerate', $this->check('Понял. А вы из какого города?', 'ask_slot', $s)['verdict']);
    }

    public function test_price_in_chat_claim_blocked_without_send(): void
    {
        $r = $this->check('Понятно, освежим. Прайс в чате, там можно посмотреть позиции. А что планируете брать?');
        $this->assertSame('regenerate', $r['verdict']);
    }

    public function test_price_sent_claim_ok_when_tool_called(): void
    {
        $r = $this->g->check('Прайс отправил вам на почту. Когда удобно созвониться по подборке?', new Goal('ask_slot'), new LeadState, ['tools_called' => ['send_price']]);
        $this->assertSame('ok', $r['verdict'], implode('; ', $r['issues']));
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
