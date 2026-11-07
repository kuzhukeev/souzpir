<?php

namespace App\Seller\Dialogs;

use App\Ai\Gateway;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\GuardDecision;
use App\Seller\Engine\ResponderResult;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Generates a draft reply for a manager-assigned dialog; never sends or stores it as a message. */
final class ManagerSuggestionController
{
    public function generate(Request $request, string $dialog): mixed
    {
        abort_unless(Str::isUlid($dialog), 422);
        $input = $request->validate(['key' => ['required', 'string', 'max:128']]);
        try {
            app(Gateway::class)->completeManagerSuggestionExtractor($request, $dialog, $input['key']);
            app(Gateway::class)->completeManagerSuggestionResponder($request, $dialog, $input['key']);
            $text = DB::transaction(function () use ($request, $dialog, $input): string {
                $result = app(Gateway::class)->readManagerSuggestionResponderLocked($request, $dialog, $input['key']);
                [, , $state, $goal, $knowledge] = app(ManagerSuggestionResponderInput::class)->build($request, $dialog, $input['key']);
                $candidate = app(ResponderResult::class)->interpret($result);
                if ($candidate['status'] !== 'candidate') {
                    throw new LogicException('Manager suggestion unavailable.');
                }
                $deterministic = (new DeterministicGuard($knowledge))->check($candidate['text'], $goal, $state);
                $decision = app(GuardDecision::class)->finish($deterministic, $goal, false);
                if ($decision['verdict'] !== 'ok') {
                    throw new LogicException('Manager suggestion unavailable.');
                }
                app(AuditWriter::class)->record(app(TenantContext::class), new AuditEntry('seller.dialog.suggestion.generated', 'conversation', $dialog));

                return $candidate['text'];
            });

            return response()->json(['status' => 'ready', 'suggestion' => $text]);
        } catch (IdempotencyConflict) {
            return response()->json(['status' => 'conflict'], 409);
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable'], 422);
        }
    }
}
