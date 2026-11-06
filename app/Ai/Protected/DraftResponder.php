<?php

namespace App\Ai\Protected;

use App\Ai\Drivers\FakeDriver;
use App\Models\User;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Throwable;

/** Fixture-only responder; missing fixtures leave the existing preview unchanged. */
final class DraftResponder
{
    public function body(KnowledgeSnapshot $draft, LeadState $state, Goal $goal, string $client): array
    {
        $name = $draft->persona()['name'] ?? 'Продавец';
        $name = is_string($name) && trim($name) !== '' ? $name : 'Продавец';

        return ['model' => 'fake-draft-responder', 'draft' => $draft->releaseId(), 'checksum' => $draft->checksum(),
            'input' => ['system' => app(ResponderProjection::class)->render($draft, $state, $goal, $name), 'client' => $client]];
    }

    public function respond(Request $request, KnowledgeSnapshot $draft, LeadState $state, Goal $goal, string $client): ?string
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($draft, $state, $goal, $client): ?string {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            abort_unless(DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $draft->releaseId())->where('status', 'draft')->exists(), 404);
            $current = app(KnowledgeRepository::class)->forRelease($tenant, $draft->releaseId(), true);
            if (! hash_equals($current->checksum(), $draft->checksum()) || $current->toArray() !== $draft->toArray()) {
                throw new LogicException('Draft changed during preview.');
            }
            if (config('runtime.ai_driver') !== 'fake') {
                return null;
            }
            try {
                $response = app(FakeDriver::class)->complete($this->body($draft, $state, $goal, $client));
                $text = $response['content'] ?? null;

                return is_string($text) && trim($text) !== '' && mb_strlen($text) <= 5000 ? trim($text) : null;
            } catch (Throwable) {
                return null;
            }
        });
    }
}
