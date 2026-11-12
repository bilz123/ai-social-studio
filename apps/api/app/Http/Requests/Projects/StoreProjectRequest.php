<?php

namespace App\Http\Requests\Projects;

use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.UploadLimits::MAX_TITLE_LENGTH],
            'product_name' => ['nullable', 'string', 'max:160'],
            // Drafts may be saved before the description is written.
            'product_description' => ['nullable', 'string', 'max:'.UploadLimits::MAX_DESCRIPTION_LENGTH],
        ];
    }
}
