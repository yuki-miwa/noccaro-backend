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
        Schema::create('system_admins', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('system_admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('system_admin_id')->nullable()->constrained('system_admins')->nullOnDelete();
            $table->string('action', 50);
            $table->string('entity_type', 30);
            $table->string('entity_public_id', 100);
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at']);
            $table->index(['entity_type', 'entity_public_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_admin_audit_logs');
        Schema::dropIfExists('system_admins');
    }
};
