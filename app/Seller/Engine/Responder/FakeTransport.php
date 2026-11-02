<?php

namespace App\Seller\Engine\Responder;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;

/** Test-only transport; the protected runtime owns real provider execution. */
interface FakeTransport
{
    public function complete(AiRequest $request, AiContext $context): AiResult;
}
