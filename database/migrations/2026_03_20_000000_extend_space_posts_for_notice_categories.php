<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('space_posts', function (Blueprint $table) {
            $table->string('category', 20)->default('owner')->after('space_id');
            $table->foreignId('created_by_system_admin_id')
                ->nullable()
                ->after('author_membership_id')
                ->constrained('system_admins')
                ->nullOnDelete();
            $table->index(['space_id', 'category', 'status', 'published_at'], 'space_posts_space_category_status_published_idx');
        });

        DB::table('space_posts')->update(['category' => 'owner']);

        Schema::create('space_post_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('space_posts')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['post_id', 'recipient_user_id']);
            $table->index(['recipient_user_id', 'post_id']);
        });

        Schema::create('space_post_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('space_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_post_reads');
        Schema::dropIfExists('space_post_deliveries');

        Schema::table('space_posts', function (Blueprint $table) {
            $table->dropIndex('space_posts_space_category_status_published_idx');
            $table->dropConstrainedForeignId('created_by_system_admin_id');
            $table->dropColumn('category');
        });
    }
};
