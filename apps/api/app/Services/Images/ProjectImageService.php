<?php

namespace App\Services\Images;

use App\Enums\ImageStatus;
use App\Exceptions\ImageRejectedException;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Adds, removes and reorders the images of a project. The uploaded original is stored byte for
 * byte; the preview is a separate, re-encoded file. Rows are only written after both files exist,
 * and files are removed again if anything fails, so storage and database stay in step.
 */
class ProjectImageService
{
    /** Positions are parked this far out of the way while the order is rewritten (unique index). */
    private const PARKING_OFFSET = 1000;

    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly ImageStorage $storage,
    ) {}

    public function upload(User $user, Project $project, UploadedFile $file): ProjectImage
    {
        $limit = (int) config('uploads.max_images_per_project');

        if ($project->images()->count() >= $limit) {
            throw ImageRejectedException::limitReached($limit);
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw ImageRejectedException::corrupt();
        }

        $info = $this->processor->inspect($path);
        $thumbnail = $this->processor->thumbnail($path, $info);

        $ulid = (string) Str::ulid();
        $originalKey = $this->storage->originalKey($user, $project, $ulid, $info->extension);
        $thumbnailKey = $this->storage->thumbnailKey($user, $project, $ulid);

        $this->storage->putFile($originalKey, $path);

        try {
            $this->storage->putContents($thumbnailKey, $thumbnail);

            return $this->record($project, $info, [
                'ulid' => $ulid,
                'disk' => $this->storage->diskName(),
                'object_key' => $originalKey,
                'thumbnail_key' => $thumbnailKey,
                'thumbnail_bytes' => strlen($thumbnail),
                'original_name' => $this->displayName($file, $info->extension),
            ], $limit);
        } catch (Throwable $e) {
            $this->storage->delete([$originalKey, $thumbnailKey]);

            throw $e;
        }
    }

    public function delete(Project $project, ProjectImage $image): void
    {
        DB::transaction(function () use ($project, $image): void {
            $this->lock($project);

            $image->delete();
            $this->resequence($project);
            $project->touch();
        });

        $this->storage->delete([$image->object_key, $image->thumbnail_key]);
    }

    /**
     * @param  list<string>  $order  every image id of the project, in the wanted order
     */
    public function reorder(Project $project, array $order): void
    {
        DB::transaction(function () use ($project, $order): void {
            $this->lock($project);

            /** @var array<string, int> $current image public id => internal id */
            $current = ProjectImage::query()->where('project_id', $project->getKey())->pluck('id', 'ulid')->all();

            $given = array_values(array_unique($order));

            if (count($given) !== count($order)
                || count($given) !== count($current)
                || array_diff($given, array_keys($current)) !== []) {
                throw ValidationException::withMessages([
                    'order' => ['The order must list every image of this project exactly once.'],
                ]);
            }

            $this->applyOrder($project, array_map(static fn (string $ulid): int => $current[$ulid], $order));
            $project->touch();
        });
    }

    /** Deletes every image row and file of a project (used when the project is deleted). */
    public function purgeProject(User $user, Project $project): void
    {
        DB::transaction(function () use ($project): void {
            ProjectImage::query()->where('project_id', $project->getKey())->delete();
        });

        $this->storage->deleteProjectFiles($user, $project);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(Project $project, ImageInfo $info, array $attributes, int $limit): ProjectImage
    {
        return DB::transaction(function () use ($project, $info, $attributes, $limit): ProjectImage {
            $this->lock($project);

            // Re-checked under the lock: two uploads at once must not slip past the limit or
            // create the same position.
            $images = ProjectImage::query()->where('project_id', $project->getKey());

            if ((clone $images)->count() >= $limit) {
                throw ImageRejectedException::limitReached($limit);
            }

            if ((clone $images)->where('sha256', $info->sha256)->exists()) {
                throw ImageRejectedException::duplicate();
            }

            $image = new ProjectImage;
            $image->forceFill($attributes + [
                'user_id' => $project->user_id,
                'project_id' => $project->getKey(),
                'mime' => $info->mime,
                'bytes' => $info->bytes,
                'sha256' => $info->sha256,
                'width' => $info->width,
                'height' => $info->height,
                'position' => ((int) (clone $images)->max('position')) + 1,
                'role' => 'other',
                'status' => ImageStatus::Validated,
            ])->save();

            $project->touch();

            return $image;
        });
    }

    private function lock(Project $project): void
    {
        Project::query()->whereKey($project->getKey())->lockForUpdate()->first();
    }

    private function resequence(Project $project): void
    {
        /** @var list<int> $ids */
        $ids = ProjectImage::query()->where('project_id', $project->getKey())->orderBy('position')->pluck('id')->all();

        $this->applyOrder($project, $ids);
    }

    /** @param  list<int>  $ids  internal ids in the wanted order; positions become 1..n */
    private function applyOrder(Project $project, array $ids): void
    {
        ProjectImage::query()->where('project_id', $project->getKey())->increment('position', self::PARKING_OFFSET);

        foreach ($ids as $index => $id) {
            ProjectImage::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }

    /** The name shown in the interface: the browser's file name, cleaned, never used as a path. */
    private function displayName(UploadedFile $file, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        if (! is_string($name) || trim($name) === '') {
            return 'image.'.$extension;
        }

        return Str::limit(trim($name), 200, '');
    }
}
