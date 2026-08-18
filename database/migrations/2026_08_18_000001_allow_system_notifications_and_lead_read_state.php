<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the platform itself message a vendor, and lets a vendor mark a lead handled.
 *
 * Notifications reuse `admin_messages` rather than adding a table: the app already reads it
 * through myMessages / unreadMessageCount, so a vendor sees these with no client work. The
 * only obstacle was `admin_id` being NOT NULL — a system notice has no admin behind it.
 *
 * `product_leads.is_read` exists so a vendor can clear an enquiry once they have called
 * back; without it the leads screen is an undifferentiated pile and the unread count is
 * meaningless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_messages', function (Blueprint $table) {
            // null = sent by the system rather than a person
            $table->unsignedInteger('admin_id')->nullable()->change();

            // Lets the app route a notification to the right screen instead of only
            // showing text. null for the plain admin-to-user messages that predate this.
            $table->string('type', 40)->nullable()->after('admin_id');
            $table->json('meta_data')->nullable()->after('message');
        });

        Schema::table('product_leads', function (Blueprint $table) {
            $table->boolean('is_read')->default(false)->after('platform');
            $table->timestamp('read_at')->nullable()->after('is_read');

            $table->index(['product_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::table('product_leads', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'is_read']);
            $table->dropColumn(['is_read', 'read_at']);
        });

        Schema::table('admin_messages', function (Blueprint $table) {
            $table->dropColumn(['type', 'meta_data']);
            $table->unsignedInteger('admin_id')->nullable(false)->change();
        });
    }
};
