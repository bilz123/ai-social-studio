<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
        return [
            // No markup characters: names are rendered in emails and UIs.
            'name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^[^<>]+$/u'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'password' => [...PasswordRules::new(), 'confirmed'],
        ];
    }
}
