<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SecurityAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'moshkelti:create-admin {email : New administrator email}';

    protected $description = 'Create a new verified administrator, requiring TOTP enrollment on first login';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $name = $this->ask('Administrator name');
        $password = $this->secret('New password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm password');
        $validator = Validator::make(['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            ['email' => ['required', 'email', 'max:254', 'unique:users,email'], 'name' => ['required', 'string', 'max:80', 'regex:/\A[^\p{C}<>]+\z/u'],
                'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed', 'not_regex:/\x00/']]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        app(SecurityAudit::class)->record('operator.admin_created', $user, $user);
        $this->info('Administrator created. Sign in, confirm your password, and enroll an authenticator. No default password exists.');

        return self::SUCCESS;
    }
}
