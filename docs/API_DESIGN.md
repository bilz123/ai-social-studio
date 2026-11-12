# API Design

**Status:** Draft v0.1 · Design only
Related: [PROJECT_ARCHITECTURE](PROJECT_ARCHITECTURE.md) · [DATABASE_DESIGN](DATABASE_DESIGN.md) · [AI_MICROSERVICES](AI_MICROSERVICES.md)

Three API surfaces:

1. **Public API** — React SPA ↔ Laravel (`/api/v1/*`). §1–9 (realtime events in §9).
2. **Internal AI API** — Laravel → FastAPI (private network). §10.
3. **Inbound webhooks/callbacks** — AI → Laravel, Meta → Laravel. §11.

The OpenAPI source of truth will live in `contracts/` and the TypeScript client is generated from it.

## 1. Conventions

| Topic | Rule |
|---|---|
| Base URL | `https://api.<domain>/api/v1` |
| Auth | Sanctum SPA cookie. Client first calls `GET /sanctum/csrf-cookie`, then sends `X-XSRF-TOKEN` on mutations |
| IDs | ULIDs in paths and bodies (never DB ints) |
| Tenancy | All studio resources are nested: `/workspaces/{ws}/…`. Cross-tenant IDs → `404` |
| Format | JSON, `snake_case` keys, ISO-8601 UTC timestamps |
| Pagination | Cursor: `?limit=20&cursor=…` → `{ data: [], meta: { next_cursor } }` |
| Idempotency | `Idempotency-Key: <uuid>` required on `POST …/generations`, `POST …/publications`; replays return the original response |
| Errors | RFC 9457 `application/problem+json` (see §11) |
| Rate limits | Auth: 5/min per IP+email · General: 120/min per user · Generation start: 10/hour per workspace (plan-dependent) · headers `RateLimit-*`, `Retry-After` |
| Versioning | URL major version; additive changes only inside v1 |
| Async work | `202 Accepted` + resource with `status`; progress via realtime events or polling |

## 2. Auth & account

| Method | Path | Notes |
|---|---|---|
| GET | `/sanctum/csrf-cookie` | Sets XSRF cookie |
| POST | `/auth/register` | `{name,email,password,password_confirmation}` → creates user + default workspace; sends verification email |
| POST | `/auth/login` | `{email,password}`; throttled |
| POST | `/auth/logout` | |
| GET | `/auth/me` | User + workspaces + current role |
| POST | `/auth/forgot-password`, `/auth/reset-password` | |
| GET | `/auth/verify-email/{id}/{hash}` | Signed |
| POST | `/auth/two-factor/*` | Phase 6 |

## 3. Workspaces

| Method | Path |
|---|---|
| GET/POST | `/workspaces` |
| GET/PATCH | `/workspaces/{ws}` |
| GET | `/workspaces/{ws}/members` |
| POST/PATCH/DELETE | `/workspaces/{ws}/members[/{user}]` (invite by email, change role, remove) |
| GET | `/workspaces/{ws}/usage` | Quota + usage for the month |

## 4. Media (direct-to-S3 uploads)

**Step 1 — request upload slots**

`POST /workspaces/{ws}/media/uploads`
```json
{
  "files": [
    { "filename": "front.jpg", "mime": "image/jpeg", "bytes": 2481923, "sha256": "…optional…" },
    { "filename": "side.png",  "mime": "image/png",  "bytes": 1893321 }
  ]
}
```
→ `201`
```json
{
  "data": [
    {
      "media_id": "01J9…",
      "method": "PUT",
      "url": "https://s3…/uploads/ws/01J9…?X-Amz-Signature=…",
      "headers": { "Content-Type": "image/jpeg" },
      "expires_at": "2026-11-12T19:30:00Z",
      "multipart": null
    }
  ]
}
```
Server limits: JPEG/PNG/WebP, ≤ 15 MB each, ≤ 10 files per request, min 512 px short side, max 8000 px (values configurable). Files > 15 MB are rejected; multipart is reserved for future video/audio uploads.

**Step 2 — confirm**

`POST /workspaces/{ws}/media/{media}/complete` → server HEAD-checks the object, queues validation (magic bytes, decode, EXIF strip, dimensions, optional AV scan) → `media.status = uploaded → validated | rejected`.

| Method | Path | Notes |
|---|---|---|
| GET | `/workspaces/{ws}/media/{media}` | Metadata + short-lived `preview_url` |
| DELETE | `/workspaces/{ws}/media/{media}` | Soft-deletes a source image and removes it from campaigns; final videos and publication history are unaffected |

## 5. Campaigns

