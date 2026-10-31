<?php

namespace Tests\Feature\Seller\Settings\Limits;

use App\Seller\Settings\Limits\DigestPreferenceController;
use App\Seller\Settings\Limits\NotificationsController;
use App\Seller\Settings\Limits\TestDeliveryController;
use App\Seller\Settings\Limits\TestDeliveryService;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class StaleSessionTest extends LimitsTestCase
{
    public function test_revoked_session_cannot_mutate_rules_preferences_or_request_a_test(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $make = function (array $data) use ($owner): Request {
            $request = Request::create('/', 'POST', $data);
            $request->setLaravelSession(session()->driver());
            $request->setUserResolver(fn () => $owner);

            return $request;
        };
        $registry = app(AuthSessionRegistry::class);
        $request = $make([]);
        $registry->withinActive($request, fn () => $registry->revokeCurrent($request));
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id, 'session');
        $attempts = [
            fn () => app(NotificationsController::class)->update($make(['changes' => [['event' => 'seller.conversation.handed_off', 'recipient' => 'owner', 'channels' => ['cabinet' => true, 'email' => false, 'telegram' => false], 'timing' => 'immediate']]]), $context),
            fn () => app(DigestPreferenceController::class)($make(['digest_time' => '12:00']), $context),
            fn () => app(TestDeliveryController::class)($make(['event' => 'seller.conversation.handed_off', 'channel' => 'cabinet', 'key' => (string) Str::uuid()]), $context, app(TestDeliveryService::class)),
        ];
        foreach ($attempts as $attempt) {
            try {
                app(TenantContextRunner::class)->run($context, $attempt);
                self::fail('The revoked session must not authorize the action.');
            } catch (AuthenticationException) {
                self::assertTrue(true);
            }
        }
        $this->assertDatabaseCount('seller_notification_rules', 0);
        $this->assertDatabaseCount('seller_digest_preferences', 0);
        $this->assertDatabaseCount('seller_test_deliveries', 0);
    }
}
