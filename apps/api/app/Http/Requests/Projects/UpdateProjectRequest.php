<?php

namespace App\Http\Requests\Projects;

use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:'.UploadLimits::MAX_TITLE_LENGTH],
            'product_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'product_description' => ['sometimes', 'nullable', 'string', 'max:'.UploadLimits::MAX_DESCRIPTION_LENGTH],
        ];
    }
}
