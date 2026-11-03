<?php

namespace App\Ai\Protected;

use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;
use Illuminate\Support\Facades\Blade;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Prototype prompt projection only; its arguments do not prove a protected stage. */
final class ResponderProjection
{
    public function render(#[SensitiveParameter] KnowledgeSnapshot $knowledge, #[SensitiveParameter] LeadState $state, #[SensitiveParameter] Goal $goal, #[SensitiveParameter] string $agentName): string
    {
        try {
            $template = file_get_contents(resource_path('views/prompts/responder.blade.php'));
            if (! is_string($template) || trim($agentName) === '') {
                throw new LogicException;
            }

            return Blade::render($template, [
                'persona' => $knowledge->persona(),
                'agentName' => $agentName,
                'card' => $state->cardText(),
                'summary' => $state->data['summary'] ?? null,
                'goal' => $goal,
                'facts' => $goal->facts,
                'trigger' => $goal->trigger,
                'examples' => $knowledge->fewshot($goal->fewshotTags, $state->clientType(), 6, 0.7),
                'badExamples' => array_slice($knowledge->badExamples(), 0, 8),
                'slotHint' => $goal->slot ? $this->slotHint($goal->slot, $state, $knowledge) : null,
                'guardIssues' => [],
                'closedTopic' => $goal->extras['closed_topic'] ?? null,
                'conditionsNote' => empty($knowledge->conditions()['approved_by']) ? 'Условия (минималка, проценты) пока не утверждены руководителем — называй их как ориентир («обычно», «примерно»), без гарантий.' : null,
            ], deleteCachedView: true);
        } catch (Throwable) {
            throw new LogicException('Responder prompt could not be safely projected.');
        }
    }

    private function slotHint(string $slot, LeadState $state, KnowledgeSnapshot $knowledge): string
    {
        $hints = [
            'city' => 'спросить, из какого города клиент',
            'purpose' => 'спросить, для себя или на продажу',
            'experience' => 'спросить, был ли опыт торговли или это первый сезон',
            'outlet_type' => 'спросить формат точки: павильон / ТЦ (и этаж) / жилой дом / интернет / с машины',
            'categories' => 'спросить, какие категории товаров интересуют клиента',
            'current_supplier' => 'спросить, с кем работает сейчас и какую скидку дают (мягко, без переманивания)',
            'budget_range' => $state->counter('budget_asks') >= 1
                ? 'ВТОРАЯ попытка узнать бюджет: предложить вилку 50 / 100 / 200 тысяч, объяснить, что это для скидки и ни к чему не обязывает. Если откажется — больше не спрашивать.'
                : 'спросить ориентир суммы закупки (плюс-минус), объяснив, что это ни к чему не обязывает и нужно для скидки',
            'timing' => 'спросить, закупка сейчас или ближе к сезону',
            'email' => 'спросить почту для прайса',
            'messenger' => 'спросить, какой мессенджер на номере (Макс / WhatsApp / Telegram), предупредить, что напишете с другого номера',
            'next_contact_at' => 'спросить, когда удобно списаться по обратной связи, предложив два варианта (например завтра или в пятницу)',
            'legal' => 'уточнить, на кого оформлять: физлицо, ИП или ООО',
        ];

        $configured = $knowledge->persona()['slot_hints'][$slot] ?? null;

        return is_string($configured) && trim($configured) !== '' ? $configured : ($hints[$slot] ?? "спросить {$slot}");
    }
}
