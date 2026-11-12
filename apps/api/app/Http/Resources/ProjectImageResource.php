<?php

namespace App\Http\Resources;

use App\Models\ProjectImage;
use App\Services\Images\ImageUrlSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An image as the SPA sees it. Storage details (disk, object keys) are never exposed; the links
 * are temporary signed URLs that stop working after `url_expires_at`.
 */
class ProjectImageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ProjectImage $image */
        $image = $this->resource;
        $signer = app(ImageUrlSigner::class);

        return [
            'id' => $image->ulid,
            'name' => $image->original_name,
            'mime' => $image->mime,
            'bytes' => $image->bytes,
            'width' => $image->width,
            'height' => $image->height,
            'position' => $image->position,
            'status' => $image->status->value,
            'thumbnail_url' => $signer->thumbnail($image),
            'original_url' => $signer->original($image),
            'url_expires_at' => $signer->expiry()->toIso8601ZuluString(),
            'created_at' => $image->created_at?->toIso8601ZuluString(),
        ];
    }
}
