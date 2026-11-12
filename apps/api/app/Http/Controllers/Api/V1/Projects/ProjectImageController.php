<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Concerns\FindsOwnedProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ReorderImagesRequest;
use App\Http\Requests\Projects\UploadImageRequest;
use App\Http\Resources\ProjectImageResource;
use App\Services\AuditLogger;
use App\Services\Images\ProjectImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProjectImageController extends Controller
{
    use FindsOwnedProject;

    /** One image per request, so the SPA can show progress and errors per file. */
    public function store(UploadImageRequest $request, string $project, ProjectImageService $images, AuditLogger $audit): JsonResponse
    {
        $model = $this->ownedProject($request, $project);

        $image = $images->upload($request->user(), $model, $request->file('image'));

        $audit->record('project.image.uploaded', $request->user(), $image, [
            'project' => $model->ulid,
            'mime' => $image->mime,
            'bytes' => $image->bytes,
        ], $request);

        return (new ProjectImageResource($image))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $project, string $image, ProjectImageService $images, AuditLogger $audit): Response
    {
        $model = $this->ownedProject($request, $project);
        $target = $model->images()->where('ulid', $image)->firstOrFail();

        $images->delete($model, $target);

        $audit->record('project.image.deleted', $request->user(), $target, ['project' => $model->ulid], $request);

        return response()->noContent();
    }

    /** Body: {"order": ["<image id>", …]} listing every image of the project once. */
    public function reorder(ReorderImagesRequest $request, string $project, ProjectImageService $images, AuditLogger $audit): AnonymousResourceCollection
    {
        $model = $this->ownedProject($request, $project);

        /** @var list<string> $order */
        $order = array_values($request->validated('order'));

        $images->reorder($model, $order);

        $audit->record('project.images.reordered', $request->user(), $model, ['count' => count($order)], $request);

        return ProjectImageResource::collection($model->images()->get());
    }
}
