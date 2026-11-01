<?php

namespace App\Shell\InvitationIssuance;

use App\Shell\Integrations\SecretTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

final class InvitationIssuanceController
{
    public function __invoke(#[\SensitiveParameter] Request $request, InvitationIssuer $issuer): JsonResponse
    {
        SecretTransport::assertAllowed($request);
        abort_unless($request->isJson() && $request->expectsJson(), 415);
        $validator = Validator::make($request->all(), [
            'attempt_key' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
            'brand_ids' => ['required', 'array', 'min:1', 'max:100'],
            'brand_ids.*' => ['required', 'string', 'ulid', 'distinct'],
            'sales_manager' => ['required', 'boolean'],
            'expires_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
        ]);
        abort_if($validator->fails() || array_diff(array_keys($request->all()), ['attempt_key', 'email', 'role', 'brand_ids', 'sales_manager', 'expires_at']), 422);
        $data = $validator->validated();
        abort_if($data['sales_manager'] && $data['role'] !== 'member', 422);

        $link = $issuer->issue($request, $data);

        return $this->response($link);
    }

    private function response(#[\SensitiveParameter] string $link): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'];
        try {
            return new JsonResponse(['link' => $link], 201, $headers);
        } catch (Throwable) {
            // A response construction failure is an unknown outcome; never expose its original exception.
            unset($link);

            return new JsonResponse(['message' => 'Не удалось показать ссылку.'], 500, $headers);
        }
    }
}
