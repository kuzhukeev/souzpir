<?php

namespace App\ProtectedOperations\DraftSimulation;

use App\Ai\Drivers\FakeDriver;
use App\Models\User;
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

/** Fixture-only draft runtime bound to an authorized session and immutable draft. */
final class FixtureRuntime
{
    public function extract(Request $request, KnowledgeSnapshot $draft, string $client): ?array
    {
        $data = $this->run($request, $draft, 'extract', ['client' => $client, 'intents' => $draft->intentIds()]);
        if (! is_array($data) || ! is_string($data['client_intent'] ?? null) || ! in_array($data['client_intent'], $draft->intentIds(), true)) {
            return null;
        }

        return ['client_intent' => $data['client_intent']];
    }

    public function check(Request $request, KnowledgeSnapshot $draft, string $client, string $answer, array $expectations): ?array
    {
        $data = $this->run($request, $draft, 'judge', ['client' => $client, 'answer' => $answer, 'expectations' => array_values($expectations)]);
        if (! is_array($data) || ! is_array($data['checks'] ?? null)) {
            return null;
        }
        $checks = [];
        foreach ($expectations as $expectation) {
            if (! is_string($expectation) || ! is_bool($data['checks'][$expectation] ?? null)) {
                return null;
            }
            $checks[$expectation] = $data['checks'][$expectation];
        }

        return $checks;
    }

    public function body(KnowledgeSnapshot $draft, string $stage, array $input): array
    {
        return ['model' => 'fake-draft-'.$stage, 'draft' => $draft->releaseId(), 'checksum' => $draft->checksum(), 'input' => $input];
    }

    private function run(Request $request, KnowledgeSnapshot $draft, string $stage, array $input): ?array
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($draft, $stage, $input): ?array {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $exists = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('id', $draft->releaseId())->where('status', 'draft')->exists();
            abort_unless($exists, 404);
            $current = app(KnowledgeRepository::class)->forRelease($tenant, $draft->releaseId(), true);
            if (! hash_equals($current->checksum(), $draft->checksum()) || $current->toArray() !== $draft->toArray()) {
                throw new LogicException('Draft changed during preview.');
            }
            if (config('runtime.ai_driver') !== 'fake') {
                return null;
            }
            try {
                $result = app(FakeDriver::class)->complete($this->body($draft, $stage, $input));
                $value = json_decode($result['content'] ?? '', true, 32, JSON_THROW_ON_ERROR);

                return is_array($value) ? $value : null;
            } catch (Throwable) {
                return null;
            }
        });
    }
}
