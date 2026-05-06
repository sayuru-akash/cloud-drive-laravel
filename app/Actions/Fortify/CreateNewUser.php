<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->after(function ($validator) use ($input): void {
            $domain = config('drive.internal_email_domain');
            if ($domain && ! str_ends_with(strtolower($input['email'] ?? ''), '@'.strtolower($domain))) {
                $validator->errors()->add('email', 'Use your workspace email address.');
            }
        })->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => User::query()->exists() ? 'member' : 'super_admin',
            'is_active' => true,
        ]);
    }
}
