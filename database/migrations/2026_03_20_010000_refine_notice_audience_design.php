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
            $table->string('audience_type', 20)->default('all_members')->after('category');
            $table->dropIndex('space_posts_space_category_status_published_idx');
            $table->index(
                ['space_id', 'category', 'audience_type', 'status', 'published_at'],
                'space_posts_space_category_audience_status_published_idx',
            );
        });

        $targetedPostIds = DB::table('space_post_deliveries')->pluck('post_id')->all();

        if (! empty($targetedPostIds)) {
            DB::table('space_posts')
                ->whereIn('id', $targetedPostIds)
                ->update(['audience_type' => 'targeted_users']);
        }

        DB::table('space_posts')
            ->where('category', 'personal')
            ->update([
                'category' => 'operation',
                'audience_type' => 'targeted_users',
                'notify_members' => false,
            ]);
    }

    public function down(): void
    {
        $targetedOperationPostIds = DB::table('space_post_deliveries')
            ->join('space_posts', 'space_posts.id', '=', 'space_post_deliveries.post_id')
            ->where('space_posts.category', 'operation')
            ->pluck('space_posts.id')
            ->unique()
            ->all();

        if (! empty($targetedOperationPostIds)) {
            DB::table('space_posts')
                ->whereIn('id', $targetedOperationPostIds)
                ->update([
                    'category' => 'personal',
                    'notify_members' => false,
                ]);
        }

        Schema::table('space_posts', function (Blueprint $table) {
            $table->dropIndex('space_posts_space_category_audience_status_published_idx');
            $table->dropColumn('audience_type');
            $table->index(['space_id', 'category', 'status', 'published_at'], 'space_posts_space_category_status_published_idx');
        });
    }
};
