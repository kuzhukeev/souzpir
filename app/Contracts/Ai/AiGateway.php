<?php

namespace App\Contracts\Ai;

interface AiGateway
{
    public function complete(AiRequest $request, AiContext $context): AiResult;
}