| Method | Path | Body / notes |
|---|---|---|
| GET | `/workspaces/{ws}/campaigns` | Filters: `status`, `q` |
| POST | `/workspaces/{ws}/campaigns` | `{title, product_name, product_description, language, tone?, brand_notes?, target_audience?}` |
| GET/PATCH/DELETE | `/workspaces/{ws}/campaigns/{c}` | |
| PUT | `/workspaces/{ws}/campaigns/{c}/assets` | `{assets:[{media_id, role?, note?}]}` — ordered list, replaces set (1–10, validated images only) |

Validation highlights: `product_description` 20–4000 chars; moderation check runs server-side before any generation spend.

## 6. Generation & review

### Start generation
`POST /workspaces/{ws}/campaigns/{c}/generations`  (`Idempotency-Key` required)
```json
{
  "style_preset": "cinematic_dolly",
  "aspect_ratio": "9:16",
  "duration_seconds": 10,
  "music": { "mode": "library", "track_id": "trk_calm_01" },
  "notes": "Warm golden-hour feel, emphasize the leather texture"
}
```
→ `202`
```json
{
  "data": {
    "id": "01JA…", "campaign_id": "01J9…", "version": 1,
    "status": "queued", "stage": null, "progress": 0,
    "duration_seconds": 10, "aspect_ratio": "9:16",
    "created_at": "2026-11-12T18:31:00Z"
  }
}
```
MVP accepts only `duration_seconds = 10`; the field exists so other durations are additive.

| Method | Path | Notes |
|---|---|---|
| GET | `/workspaces/{ws}/campaigns/{c}/generations` | Versions, newest first |
| GET | `/workspaces/{ws}/generations/{g}` | `status, stage, progress, storyboard?, error?, video?` |
| POST | `/workspaces/{ws}/generations/{g}/cancel` | Only while not `ready_for_review`/terminal |
| POST | `/workspaces/{ws}/generations/{g}/regenerate` | `{notes?, style_preset?}` → new generation (version+1) |
| GET | `/workspaces/{ws}/videos/{v}/playback` | `{ url, expires_at, poster_url }` (presigned, ~15 min) |
| GET | `/workspaces/{ws}/videos/{v}/download` | Presigned download (`Content-Disposition: attachment`) |

### Review / approval
`POST /workspaces/{ws}/videos/{v}/reviews`
```json
{ "decision": "approved", "comment": "Looks great" }
```
`decision ∈ approved | rejected | changes_requested`. Roles: `owner/admin/editor`. Returns the review; `GET /videos/{v}` includes `approval_state`.

## 7. Social connections

### 7.0 Phase 4 as built (user-scoped; supersedes the workspace routes below until workspaces exist)

All routes need a session (`auth:sanctum`) and the `social-accounts.manage` permission (the `member` role has it). Responses use the usual `{data: …}` resource wrapper; errors are problem+json (codes in [SOCIAL_MEDIA_INTEGRATION §0](SOCIAL_MEDIA_INTEGRATION.md)).

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/social/platforms` | `[{id: facebook|instagram|youtube, name, provider, configured, supports_refresh, required_permissions, connected_count, missing_configuration?}]`. `missing_configuration` (env var names) is omitted in production. |
| POST | `/api/v1/social/{platform}/connect` | `503 integration_not_configured` when credentials are missing. Else `{authorization_url, expires_at}`; the SPA navigates there. Throttle `social-connect` (10/min). |
| POST | `/api/v1/social/{platform}/callback` | Body `{state, code?, error?}` (what the provider put on the SPA redirect). Validates and burns the state, exchanges the code, stores accounts. Returns the connected accounts. |
| GET | `/api/v1/social/accounts` | The user's accounts: `id, platform, provider, name, username, avatar_url, status, status_reason, is_enabled, capabilities, profile, permissions{granted,missing}, token{present,expires_at,has_refresh_token,last_error}, connected_at, last_checked_at, last_publish_at`. Never any token value. |
| PATCH | `/api/v1/social/accounts/{id}` | `{is_enabled}`; owner only. |
| POST | `/api/v1/social/accounts/{id}/check` | Renews the token if due, re-reads profile and permissions. `409` problem when the provider no longer accepts the connection (account becomes `needs_reconnect`). |
| DELETE | `/api/v1/social/accounts/{id}` | Revokes at the provider where possible (Google), deletes credentials, soft-deletes the account. `204`. |


| Method | Path | Notes |
|---|---|---|
| GET | `/workspaces/{ws}/social/providers` | `[{provider:"meta", platforms:["facebook_page","instagram_business"]},{provider:"google", platforms:["youtube_channel"]}]` |
| POST | `/workspaces/{ws}/social/{provider}/connect` | → `{ authorize_url }`; SPA navigates the browser to it |
| GET | `/social/{provider}/callback` | **Top-level browser redirect target** (Laravel web route). Validates `state`, exchanges code, stores tokens, then redirects to `https://app.<domain>/app/connections?connected=meta` |
| GET | `/workspaces/{ws}/social/connections` | Status, scopes, token health |
| POST | `/workspaces/{ws}/social/connections/{conn}/sync` | Re-discover pages / IG accounts / channels |
| DELETE | `/workspaces/{ws}/social/connections/{conn}` | Revoke at provider + purge tokens |
| GET | `/workspaces/{ws}/social/accounts` | Destinations: `id, platform, name, username, avatar_url, is_enabled, status, capabilities` |
| PATCH | `/workspaces/{ws}/social/accounts/{acct}` | `{is_enabled}` |

