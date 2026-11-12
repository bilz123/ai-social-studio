<?php

namespace App\Http\Requests\Social;

use Illuminate\Foundation\Http\FormRequest;

/** Values the provider put on the redirect URL; the SPA forwards them unchanged. */
class CallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:2048'],
            // Set by the provider when the user cancels or access is denied.
            'error' => ['nullable', 'string', 'max:255'],
        ];
    }
}
