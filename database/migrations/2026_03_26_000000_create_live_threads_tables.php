<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_threads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('active');
            $table->foreignId('created_by_membership_id')->nullable()->constrained('space_memberships')->nullOnDelete();
            $table->foreignId('closed_by_membership_id')->nullable()->constrained('space_memberships')->nullOnDelete();
            $table->foreignId('closed_by_system_admin_id')->nullable()->constrained('system_admins')->nullOnDelete();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('close_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['space_id', 'status']);
            $table->index(['status', 'starts_at']);
        });

        Schema::create('live_stream_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('live_thread_id')->constrained('live_threads')->cascadeOnDelete();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('live');
            $table->string('ivs_channel_arn');
            $table->text('ivs_playback_url');
            $table->string('ivs_ingest_endpoint');
            $table->foreignId('started_by_membership_id')->nullable()->constrained('space_memberships')->nullOnDelete();
            $table->foreignId('ended_by_membership_id')->nullable()->constrained('space_memberships')->nullOnDelete();
            $table->foreignId('ended_by_system_admin_id')->nullable()->constrained('system_admins')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('end_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['space_id', 'status']);
            $table->index(['live_thread_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_stream_sessions');
        Schema::dropIfExists('live_threads');
    }
};
