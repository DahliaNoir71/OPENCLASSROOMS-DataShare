<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backstop for the normalization done by the User model: a write that
     * bypasses Eloquent cannot create two accounts whose emails differ only by
     * case. Raw SQL because the schema builder has no portable syntax for an
     * index on an expression — PostgreSQL and SQLite both accept this one.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (LOWER(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX users_email_lower_unique');
    }
};
