<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEventsTable extends Migration
{
    public function up()
    {
        Schema::create('events', function (Blueprint $table) {
            $table->increments('id');

            // Ownership & Status
            $table->integer('user_id')->unsigned();
            $table->integer('site_id')->unsigned()->nullable();
            $table->integer('event_type_id')->unsigned()->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'cancelled', 'completed'])->default('pending');
            $table->text('rejection_reason')->nullable();

            // Basic Info
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');

            // Organizer Contact
            $table->string('organizer_name');
            $table->string('organizer_phone', 20);
            $table->string('organizer_email')->nullable();
            $table->string('contact_person_name')->nullable();
            $table->string('contact_person_phone', 20)->nullable();

            // Location
            $table->string('venue_name')->nullable();
            $table->text('address');
            $table->enum('taluka', ['Devgad', 'Kudal', 'Malvan', 'Sawantwadi', 'Vengurla', 'Dodamarg', 'Kankavli', 'Vaibhavvadi']);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Date & Time
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_multi_day')->default(false);
            $table->string('timezone', 50)->default('Asia/Kolkata');

            // Media
            $table->string('banner_image')->nullable();
            $table->json('gallery')->nullable();
            $table->string('video_url')->nullable();

            // Entry & Registration
            $table->boolean('is_free')->default(true);
            $table->decimal('entry_fee', 10, 2)->nullable();
            $table->boolean('registration_required')->default(false);
            $table->string('registration_link')->nullable();
            $table->date('registration_deadline')->nullable();
            $table->integer('max_participants')->nullable();

            // Tags
            $table->json('tags')->nullable();

            // Engagement Stats (Cached)
            $table->integer('view_count')->default(0);
            $table->integer('click_count')->default(0);
            $table->integer('share_count')->default(0);
            $table->integer('like_count')->default(0);
            $table->integer('favourite_count')->default(0);
            $table->integer('going_count')->default(0);
            $table->integer('interested_count')->default(0);

            // Featured & Promotion
            $table->boolean('is_featured')->default(false);
            $table->timestamp('featured_until')->nullable();
            $table->boolean('is_sponsored')->default(false);
            $table->string('sponsor_name')->nullable();

            // Notifications
            $table->boolean('notification_sent')->default(false);
            $table->boolean('reminder_sent')->default(false);

            // Moderation
            $table->integer('approved_by')->unsigned()->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('admin_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign Keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('site_id')->references('id')->on('sites')->onDelete('set null');
            $table->foreign('event_type_id')->references('id')->on('event_types')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->index(['status', 'start_date']);
            $table->index(['user_id', 'status']);
            $table->index(['taluka', 'status', 'start_date']);
            $table->index(['event_type_id', 'status', 'start_date']);
            $table->index('slug');
        });
    }

    public function down()
    {
        Schema::dropIfExists('events');
    }
}
