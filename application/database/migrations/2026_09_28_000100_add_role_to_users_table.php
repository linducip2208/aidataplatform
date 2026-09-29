<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('viewer')->after('email')->index();
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        // The index has to go first. SQLite rejects the native
        // `ALTER TABLE "users" DROP COLUMN "role"` while an index still
        // references the column ("1 error in index users_role_index after drop
        // column: no such column: role"), which made `migrate:rollback` fail
        // and left the migration half-rolled-back. Postgres and MySQL drop the
        // index implicitly with the column, so dropping it explicitly is a
        // no-op there and valid everywhere.
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'last_login_at']);
        });
    }
};
