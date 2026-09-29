<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Attribute pre-ownership datasets to the earliest admin.
     *
     * Strict ownership (DatasetPolicy, iteration 5) leaves rows with
     * `user_id = null` writable by admins only. Rows uploaded before
     * ownership existed would otherwise strand analysts from their own
     * history, so they are attributed to the earliest admin account.
     * Idempotent: only touches rows that still have no owner. A no-op when
     * no admin account exists yet (fresh installs create the admin via
     * UserSeeder after migrating).
     */
    public function up(): void
    {
        $adminId = DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        if ($adminId === null) {
            return;
        }

        DB::table('datasets')
            ->whereNull('user_id')
            ->update(['user_id' => $adminId]);
    }

    /**
     * Ownership attribution cannot be un-inferred, so rollback is a
     * documented no-op: rows keep the owner they were given.
     */
    public function down(): void
    {
        //
    }
};