Details: [SOCIAL_MEDIA_INTEGRATION](SOCIAL_MEDIA_INTEGRATION.md).

## 8. Publishing

`POST /workspaces/{ws}/campaigns/{c}/publications`  (`Idempotency-Key` required)
```json
{
  "video_id": "01JB…",
  "caption": "Meet the new weekender bag…",
  "hashtags": ["leatherbag", "handmade"],
  "scheduled_at": null,
  "targets": [
    { "social_account_id": "01JC…", "options": {} },
    { "social_account_id": "01JD…", "options": { "share_to_feed": true } },
    { "social_account_id": "01JE…", "options": { "title": "Handmade Weekender Bag", "privacy": "public", "made_for_kids": false, "tags": ["leather"], "contains_synthetic_media": true } }
  ]
}
```
Server checks: video's latest review is `approved`; targets belong to the workspace and are `active`; per-platform constraints (title/caption length, duration, aspect). Failure → `422` with per-target errors; **no partial creation**.
→ `202`
```json
{ "data": { "id": "01JF…", "status": "publishing", "targets": [ { "id":"…", "status":"pending", "platform":"youtube_channel" } ] } }
```

| Method | Path | Notes |
|---|---|---|
| GET | `/workspaces/{ws}/publications` | |
| GET | `/workspaces/{ws}/publications/{p}` | Targets with `status`, `external_url`, `last_error_code/message` |
| POST | `/workspaces/{ws}/publications/{p}/targets/{t}/retry` | Only `failed` + retryable |
| POST | `/workspaces/{ws}/publications/{p}/cancel` | Cancels targets not yet started |
| PATCH | `/workspaces/{ws}/publications/{p}` | Edit caption/schedule while `draft|scheduled` |

### AI-assisted copy (SEO)
`POST /workspaces/{ws}/campaigns/{c}/copy-suggestions` → Laravel calls AI `/internal/v1/copy/suggest` (sync, ≤ 15 s timeout) and returns per-platform `{title, caption, hashtags[], tags[]}`.

## 9. Realtime events (Reverb)

Private channel `private-workspace.{ws}` (authorized by membership).

| Event | Payload |
|---|---|
| `generation.progressed` | `{generation_id, stage, progress}` |
| `generation.ready` | `{generation_id, video_id}` |
| `generation.failed` | `{generation_id, error_code, message}` |
| `publication.target_updated` | `{publication_id, target_id, status, external_url?, error?}` |
| `social.connection_updated` | `{connection_id, status}` |

Clients also poll `GET /generations/{g}` every 5–10 s while the tab is visible as a fallback.

## 10. Internal AI API (Laravel → FastAPI)

Private network only. Base: `http://ai-api:8000/internal/v1`.

**Auth headers (all requests):**
```
X-Service-Id: laravel
X-Timestamp: 1731436260                       # unix seconds, ±300 s accepted
X-Signature: sha256=<hex HMAC_SHA256(secret, timestamp + "." + method + "." + path + "." + sha256(body))>
X-Request-Id: <uuid>                           # propagated into logs
```

### `POST /generations` → `202`
```json
{
  "generation_id": "01JA…",
  "callback_url": "https://api.<domain>/internal/webhooks/ai/generation-events",
  "idempotency_key": "01JA…",
  "brief": {
    "product_name": "Weekender Bag",
    "product_description": "…",
    "language": "en", "tone": "premium", "audience": "…", "brand_notes": "…"
  },
  "images": [
    { "media_id": "01J9…", "bucket": "studio", "key": "uploads/ws/01J9…", "role": "hero", "width": 3000, "height": 2000 }
  ],
  "output": {
    "bucket": "studio", "prefix": "work/ws/01JA…/",
    "final_key": "final/ws/01JA…/master.mp4",
    "thumbnail_key": "final/ws/01JA…/thumb.jpg",
    "aspect_ratio": "9:16", "duration_ms": 10000, "fps": 30, "width": 1080, "height": 1920
  },
  "style_preset": "cinematic_dolly",
  "music": { "mode": "library", "key": "library/music/calm_01.mp3" },
  "notes": "…",
  "limits": { "max_cost_cents": 150, "max_attempts_per_clip": 2 }
}
```
→ `{ "ai_job_id": "job_8f2…", "status": "queued" }` (same response on replay with same `idempotency_key`).

