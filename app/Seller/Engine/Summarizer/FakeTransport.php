<?php

namespace App\Seller\Engine\Summarizer;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;

/** The protected runtime will own real provider execution. */
interface FakeTransport
{
    public function complete(AiRequest $request, AiContext $context): AiResult;
}
