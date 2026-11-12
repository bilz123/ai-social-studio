<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class ReorderImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'order' => ['required', 'array', 'min:1', 'max:'.(int) config('uploads.max_images_per_project')],
            'order.*' => ['required', 'string', 'size:26', 'distinct'],
        ];
    }
}
