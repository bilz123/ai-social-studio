<?php

namespace App\Http\Requests\Account;

use App\Support\Auth\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => [...PasswordRules::new(), 'confirmed', 'different:current_password'],
        ];
    }
}
