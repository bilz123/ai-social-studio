<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Services\Images\ImageUrlSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Project $project */
        $project = $this->resource;

        return [
            'id' => $project->ulid,
            'title' => $project->title,
            'product_name' => $project->product_name,
            'product_description' => $project->product_description,
            'status' => $project->status->value,
            'image_count' => $this->whenCounted('images'),
            'cover_thumbnail_url' => $this->when(
                $project->relationLoaded('coverImage'),
                fn (): ?string => $project->coverImage !== null
                    ? app(ImageUrlSigner::class)->thumbnail($project->coverImage)
                    : null,
            ),
            'images' => ProjectImageResource::collection($this->whenLoaded('images')),
            'created_at' => $project->created_at?->toIso8601ZuluString(),
            'updated_at' => $project->updated_at?->toIso8601ZuluString(),
        ];
    }
}
