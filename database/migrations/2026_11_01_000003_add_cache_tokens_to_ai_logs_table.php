<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the provider's prompt cache actually did, per call.
 *
 * Worth recording even though it currently reads zero: the frozen half of our
 * prompt measures about 217 tokens and Claude Haiku 5.5 will not cache a
 * prefix below 512, so a cacheable marker on it would silently do nothing. A
 * longer system prompt, or a different model, changes that — and these two
 * columns are how anyone would know, rather than assuming.
 *
 * They also price the call correctly: a cache read costs a tenth of an input
 * token, so counting reads as ordinary input would overstate the bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_logs', function (Blueprint $table) {
            $table->integer('cache_read_tokens')->default(0)->after('tokens_out');
            $table->integer('cache_write_tokens')->default(0)->after('cache_read_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_logs', function (Blueprint $table) {
            $table->dropColumn(['cache_read_tokens', 'cache_write_tokens']);
        });
    }
};
