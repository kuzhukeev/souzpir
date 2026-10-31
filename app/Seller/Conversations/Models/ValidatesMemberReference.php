<?php

namespace App\Seller\Conversations\Models;

use App\Models\Membership;
use App\Tenancy\TenantContext;
use LogicException;

trait ValidatesMemberReference
{
    public static function bootValidatesMemberReference(): void
    {
        static::saving(function (SellerModel $model): void {
            $context = app(TenantContext::class);
            $column = $model instanceof MessageReview ? 'user_id' : 'assigned_user_id';
            $userId = $model->getAttribute($column);
            if ($userId === null && $column !== 'user_id') {
                return;
            }
            if (! is_string($userId) || $context->organizationId === null || $context->brandId === null) {
                throw new LogicException('A valid tenant and member are required.');
            }
            if ($column === 'user_id' && ($context->actorId === null || $context->actorId !== $userId)) {
                throw new LogicException('Reviews can only be recorded for the current actor.');
            }
            $membership = Membership::withoutGlobalScopes()->where('organization_id', $context->organizationId)->where('user_id', $userId)->where('status', 'active')->first();
            if (! $membership || ! $membership->permitsBrand($context->brandId)) {
                throw new LogicException('Member is not authorized for the active brand.');
            }
        });
    }
}
