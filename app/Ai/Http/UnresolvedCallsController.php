<?php

namespace App\Ai\Http;

use App\Shell\Profile\ProfileAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class UnresolvedCallsController
{
    public function __invoke(#[\SensitiveParameter] Request $request, ProfileAccess $access): JsonResponse
    {
        try {
            $payload = $access->run($request, function ($user, $tenant, $brand, $member, $organization) use ($request): array {
                Gate::forUser($user)->authorize('billing.manage');
                abort_unless($tenant->source === 'session' && $member->role === 'owner'
                    && $organization->owner_user_id === $user->id, 403);

                $input = Validator::make($request->query(), ['after' => ['sometimes', 'required', 'string', 'ulid']])->validate();
                $query = DB::table('ai_calls')->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->whereIn('status', ['reserved', 'uncertain']);
                if (isset($input['after'])) {
                    $query->where('id', '>', strtoupper($input['after']));
                }
                // Fetch only the fields required for an operator to locate an unresolved call.
                $calls = $query->orderBy('id')->limit(51)->get(['id', 'status', 'family', 'reserved_micros', 'created_at', 'updated_at']);
                $hasMore = $calls->count() > 50;
                $items = $calls->take(50)->map(static fn (object $call): array => [
                    'id' => $call->id,
                    'status' => $call->status,
                    'family' => $call->family,
                    'reservedMicros' => (string) $call->reserved_micros,
                    'createdAt' => $call->created_at,
                    'updatedAt' => $call->updated_at,
                ])->values()->all();

                return ['calls' => $items, 'nextCursor' => $hasMore ? $items[count($items) - 1]['id'] : null];
            });

            return response()->json($payload)->header('Cache-Control', 'private, no-store');
        } catch (AuthenticationException) {
            throw new AuthenticationException;
        } catch (AuthorizationException $error) {
            throw (new AuthorizationException)->withStatus($error->status());
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException;
        } catch (ValidationException $error) {
            throw $error;
        } catch (HttpExceptionInterface $error) {
            throw new HttpException($error->getStatusCode(), '', null, $error->getHeaders());
        } catch (Throwable) {
            // Never expose SQL, bindings, provider data or other exception details.
            throw new \RuntimeException('Не удалось загрузить список вызовов ИИ.');
        }
    }
}
