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
        Schema::create('space_creation_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('future_primary_owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('requested_space_name', 120);
            $table->string('requested_space_code', 20);
            $table->string('requested_join_policy', 20);
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_space_id')->nullable()->constrained('spaces')->nullOnDelete();
            $table->foreignId('reviewed_by_system_admin_id')->nullable()->constrained('system_admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('rejection_visible_until')->nullable();
            $table->timestamps();

            $table->index(['requester_user_id', 'status', 'created_at']);
            $table->index(['requested_space_code', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('space_creation_requests');
    }
};
