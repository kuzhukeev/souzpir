<?php

namespace App\Shell\Brands\Actions;

use App\Models\Brand;
use App\Models\Membership;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Brands\BrandAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BrandAiPolicy
{
    public const CLASSES = ['conversation_content', 'knowledge_internal', 'vision_question', 'knowledge_public'];

    public function __construct(private readonly BrandAccess $access) {}

    /** @return array<string,bool> */
    public function current(Brand $brand): array
    {
        return self::fromSettings($brand->settings ?? []);
    }

    /** @return array<string,bool> */
    public static function fromSettings(array $settings): array
    {
        $policy = is_array($settings['ai_data_policy'] ?? null) ? $settings['ai_data_policy'] : [];
        $result = [];
        foreach (self::CLASSES as $class) {
            $result[$class] = in_array('any', is_array($policy[$class] ?? null) ? $policy[$class] : [], true);
        }

        return $result;
    }

    /** Owner-only save from the brand settings page. @param array<string,bool> $allow */
    public function updateFromRequest(Request $request, string $brandId, array $allow): array
    {
        return $this->access->change($request, $brandId, function (Brand $brand, TenantContext $ctx) use ($allow): array {
            $member = Membership::withoutGlobalScopes()->whereKey($ctx->membershipId)->first();
            abort_unless($member && $member->role === 'owner', 403);

            return $this->save($brand, $ctx, $allow);
        });
    }

    /** Console: overlays explicit allow/deny class lists onto the current policy. */
    public function updateFromConsole(Brand $brand, array $allowClasses, array $denyClasses): array
    {
        return DB::transaction(function () use ($brand, $allowClasses, $denyClasses): array {
            $locked = Brand::withoutGlobalScopes()->whereKey($brand->id)->where('organization_id', $brand->organization_id)->lockForUpdate()->firstOrFail();
            $allow = self::fromSettings($locked->settings ?? []);
            foreach ($allowClasses as $class) {
                $allow[$class] = true;
            }
            foreach ($denyClasses as $class) {
                $allow[$class] = false;
            }
            $ctx = new TenantContext($locked->organization_id, $locked->id, null, null, 'job');

            return app(TenantContextRunner::class)->run($ctx, fn () => $this->save($locked, $ctx, $allow));
        });
    }

    /** @param array<string,bool> $allow */
    private function save(Brand $brand, TenantContext $ctx, array $allow): array
    {
        $settings = $brand->settings ?? [];
        $policy = [];
        foreach (self::CLASSES as $class) {
            $policy[$class] = ($allow[$class] ?? false) ? ['any'] : [];
        }
        $settings['ai_data_policy'] = $policy;
        try {
            $brand->forceFill(['settings' => $settings])->save();
        } catch (QueryException $error) {
            if ((string) $error->getCode() === 'P0001' && str_contains($error->getMessage(), 'policy changes')) {
                throw new BrandAiPolicyLocked('Публичные знания сейчас переданы в активный доступ Видения. Сначала отзовите действующий доступ и повторите.');
            }
            throw $error;
        }
        $result = self::fromSettings($settings);
        app(AuditWriter::class)->record($ctx, new AuditEntry('brand.ai_policy.updated', 'brand', $brand->id, after: $result));

        return $result;
    }
}
