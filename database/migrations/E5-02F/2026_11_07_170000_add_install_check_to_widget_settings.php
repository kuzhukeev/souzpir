<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $t): void {
            $t->string('install_check_status', 20)->nullable();
            $t->string('install_check_message', 255)->nullable();
            $t->timestampTz('install_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $t): void {
            $t->dropColumn(['install_check_status', 'install_check_message', 'install_checked_at']);
        });
    }
};
