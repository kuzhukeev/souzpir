<?php

namespace App\Seller\Engine\Summarizer;

interface PromptRenderer
{
    public function render(string $body, ?string $previous): string;
}
