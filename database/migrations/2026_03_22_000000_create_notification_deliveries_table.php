<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('space_posts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_push_device_id')->nullable()->constrained('user_push_devices')->nullOnDelete();
            $table->string('platform', 20);
            $table->string('push_token', 2048);
            $table->string('transport', 30)->default('fcm_http_v1');
            $table->string('status', 30)->default('queued');
            $table->string('response_code', 50)->nullable();
            $table->text('response_body')->nullable();
            $table->string('fcm_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'push_token']);
            $table->index(['notification_id', 'status']);
            $table->index(['post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
