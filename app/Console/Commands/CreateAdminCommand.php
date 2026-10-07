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
        {--username= : Login username}';

    protected $description = 'Create a super admin user';

    public function handle(): int
    {
        $username = Str::lower($this->option('username') ?: text('Username', required: true, hint: '3-50 characters: lowercase letters, numbers, dot, underscore or hyphen.'));
        $password = password('Password', required: true, hint: 'At least 6 characters.');

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            [
                'username' => $this->usernameRules(),
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
            'name' => $username,
            'username' => $username,
            'email' => null,
            'password' => Hash::make($password),
            'type' => UserType::Admin,
            'role_id' => Role::bySlug(SystemRoles::ADMIN_SUPER)->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        AuditLog::record('user.created', $user, new: [
            'username' => $user->username,
            'role' => SystemRoles::ADMIN_SUPER,
            'via' => 'cli',
        ]);

        $this->components->info("Super admin {$username} created. Two-factor setup will be required at first login.");

        return self::SUCCESS;
    }
}
