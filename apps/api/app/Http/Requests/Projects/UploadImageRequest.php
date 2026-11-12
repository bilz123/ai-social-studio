<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only checks that a file arrived and is not over the size limit. What the file really is gets
 * decided by ImageProcessor from the file's bytes, not by rules on the name or declared type.
 */
class UploadImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'max:'.((int) config('uploads.max_megabytes') * 1024)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.required' => 'Choose an image to upload.',
            'image.file' => 'The upload was not a file.',
            'image.uploaded' => 'The upload did not finish. Try again.',
            'image.max' => 'This file is larger than '.(int) config('uploads.max_megabytes').' MB.',
        ];
    }
}