### Other endpoints
| Method | Path | Purpose |
|---|---|---|
| GET | `/generations/{ai_job_id}` | Status for polling fallback: `{status, stage, progress, clips:[…], error?}` |
| POST | `/generations/{ai_job_id}/cancel` | Cooperative cancel; refunds nothing already spent |
| POST | `/copy/suggest` | Sync: brief + platforms → titles/captions/hashtags |
| POST | `/moderation/check` | Sync: brief text + image keys → `{allowed, reasons[]}` |
| GET | `/healthz`, `/readyz` | Liveness / dependency readiness |

Laravel client rules: timeouts (connect 2 s, read 10 s for sync endpoints), retry only idempotent calls (3×, exponential backoff + jitter), circuit breaker after repeated failures (generations stay `queued` and `DispatchGenerationToAi` retries).

## 11. Inbound webhooks

### 11.1 AI → Laravel: `POST /internal/webhooks/ai/generation-events`
Headers: `X-Timestamp`, `X-Event-Id` (unique), `X-Signature` (HMAC as above, separate secret).
```json
{
  "event_id": "evt_01JA…_0007",
  "type": "generation.progress",          // progress | clip.completed | completed | failed | canceled
  "generation_id": "01JA…",
  "ai_job_id": "job_8f2…",
  "occurred_at": "2026-11-12T18:34:10Z",
  "stage": "generating",
  "progress": 55,
  "storyboard": null,
  "clips": [{ "idx": 0, "status": "succeeded", "key": "work/ws/01JA…/clip0.mp4", "qa_score": 0.91 }],
  "result": null,
  "cost_cents": 42
}
```
`completed` carries `result: { video_key, thumbnail_key, width, height, duration_ms, fps, video_codec, audio_codec, bytes }`. `failed` carries `error: { code, message, retryable }`.

Laravel handler: verify signature + timestamp → insert into `inbound_events` (unique `(source,event_id)`; duplicate → `200` no-op) → lock generation row → ignore events older than current state → apply → broadcast. Responds `2xx` quickly; heavy work is queued. AI retries non-2xx with backoff for up to 24 h.

### 11.2 Meta callbacks (public, signed by Meta)
| Path | Purpose |
|---|---|
| `POST /social/meta/deauthorize` | User removed the app → mark connection `revoked`, purge tokens (verifies `signed_request`) |
| `POST /social/meta/data-deletion` | Returns `{ url, confirmation_code }`; queues data deletion; status page `GET /social/meta/data-deletion/{code}` |

## 12. Error format

```json
{
  "type": "https://docs.<domain>/errors/publication-not-approved",
  "title": "Video is not approved",
  "status": 422,
  "detail": "Approve the video before publishing.",
  "code": "publication_not_approved",
  "errors": { "targets.1.options.title": ["Title must be 100 characters or fewer."] },
  "request_id": "9b2f…"
}
```

Stable machine codes (non-exhaustive): `validation_failed`, `unauthenticated`, `forbidden`, `not_found`, `quota_exceeded`, `rate_limited`, `media_rejected`, `generation_in_progress`, `generation_failed`, `publication_not_approved`, `social_needs_reconnect`, `social_rate_limited`, `social_platform_error`, `idempotency_conflict`.

## 13. Security notes specific to the API

- Presigned URLs and playback URLs are bearer secrets: short TTL, never logged, never stored.
- `connect` returns the authorize URL; `state` is generated server-side and bound to session + workspace + provider.
- The `/internal/*` routes are not exposed through the public ingress except the two webhook paths, which are additionally IP-restricted where possible and always signature-verified.
- Mass-assignment protection via `FormRequest::validated()` only; `Resource` classes control output (no tokens, ever).

## Phase 2 implementation notes (authentication)

- Sanctum **SPA cookie mode**: `GET /api/sanctum/csrf-cookie`, then send `X-XSRF-TOKEN` with every
  state-changing request. Register and login refuse requests without a session (no token flows).
- Errors are RFC 9457 `application/problem+json` with `code`: `validation_failed`, `unauthenticated`,
  `forbidden`, `rate_limited`, `csrf_token_mismatch`.
- Rate limits (per minute unless noted): register 5 (+30/h per IP); login 5 per email+IP, 20 per IP;
  forgot-password 3 per email+IP (+20/h); reset-password 5 per email+IP; verification 6; profile
  update, password change and account deletion 5; everything else 120 per user or IP.
- Email links point at the SPA (`/verify-email?id&hash&expires&signature`, `/reset-password?token&email`);
  the SPA calls the signed API route. Changing the password signs out every other session.
- `GET /api/v1/admin/users` requires the `users.view` permission.
