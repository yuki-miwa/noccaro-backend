<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->text('push_token');
            $table->string('device_uuid', 128)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->string('os_version', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'push_token']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 120);
            $table->string('space_code', 20)->unique();
            $table->text('description')->nullable();
            $table->string('join_policy', 20)->default('approval_required');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('max_owner_count')->default(3);
            $table->unsignedInteger('whisper_ttl_minutes')->default(180);
            $table->unsignedInteger('whisper_auto_hide_report_threshold')->default(5);
            $table->unsignedInteger('whisper_rate_limit_per_minute')->default(1);
            $table->unsignedInteger('whisper_rate_limit_per_10min')->default(3);
            $table->unsignedInteger('whisper_max_length')->default(30);
            $table->unsignedInteger('location_grid_meters')->default(120);
            $table->boolean('location_jitter_enabled')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('space_memberships', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('guest');
            $table->string('status', 20)->default('pending');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_membership_id')->nullable()->constrained('space_memberships')->nullOnDelete();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('kicked_at')->nullable();
            $table->timestamp('suspended_until')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->timestamp('mute_until')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['space_id', 'user_id']);
            $table->index(['space_id', 'status', 'role']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('space_posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignId('author_membership_id')->constrained('space_memberships');
            $table->string('title', 200);
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->boolean('notify_members')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('visible_from')->nullable();
            $table->timestamp('visible_to')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['space_id', 'status', 'published_at']);
        });

        Schema::create('space_post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('space_posts')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('space_memberships')->cascadeOnDelete();
            $table->string('reaction_type', 20)->default('like');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['post_id', 'membership_id']);
        });

        Schema::create('map_whispers', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('space_memberships')->cascadeOnDelete();
            $table->string('body', 30);
            $table->string('status', 30)->default('active');
            $table->string('grid_key', 64);
            $table->decimal('display_lat', 10, 7);
            $table->decimal('display_lng', 10, 7);
            $table->unsignedInteger('display_radius_m');
            $table->timestamp('expires_at');
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_membership_id')->nullable()->constrained('space_memberships')->nullOnDelete();
            $table->unsignedInteger('report_count')->default(0);
            $table->timestamps();

            $table->index(['space_id', 'status', 'expires_at']);
            $table->index(['space_id', 'grid_key', 'status']);
        });

        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignId('reporter_membership_id')->constrained('space_memberships')->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('reason_type', 30);
            $table->text('detail')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('handled_by_membership_id')->nullable()->constrained('space_memberships')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('resolution_type', 30)->nullable();
            $table->timestamps();

            $table->unique(['reporter_membership_id', 'target_type', 'target_id']);
            $table->index(['space_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_reports');
        Schema::dropIfExists('map_whispers');
        Schema::dropIfExists('space_post_reactions');
        Schema::dropIfExists('space_posts');
        Schema::dropIfExists('space_memberships');
        Schema::dropIfExists('spaces');
        Schema::dropIfExists('user_push_devices');
    }
};
