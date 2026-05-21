<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Copy existing role_id values into the pivot table
        DB::table('users')
            ->whereNotNull('role_id')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('user_roles')->insertOrIgnore([
                    'user_id'    => $user->id,
                    'role_id'    => $user->role_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        // Drop the role_id column
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('role_id')->nullable()->after('project_id');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade')->onUpdate('cascade');
        });

        // Restore role_id from pivot (take first role per user)
        DB::table('user_roles')->orderBy('user_id')->each(function ($row) {
            DB::table('users')->where('id', $row->user_id)->update(['role_id' => $row->role_id]);
        });
    }
};
