<?php

namespace App\Console\Commands;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Concerns\ProfileValidationRules;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Bootstraps the first super admin (public registration is disabled).
 */
class CreateAdminCommand extends Command
{
    use ProfileValidationRules;

    protected $signature = 'paygate:create-admin
        {--name= : Full name}
        {--username= : Login username}
        {--email= : Login email}';

    protected $description = 'Create a super admin user';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $username = Str::lower($this->option('username') ?: text('Username', required: true, hint: '3-50 characters: lowercase letters, numbers, dot, underscore or hyphen.'));
        $email = Str::lower($this->option('email') ?: text('Email', required: true));
        $password = password('Password', required: true, hint: 'At least 12 characters in production.');

        $validator = Validator::make(
            ['name' => $name, 'username' => $username, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'username' => $this->usernameRules(),
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
            'username' => $username,
            'email' => $email,
            'password' => Hash::make($password),
            'type' => UserType::Admin,
            'role_id' => Role::bySlug(SystemRoles::ADMIN_SUPER)->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        AuditLog::record('user.created', $user, new: [
            'username' => $user->username,
            'email' => $user->email,
            'role' => SystemRoles::ADMIN_SUPER,
            'via' => 'cli',
        ]);

        $this->components->info("Super admin {$username} ({$email}) created. Two-factor setup will be required at first login.");

        return self::SUCCESS;
    }
}
