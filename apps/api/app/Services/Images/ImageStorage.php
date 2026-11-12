<?php

namespace App\Services\Images;

use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Everything that touches the private uploads disk. Object keys are built from random public ids
 * only (never from user input), as `users/{user}/projects/{project}/{original|thumb}/{image}.{ext}`,
 * so a user's files can be found, and removed, by prefix.
 */
class ImageStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) config('uploads.disk');
    }

    public function originalKey(User $user, Project $project, string $imageUlid, string $extension): string
    {
        return $this->projectDirectory($user, $project)."/original/{$imageUlid}.{$extension}";
    }

    public function thumbnailKey(User $user, Project $project, string $imageUlid): string
    {
        return $this->projectDirectory($user, $project)."/thumb/{$imageUlid}.jpg";
    }

    /** Streams a file from disk to storage without loading it into memory or changing it. */
    public function putFile(string $key, string $path): void
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new \RuntimeException('The uploaded file could not be read.');
        }

        try {
            $this->disk()->put($key, $stream, ['visibility' => 'private']);
        } finally {
            fclose($stream);
        }
    }

    public function putContents(string $key, string $contents): void
    {
        $this->disk()->put($key, $contents, ['visibility' => 'private']);
    }

    /** @return resource|null */
    public function readStream(string $key)
    {
        try {
            $stream = $this->disk()->readStream($key);
        } catch (Throwable) {
            return null;
        }

        return is_resource($stream) ? $stream : null;
    }

    /**
     * Removes files; a failure is reported but never thrown, because the database is the source of
     * truth and an orphaned file is harmless (it is private and unreachable).
     *
     * @param  list<string|null>  $keys
     */
    public function delete(array $keys): void
    {
        $keys = array_values(array_filter($keys, static fn (?string $key): bool => $key !== null && $key !== ''));

        if ($keys === []) {
            return;
        }

        try {
            $this->disk()->delete($keys);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function deleteProjectFiles(User $user, Project $project): void
    {
        $this->deleteDirectory($this->projectDirectory($user, $project));
    }

    public function deleteUserFiles(User $user): void
    {
        $this->deleteDirectory($this->userDirectory($user));
    }

    private function deleteDirectory(string $directory): void
    {
        try {
            $this->disk()->deleteDirectory($directory);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function userDirectory(User $user): string
    {
        return 'users/'.$user->ulid;
    }

    private function projectDirectory(User $user, Project $project): string
    {
        return $this->userDirectory($user).'/projects/'.$project->ulid;
    }
}
