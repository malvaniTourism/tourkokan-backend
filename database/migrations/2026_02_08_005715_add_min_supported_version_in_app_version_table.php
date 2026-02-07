<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMinSupportedVersionInAppVersionTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
     public function up()
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->string('min_supported_version')->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->dropColumn('min_supported_version');
        });
    }
}
