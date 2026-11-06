<?php

namespace Tests\Feature\Vision\Profile;

use Illuminate\Testing\TestResponse;

/** Existing hint assertions use the same protected request as the client. */
trait ResolvesDeferredHints
{
    private function resolveDeferredHints(TestResponse $response, string $path): TestResponse
    {
        if (! preg_match('~^/vision/quiz/([45])$~', $path, $matches)) {
            return $response;
        }
        $page = $response->viewData('page');
        $grant = $page['props']['hints_grant'] ?? null;
        if (! is_string($grant)) {
            return $response;
        }
        $hints = $this->withHeader('X-Quiz-Hints-Grant', $grant)->getJson($path.'/hints')->assertOk()->json();
        $this->withoutHeader('X-Quiz-Hints-Grant');
        $page['props']['hints'] = $hints;
        $response->baseResponse->getOriginalContent()->with('page', $page);

        return $response;
    }
}
