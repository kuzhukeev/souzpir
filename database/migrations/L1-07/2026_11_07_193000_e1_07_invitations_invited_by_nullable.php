<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // org:create issues the owner's invitation before any user exists to act as inviter.
        DB::statement('ALTER TABLE invitations ALTER COLUMN invited_by DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invitations ALTER COLUMN invited_by SET NOT NULL');
    }
};
