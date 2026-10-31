<?php

namespace App\Shell\Profile;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Disabled or incomplete schema must never select a profile flow. */
final class ProfileSettingsAvailability
{
    private const MIGRATIONS = [
        '2026_10_31_180000_create_mfa_recovery_codes',
        '2026_10_31_180800_e1_08_create_profile_tables',
        '2026_10_31_190108_e1_08_create_profile_test_notifications',
    ];

    private const COLUMNS = [
        'mfa_recovery_codes' => ['id', 'user_id', 'code_hash', 'used_at', 'created_at', 'updated_at'],
        'mfa_challenge_limits' => ['user_id', 'failures', 'expires_at'],
        'profile_preferences' => ['user_id', 'timezone', 'language', 'avatar_path', 'created_at', 'updated_at'],
        'profile_email_changes' => ['id', 'user_id', 'organization_id', 'brand_id', 'target_email', 'target_fingerprint', 'source_fingerprint', 'auth_version', 'token_hash', 'encrypted_token', 'status', 'expires_at', 'created_at', 'updated_at'],
        'profile_telegram_accounts' => ['user_id', 'chat_fingerprint', 'created_at', 'updated_at'],
        'profile_telegram_links' => ['id', 'organization_id', 'brand_id', 'user_id', 'token_hash', 'expires_at', 'chat_id', 'pending_chat_id', 'pending_label', 'chat_fingerprint', 'created_at', 'updated_at'],
        'profile_session_details' => ['session_hash', 'user_id', 'ip_address', 'user_agent', 'created_at'],
        'profile_test_notifications' => ['id', 'organization_id', 'brand_id', 'user_id', 'request_key', 'auth_version', 'channel', 'status', 'sent_at', 'created_at', 'updated_at'],
    ];

    public function configured(): bool
    {
        return config('features.E1-08.defaults.enabled', false) === true;
    }

    public function ready(): bool
    {
        if (! $this->configured()) {
            return false;
        }
        try {
            // Nested transactions create a savepoint when called from login;
            // a failed metadata query must not poison the outer transaction.
            return DB::transaction(function (): bool {
                if (! Schema::hasTable('migrations') || DB::table('migrations')->whereIn('migration', self::MIGRATIONS)->distinct()->count('migration') !== count(self::MIGRATIONS)) {
                    return false;
                }
                foreach (self::COLUMNS as $table => $columns) {
                    if (! Schema::hasColumns($table, $columns)) {
                        return false;
                    }
                }

                return true;
            });
        } catch (QueryException) {
            return false;
        }
    }

    /** Used for the shared MFA limiter even while the profile feature is off. */
    public function columnsExist(string $table, array $columns): bool
    {
        try {
            return DB::transaction(static fn (): bool => Schema::hasColumns($table, $columns));
        } catch (QueryException) {
            return false;
        }
    }
}
