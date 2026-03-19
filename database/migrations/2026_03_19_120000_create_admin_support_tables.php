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
        Schema::create('member_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->foreignId('target_membership_id')->constrained('space_memberships')->cascadeOnDelete();
            $table->foreignId('acted_by_membership_id')->constrained('space_memberships')->cascadeOnDelete();
            $table->string('action_type', 30);
            $table->text('reason')->nullable();
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('related_report_id')->nullable()->constrained('content_reports')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['space_id', 'created_at']);
            $table->index(['target_membership_id', 'action_type']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by_membership_id')->constrained('space_memberships')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body');
            $table->string('target_scope', 20)->default('all_active_members');
            $table->string('status', 20)->default('queued');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['space_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('member_actions');
    }
};
