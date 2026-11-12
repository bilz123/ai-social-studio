<?php

namespace App\Http\Requests\Social;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSocialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
        ];
    }
}
