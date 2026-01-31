<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAdvertisingColumnsToBannersTable extends Migration
{
    public function up()
    {
        Schema::table('banners', function (Blueprint $table) {
            if (!Schema::hasColumn('banners', 'user_id')) {
                $table->unsignedInteger('user_id')->nullable();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            }
            if (!Schema::hasColumn('banners', 'banner_package_id')) {
                $table->foreignId('banner_package_id')->nullable()->constrained('banner_packages')->onDelete('set null');
            }
            if (!Schema::hasColumn('banners', 'banner_placement_id')) {
                $table->foreignId('banner_placement_id')->nullable()->constrained('banner_placements')->onDelete('set null');
            }
            
            if (!Schema::hasColumn('banners', 'start_date')) {
                $table->date('start_date')->nullable();
            }
            if (!Schema::hasColumn('banners', 'end_date')) {
                $table->date('end_date')->nullable();
            }
            
            if (!Schema::hasColumn('banners', 'status')) {
                $table->string('status')->default('pending'); // pending, approved, rejected, expired
            }
            
            if (!Schema::hasColumn('banners', 'impressions')) {
                $table->unsignedBigInteger('impressions')->default(0);
            }
            if (!Schema::hasColumn('banners', 'clicks')) {
                $table->unsignedBigInteger('clicks')->default(0);
            }
            
            if (!Schema::hasColumn('banners', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (!Schema::hasColumn('banners', 'redirect_url')) {
                $table->string('redirect_url')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('banners', function (Blueprint $table) {
            // We only drop columns we added. 
            // Note: In a real production rollback, be careful not to drop columns that existed before if names collide.
            $table->dropForeign(['user_id']);
            $table->dropForeign(['banner_package_id']);
            $table->dropForeign(['banner_placement_id']);
            $table->dropColumn([
                'user_id', 'banner_package_id', 'banner_placement_id', 
                'start_date', 'end_date', 'status', 'impressions', 'clicks'
            ]);
        });
    }
}