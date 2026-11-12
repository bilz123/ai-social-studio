<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AuditLog $log */
        $log = $this->resource;

        return [
            'action' => $log->action,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
            'created_at' => $log->created_at?->toIso8601ZuluString(),
        ];
    }
}
