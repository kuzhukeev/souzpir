<?php

namespace App\Shell\Audit;

use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class DatabaseAuditWriter implements AuditWriter
{
    public function record(TenantContext $context, AuditEntry $entry): void
    {
        $organization = $context->requireOrganizationId();
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Audit must be recorded inside the business transaction.');
        }
        if ($entry->action === '' || $entry->subjectType === '' || $entry->subjectId === '') {
            throw new LogicException('Audit action and subject are required.');
        }
        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization,
            'brand_id' => $context->brandId, 'actor_id' => $context->actorId,
            'action' => $entry->action, 'subject_type' => $entry->subjectType, 'subject_id' => $entry->subjectId,
            'before' => $entry->before === null ? null : json_encode($this->redact($entry->before), JSON_THROW_ON_ERROR),
            'after' => $entry->after === null ? null : json_encode($this->redact($entry->after), JSON_THROW_ON_ERROR),
            'request_id' => $entry->requestId, 'event_id' => $entry->eventId, 'ip' => $entry->ip, 'created_at' => now(),
        ]);
    }

    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (preg_match('/password|secret|token|credential|authorization|api.?key|mfa|otp|prompt|message|email|phone/i', (string) $key)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            } elseif (! is_scalar($value) && $value !== null) {
                $values[$key] = '[REDACTED]';
            }
        }

        return $values;
    }
}
