<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Route search joins route_stops to itself — "which routes call at A before
     * they call at B". With only the separate site_id and route_id indexes MySQL
     * had to read the table for one side of the join and build a temporary table
     * for the DISTINCT. This composite covers both sides, so the whole join is
     * answered from the index.
     */
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            if (! $this->indexExists('route_stops', 'route_stops_site_route_serial_index')) {
                $table->index(['site_id', 'route_id', 'serial_no'], 'route_stops_site_route_serial_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropIndex('route_stops_site_route_serial_index');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($row) => $row->Key_name === $index);
    }
};
