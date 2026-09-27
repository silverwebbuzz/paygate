<?php

namespace App\Console\Commands;

use App\Auth\SystemRoles;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Bootstraps the first super admin (public registration is disabled).
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'paygate:create-admin
        {--name= : Full name}
        {--email= : Login email}';

    protected $description = 'Create a super admin user';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('Email', required: true);
        $password = password('Password', required: true, hint: 'At least 12 characters in production.');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'type' => UserType::Admin,
            'role_id' => Role::bySlug(SystemRoles::ADMIN_SUPER)->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        AuditLog::record('user.created', $user, new: [
            'email' => $user->email,
            'role' => SystemRoles::ADMIN_SUPER,
            'via' => 'cli',
        ]);

        $this->components->info("Super admin {$email} created. Two-factor setup will be required at first login.");

        return self::SUCCESS;
    }
}
