<?php

namespace App\Seller\Conversations\Models;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Eloquent bulk writes cannot bypass tenant and assignee validation on instance saves. */
final class AssigneeBuilder extends Builder
{
    public function update(array $values)
    {
        $this->assertSafeColumns(array_keys($values));

        return parent::update($values);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('Bulk assignee writes are forbidden.');
    }

    public function fillAndInsert(array $values)
    {
        throw new LogicException('Bulk assignee writes are forbidden.');
    }

    public function fillAndInsertOrIgnore(array $values)
    {
        throw new LogicException('Bulk assignee writes are forbidden.');
    }

    public function fillAndInsertGetId(array $values)
    {
        throw new LogicException('Bulk assignee writes are forbidden.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->assertSafeColumns([$column, ...array_keys($extra)]);

        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->assertSafeColumns([$column, ...array_keys($extra)]);

        return parent::decrement($column, $amount, $extra);
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        $this->assertSafeColumns([...array_keys($columns), ...array_keys($extra)]);

        return parent::incrementEach($columns, $extra);
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        $this->assertSafeColumns([...array_keys($columns), ...array_keys($extra)]);

        return parent::decrementEach($columns, $extra);
    }

    public function __call($method, $parameters)
    {
        $methodName = strtolower($method);
        if (in_array($methodName, ['insert', 'insertorignore', 'insertorignorereturning', 'insertgetid', 'insertusing', 'insertorignoreusing', 'updateorinsert', 'updatefrom'], true)
            && ! $this->getModel()->permitsAssigneeInstanceWrite()) {
            throw new LogicException('Bulk assignee writes are forbidden.');
        }

        return parent::__call($method, $parameters);
    }

    private function assertSafeColumns(array $columns): void
    {
        if ($this->getModel()->permitsAssigneeInstanceWrite()) {
            return;
        }
        foreach ($columns as $column) {
            if (! is_string($column) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)
                || in_array(strtolower($column), ['organization_id', 'brand_id', 'assigned_user_id'], true)) {
                throw new LogicException('Bulk assignee writes are forbidden.');
            }
        }
    }
}
