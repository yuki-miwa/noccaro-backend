<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_thread_schedules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('scheduled');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->decimal('area_center_lat', 10, 7);
            $table->decimal('area_center_lng', 10, 7);
            $table->unsignedInteger('area_radius_m');
            $table->foreignId('created_by_membership_id')->nullable()->constrained('space_memberships')->nullOnDelete();
            $table->foreignId('updated_by_membership_id')->nullable()->constrained('space_memberships')->nullOnDelete();
            $table->foreignId('activated_live_thread_id')->nullable()->constrained('live_threads')->nullOnDelete();
            $table->timestamps();

            $table->unique('space_id');
            $table->index(['status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_thread_schedules');
    }
};
