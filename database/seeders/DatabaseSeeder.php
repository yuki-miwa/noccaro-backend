<?php

namespace Database\Seeders;

use App\Models\MapWhisper;
use App\Models\Space;
use App\Models\SpaceMembership;
use App\Models\SpacePost;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $owner = User::query()->create([
            'email' => 'owner@noccaro.local',
            'display_name' => 'Noccaro Owner',
            'password' => Hash::make('password123'),
            'status' => 'active',
            'last_login_at' => now(),
        ]);

        $guest = User::query()->create([
            'email' => 'guest@noccaro.local',
            'display_name' => 'Noccaro Guest',
            'password' => Hash::make('password123'),
            'status' => 'active',
            'last_login_at' => now(),
        ]);

        $pendingUser = User::query()->create([
            'email' => 'pending@noccaro.local',
            'display_name' => 'Pending Guest',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);

        $space = Space::query()->create([
            'name' => 'Noccaro コミュニティ',
            'space_code' => 'NOC2026',
            'description' => '最初の開発用スペースです。',
            'join_policy' => 'approval_required',
            'status' => 'active',
            'created_by_user_id' => $owner->id,
        ]);

        $ownerMembership = SpaceMembership::query()->create([
            'space_id' => $space->id,
            'user_id' => $owner->id,
            'role' => 'primary_owner',
            'status' => 'active',
            'joined_at' => now(),
            'approved_at' => now(),
            'last_seen_at' => now(),
        ]);

        $guestMembership = SpaceMembership::query()->create([
            'space_id' => $space->id,
            'user_id' => $guest->id,
            'role' => 'guest',
            'status' => 'active',
            'joined_at' => now(),
            'approved_at' => now(),
            'approved_by_membership_id' => $ownerMembership->id,
            'last_seen_at' => now(),
        ]);

        SpaceMembership::query()->create([
            'space_id' => $space->id,
            'user_id' => $pendingUser->id,
            'role' => 'guest',
            'status' => 'pending',
        ]);

        SpacePost::query()->create([
            'space_id' => $space->id,
            'author_membership_id' => $ownerMembership->id,
            'title' => '最初のお知らせ',
            'body' => 'Laravel backend の seed データです。',
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);

        MapWhisper::query()->create([
            'space_id' => $space->id,
            'membership_id' => $guestMembership->id,
            'body' => '入口付近は静かです',
            'status' => 'active',
            'grid_key' => '35.68000:139.76700',
            'display_lat' => 35.6800,
            'display_lng' => 139.7670,
            'display_radius_m' => 72,
            'expires_at' => now()->addHours(2),
            'report_count' => 0,
        ]);
    }
}
