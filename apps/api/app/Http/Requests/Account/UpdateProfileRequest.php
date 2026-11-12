<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $changingEmail = is_string($this->input('email')) && $this->input('email') !== $user?->email;

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120', 'regex:/^[^<>]+$/u'],
            'email' => [
                'sometimes', 'required', 'string', 'email:rfc', 'max:190',
                Rule::unique('users', 'email')->ignore($user?->getKey()),
            ],
            // Changing the sign-in email is a takeover vector, so it needs the current password.
            'current_password' => [Rule::requiredIf($changingEmail), 'string', 'current_password'],
        ];
    }
}
