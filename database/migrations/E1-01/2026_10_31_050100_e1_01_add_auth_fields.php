<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->string('phone', 40)->nullable();
            $t->timestampTz('terms_accepted_at')->nullable();
            $t->timestampTz('ai_consent_at')->nullable();
            $t->boolean('marketing_opt_in')->default(false);
            $t->text('mfa_secret')->nullable();
            $t->timestampTz('mfa_confirmed_at')->nullable();
            $t->bigInteger('mfa_last_counter')->nullable();
            $t->unsignedInteger('auth_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['phone', 'terms_accepted_at', 'ai_consent_at', 'marketing_opt_in', 'mfa_secret', 'mfa_confirmed_at', 'mfa_last_counter', 'auth_version']));
    }
};
