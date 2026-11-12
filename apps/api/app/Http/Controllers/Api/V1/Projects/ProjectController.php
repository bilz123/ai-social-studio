<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Concerns\FindsOwnedProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\AuditLogger;
use App\Services\Images\ProjectImageService;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProjectController extends Controller
{
    use FindsOwnedProject;

    /** The caller's projects, most recently changed first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $projects = $request->user()->projects()
            ->with('coverImage')
            ->withCount('images')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(12);

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validated();
        $title = (string) $data['title'];

        $project = new Project;
        $project->fill([
            'title' => $title,
            'product_name' => $data['product_name'] ?? $title,
            'product_description' => $data['product_description'] ?? '',
        ]);
        $project->forceFill([
            'user_id' => $request->user()->getKey(),
            'status' => ProjectStatus::Draft,
        ])->save();

        $audit->record('project.created', $request->user(), $project, [], $request);

        return $this->respond($project->refresh())->setStatusCode(201);
    }

    public function show(Request $request, string $project): ProjectResource
    {
        return $this->present($this->ownedProject($request, $project))
            ->additional(['meta' => ['limits' => UploadLimits::toArray()]]);
    }

    /** Saves the draft: any subset of the name and description. */
    public function update(UpdateProjectRequest $request, string $project, AuditLogger $audit): ProjectResource
    {
        $model = $this->ownedProject($request, $project);
        $data = $request->validated();

        if (array_key_exists('product_description', $data)) {
            $data['product_description'] ??= '';
        }

        if (array_key_exists('product_name', $data)) {
            $data['product_name'] ??= $data['title'] ?? $model->title;
        } elseif (array_key_exists('title', $data) && $model->product_name === $model->title) {
            // The product name still just mirrors the title: keep following it when it is renamed.
            $data['product_name'] = $data['title'];
        }

        $model->fill($data)->save();

        $audit->record('project.updated', $request->user(), $model, ['fields' => array_keys($data)], $request);

        return $this->present($model);
    }

    /** Deletes the project and, immediately, every image file it holds. */
    public function destroy(Request $request, string $project, ProjectImageService $images, AuditLogger $audit): Response
    {
        $model = $this->ownedProject($request, $project);

        $images->purgeProject($request->user(), $model);
        $model->delete();

        $audit->record('project.deleted', $request->user(), $model, [], $request);

        return response()->noContent();
    }

    private function respond(Project $project): JsonResponse
    {
        return $this->present($project)->response();
    }

    private function present(Project $project): ProjectResource
    {
        return new ProjectResource($project->load('images')->loadCount('images'));
    }
}
