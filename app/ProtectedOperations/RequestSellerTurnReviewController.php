<?php

namespace App\ProtectedOperations;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Explicit operator action; routing and CSRF middleware are owned by the integrator. */
final class RequestSellerTurnReviewController
{
    public function __invoke(Request $request, string $turn): JsonResponse
    {
        if (! $request->isMethod('POST')) {
            return $this->error(405)->header('Allow', 'POST');
        }
        if (! Str::isUlid($turn)) {
            return $this->error(404);
        }

        try {
            $review = app(ManualReviewRequests::class)->quarantineAndRequest($request, $turn);

            return response()->json(['data' => ['status' => 'uncertain', ...$review]])
                ->header('Cache-Control', 'private, no-store');
        } catch (AuthenticationException) {
            return $this->error(401);
        } catch (HttpExceptionInterface $error) {
            return $this->error($error->getStatusCode());
        } catch (Throwable) {
            return $this->error(503);
        }
    }

    private function error(int $status): JsonResponse
    {
        return response()->json(['message' => 'Операция временно недоступна.'], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
