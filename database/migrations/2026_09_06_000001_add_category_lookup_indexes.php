<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The landing page resolves trending sites one category at a time, and every
     * one of those ~32 queries filtered categories on `code` (and the hot-category
     * flag) with no index to use — a full scan plus a temporary table and filesort
     * each time. Cheap table, but it runs on every request.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! $this->indexExists('categories', 'categories_code_status_index')) {
                $table->index(['code', 'status'], 'categories_code_status_index');
            }

            if (! $this->indexExists('categories', 'categories_hot_status_index')) {
                $table->index(['is_hot_category', 'status'], 'categories_hot_status_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_code_status_index');
            $table->dropIndex('categories_hot_status_index');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($row) => $row->Key_name === $index);
    }
};
