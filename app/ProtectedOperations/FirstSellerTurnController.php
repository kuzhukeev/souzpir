<?php

namespace App\ProtectedOperations;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Seller\Conversations\Data\TurnCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class FirstSellerTurnController
{
    public function __invoke(Request $request, FirstTurnCoordinator $coordinator): JsonResponse
    {
        Gate::authorize('seller.dialogues.operate');
        abort_unless(config('runtime.ai_driver', 'fake') === 'fake', 503);

        $input = $request->validate([
            'conversation_id' => ['required', 'ulid'],
            'message_id' => ['required', 'ulid'],
            'text' => ['required', 'string'],
            'external_event_id' => ['nullable', 'ulid'],
            'idempotency_key' => ['required', 'string', 'max:512'],
            'brand_id' => ['prohibited'],
            'organization_id' => ['prohibited'],
        ]);

        $command = new TurnCommand($input['conversation_id'], $input['message_id'], $input['text'],
            $input['external_event_id'] ?? null, $input['idempotency_key'], 0);

        try {
            $result = $coordinator->run($request, $command);
        } catch (IdempotencyConflict) {
            return response()->json(['message' => 'Ключ уже использован для другого запроса.'], 409)
                ->header('Cache-Control', 'private, no-store');
        }

        if (! in_array($result['status'] ?? null, ['completed', 'in_progress', 'uncertain'], true)) {
            return response()->json(['message' => 'Первый ход временно недоступен.'], 503)
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
    }
}
