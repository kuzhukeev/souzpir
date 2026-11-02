<?php

namespace App\Seller\Engine\Guard;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;

/** A fake model only; runtime owns real Gateway calls. */
interface FakeModel
{
    public function complete(AiRequest $request, AiContext $context): AiResult;
}
