<?php

namespace App\Console\Commands;

use App\Models\SystemAdmin;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateSystemAdminCommand extends Command
{
    protected $signature = 'noccaro:create-system-admin
        {email : system admin email}
        {displayName : display name}
        {--password= : password to set; generated when omitted}';

    protected $description = 'Create or update a Noccaro system admin account';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $displayName = (string) $this->argument('displayName');
        $password = (string) ($this->option('password') ?: Str::password(20));

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill([
            'display_name' => $displayName,
            'password' => $password,
            'status' => 'active',
            'last_login_at' => $user->last_login_at,
        ]);
        $user->save();

        SystemAdmin::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['status' => 'active'],
        );

        $this->info('System admin account is ready.');
        $this->line('email: '.$email);
        $this->line('password: '.$password);
        $this->line('userId: '.$user->public_id);

        return self::SUCCESS;
    }
}
