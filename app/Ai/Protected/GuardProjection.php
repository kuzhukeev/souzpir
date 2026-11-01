<?php

namespace App\Ai\Protected;

use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use Illuminate\Support\Facades\Blade;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Local projection only; caller must separately prove every input. */
final class GuardProjection
{
    /** @return array{body: string, checksum: string} */
    public function render(#[SensitiveParameter] LeadState $state, #[SensitiveParameter] Goal $goal): array
    {
        try {
            $trusted = file_get_contents(resource_path('views/prompts/guard.blade.php'));
            if (! is_string($trusted)) {
                throw new LogicException;
            }

            return ['body' => Blade::render($trusted, ['goal' => $goal, 'facts' => $goal->facts, 'card' => $state->cardText()], deleteCachedView: true),
                'checksum' => hash('sha256', $trusted)];
        } catch (Throwable) {
            throw new LogicException('Guard prompt could not be safely projected.');
        }
    }
}
