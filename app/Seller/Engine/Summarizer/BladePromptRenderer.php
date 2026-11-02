<?php

namespace App\Seller\Engine\Summarizer;

use Illuminate\Support\Facades\Blade;

final class BladePromptRenderer implements PromptRenderer
{
    public function render(string $body, ?string $previous): string
    {
        return Blade::render($body, ['previous' => $previous], deleteCachedView: true);
    }
}
