<?php

namespace App\Seller\Conversations\Models;

use App\Models\Membership;
use App\Seller\Conversations\ActiveTenantLock;
use App\Seller\Conversations\AssigneeEligibility;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/** All Eloquent instance saves, including saveQuietly, hold the tenant locks until commit. */
trait ValidatesAssignee
{
    private bool $assigneeSaveInProgress = false;

    public function newEloquentBuilder($query)
    {
        return new AssigneeBuilder($query);
    }

    public function permitsAssigneeInstanceWrite(): bool
    {
        return $this->assigneeSaveInProgress;
    }

    private function saveAssigneeInstance(array $options)
    {
        $this->assigneeSaveInProgress = true;
        try {
            return parent::save($options);
        } finally {
            $this->assigneeSaveInProgress = false;
        }
    }

    public function save(array $options = [])
    {
        $context = app(TenantContext::class);
        if ($context->organizationId === null || $context->brandId === null || ($this->organization_id !== null && $this->organization_id !== $context->organizationId) || ($this->brand_id !== null && $this->brand_id !== $context->brandId) || ($this->exists && ($this->isDirty('organization_id') || $this->isDirty('brand_id')))) {
            throw new LogicException('Assignment tenant is invalid.');
        }
        $userId = $this->getAttribute('assigned_user_id');
        if ($userId === null) {
            return $this->saveAssigneeInstance($options);
        }
        if (! is_string($userId) || $this->getConnection() !== DB::connection()) {
            throw new LogicException('Assignment cannot be validated.');
        }

        return DB::transaction(function () use ($context, $userId, $options) {
            ActiveTenantLock::acquire($context);
            $membership = Membership::withoutGlobalScopes()->where('organization_id', $context->organizationId)->where('user_id', $userId)->where('status', 'active')->first();
            if (! $membership || ! $membership->permitsBrand($context->brandId) || ! AssigneeEligibility::canOperate($membership)) {
                throw new LogicException('Assignee cannot operate in this brand.');
            }

            return $this->saveAssigneeInstance($options);
        });
    }
}
