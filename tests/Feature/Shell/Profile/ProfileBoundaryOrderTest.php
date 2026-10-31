<?php

namespace Tests\Feature\Shell\Profile;

use App\Privacy\SupportRequestBoundary;
use App\Shell\Auth\InvitationSecretBoundary;
use App\Shell\Auth\MfaCodeBoundary;
use App\Shell\Integrations\Webhooks\WebhookConfigurationBoundary;
use App\Tenancy\TokenApi\BearerBoundary;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Middleware\ValidatePostSize;
use Inertia\Middleware\EnsureDeferredCallbacksRun;
use Tests\TestCase;

final class ProfileBoundaryOrderTest extends TestCase
{
    public function test_all_private_input_boundaries_precede_the_framework_stack(): void
    {
        $this->app->boot();
        $actual = $this->app->make(Kernel::class)->getGlobalMiddleware();
        $expected = [SupportRequestBoundary::class, EnsureDeferredCallbacksRun::class, WebhookConfigurationBoundary::class, BearerBoundary::class,
            MfaCodeBoundary::class, InvitationSecretBoundary::class];
        self::assertSame($expected, array_slice($actual, 0, count($expected)));
        self::assertContains(TrustProxies::class, $actual);
        self::assertContains(ValidatePostSize::class, $actual);
        foreach ($expected as $boundary) {
            self::assertSame(1, count(array_keys($actual, $boundary, true)));
            self::assertLessThan(array_search(TrustProxies::class, $actual, true), array_search($boundary, $actual, true));
            self::assertLessThan(array_search(ValidatePostSize::class, $actual, true), array_search($boundary, $actual, true));
        }
    }
}
