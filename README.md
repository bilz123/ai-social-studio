# AI Social Studio

Create cinematic 10-second product videos from images and a description, approve them, and publish
to Facebook, Instagram and YouTube.

This repository is at **Phase 6**: accounts, the dashboard, social connections, the Create Video
module (project drafts with product images) and the five Python AI microservices exist. Laravel does not
call the AI services yet, so video generation from the UI and publishing come next.
Design lives in [`docs/`](docs/) (start with
[`PROJECT_ARCHITECTURE.md`](docs/PROJECT_ARCHITECTURE.md) and
[`DEVELOPMENT_ROADMAP.md`](docs/DEVELOPMENT_ROADMAP.md)).

## Layout

```
apps/api/          Laravel 12 API (PHP 8.3): versioned /api/v1, problem+json errors, health checks
apps/web/          React 19 + Vite + TypeScript + Tailwind v4 SPA
services/ai/       Python FastAPI AI service (private; health only for now)
infra/docker/      Dockerfiles + nginx gateway config
infra/compose/     docker-compose.yml
contracts/         Reserved for OpenAPI / JSON Schemas (Phase 2)
docs/              Architecture documentation
scripts/           init-env.sh (secret generation)
```

## Architecture in one paragraph

Browser → **nginx** (the only published port, `127.0.0.1:8080`) → `/api/*` to **Laravel** (php-fpm),
everything else to the **Vite** dev server. The SPA calls the API on the same origin, so CORS is
not even needed in the default setup (it is still configured strictly, see below). **MySQL**,
**Redis** and the **AI service** sit on private Docker networks with no published ports; MySQL and
Redis are on an `internal` network with no internet route. Laravel queue worker and scheduler run
as separate containers.

## Requirements

- Docker with Compose v2, GNU make, `openssl`
- For running tests outside Docker: Node 22+, Python 3.12+, PHP 8.3 + Composer

## Quick start

```bash
make init      # creates .env with generated secrets (git-ignored)
make build     # build images
make install   # composer install inside the api container (first run only)
make up        # start everything
make migrate   # run migrations
make health    # check every service
```

Open <http://localhost:8080>: register an account, then read the verification and password-reset
emails in the Mailpit inbox at <http://localhost:8025> (development only; nothing is sent out).
Service status is at <http://localhost:8080/status>.

Seed the roles and permissions with `make seed` (the migrations also create them, so this is only
needed after a `make fresh`).

Other commands: `make ps`, `make logs s=api`, `make down`, `make fresh` (**deletes data volumes**),
`make help`.

## Health endpoints

| Service | Liveness | Readiness | Exposure |
|---|---|---|---|
| nginx | `GET /nginx-health` | – | public (loopback) |
| Laravel | `GET /api/v1/health` | `GET /api/v1/health/ready` (MySQL, Redis; 503 on failure) | public via nginx |
| AI service | `GET /healthz` | `GET /readyz` (Redis; 503 on failure) | private network only |

Readiness responses only say ok/fail per dependency; error details go to the logs, never to the
client. `php artisan health:check` runs the same checks from the CLI (used as the container
healthcheck).

## Configuration and security

- All settings come from environment variables. Only `.env.example` files are committed;
  `make init` generates strong random secrets into `.env`. Compose refuses to start if a required
  secret is empty.
- **Nothing secret reaches React.** The web container receives only `VITE_API_BASE_URL` and
  `VITE_APP_NAME`; every `VITE_*` value is public by design.
- **CORS** (Laravel): explicit origins from `CORS_ALLOWED_ORIGINS`, credentials allowed, wildcard
  rejected at boot. In development, the Vite proxy and nginx keep browser traffic same-origin.
- **Errors**: RFC 9457 `application/problem+json` with a stable `code` and `request_id`; no stack
  traces or internals in responses. Every request gets an `X-Request-Id`.
- **Logging**: JSON lines to stderr (`docker compose logs`), with a processor that redacts
  passwords, tokens and authorization headers. The AI service logs the same way.
- **API versioning**: routes in `apps/api/routes/api/v1.php`, prefix `/api/v1`, `X-API-Version`
  response header.
- Redis logical DBs: 0 Laravel default/queue/session, 1 Laravel cache, 2 AI service.

## Testing and linting

```bash
make test-web   && make lint-web    # Vitest; ESLint, Prettier, tsc (no Docker needed)
make test-ai    && make lint-ai     # pytest; ruff, mypy      (no Docker needed)
make test-api   && make lint-api    # PHPUnit; Pint           (needs the stack up)
```

Python integration tests against a real Redis run when `REDIS_URL` is set, otherwise they skip.
CI (`.github/workflows/ci.yml`) runs all of the above plus `docker compose config`.

## Phase 2: accounts and data model

- **Auth**: Laravel Sanctum in SPA cookie-session mode (HttpOnly session cookie plus CSRF token; no
  tokens in browser storage). Endpoints: `POST /api/v1/auth/{register,login,logout,forgot-password,reset-password}`,
  `GET /api/v1/auth/{me,verify-email/{id}/{hash}}`, `POST /api/v1/auth/email/verification-notification`,
  `GET|PATCH /api/v1/account/profile`, `PUT /api/v1/account/password`, `GET /api/v1/account/activity`,
  `DELETE /api/v1/account`. Details in `docs/API_DESIGN.md`.
- **Data**: 15 migrations covering users, roles, permissions, social accounts and encrypted tokens,
  projects, images, generation jobs, videos, publishing, scheduling, notifications and an
  append-only audit log. Every user-owned table carries `user_id`, and child tables use composite
  foreign keys so a row can never point at another user's parent. See `docs/DATABASE_DESIGN.md`.
- **Security**: bcrypt hashing, OAuth tokens encrypted at rest (`encrypted` cast, hidden from
  serialization), owner-only policies, mass-assignment allow-lists, rate limits on every auth route,
  session invalidation on password change, no user enumeration on login/reset.
- **Web**: sign in, register, forgot/reset password, email verification, protected routes, profile
  (details, password, activity, delete account).

## Phase 3: dashboard UI

- **Shell**: sidebar (Dashboard, Create Video, Connected Accounts, My Videos, Publishing History,
  Scheduled Posts, Analytics, Settings), header with theme switch, notifications and user menu, and a
  focus-trapped drawer on phones. Light, dark and system themes (`data-theme` on `<html>`, saved in
  `localStorage`), Lucide icons, toasts, loading skeletons, empty and error states.
- **Real API data**: the signed-in user, account activity, profile, password change and account
  deletion (Settings), sign-out.
- **Temporary placeholders** (no backend endpoints yet, each labelled "Sample data" or "Preview" in the
  UI): dashboard statistics, recent projects and videos, connected accounts, publishing activity,
  notifications, notification preferences, and the six feature pages (Create Video, Connected
  Accounts, My Videos, Publishing History, Scheduled Posts, Analytics), which show a "Coming soon"
  empty state. All placeholder content lives in `apps/web/src/features/dashboard/data/` and
  `features/placeholders/`; replace a hook in `useSample.ts` with an `apiFetch` call when its
  endpoint ships and the components stay unchanged.
- `/profile` now redirects to `/settings`.


## Phase 4: social integrations

- **Connected Accounts** (`/accounts`): connect Facebook Pages, Instagram (Business/Creator, Instagram
  Login) and YouTube channels with each platform's official OAuth; see status, permissions and token
  health; check a connection; pause publishing; disconnect (with confirmation). The dashboard's
  Connected accounts widget and counter now use real data. OAuth returns to
  `/connections/callback/{platform}`.
- **API** (`/api/v1/social/*`): platforms, accounts, connect, callback, check, update, disconnect. See
  `docs/API_DESIGN.md` §7.0.
- **Architecture**: `SocialIntegration` / `PublishesVideo` interfaces, `FacebookService`,
  `InstagramService`, `YouTubeService`; OAuth state (hashed, single use, user and platform bound,
  PKCE for Google); tokens encrypted at rest and never returned; token renewal under a lock; permission
  tracking; `needs_reconnect` / `restricted` states; audit log entries for every action.
- **Honest by design**: with no developer credentials a platform shows "Not set up" and its Connect
  button is disabled; nothing is simulated. Credentials, redirect URIs and app review steps:
  `docs/SOCIAL_PROVIDER_SETUP.md`. Check your setup with `php artisan social:check-config`.
- **Scheduler**: `social:refresh-tokens` runs daily (renews Instagram and YouTube tokens, verifies every
  account, flags the ones that need the user).
- **Tests**: mocked provider responses (no network) for every code path; no real Meta or Google round
  trip has been run yet.

## Phase 5: Create Video (images and projects)

- **Create Video** (`/create`, `/create/:id`): your drafts, and an editor with a project name, a product
  description (with character count), and a multi-image uploader: drag and drop or choose files, previews,
  per-file progress, retry/cancel, remove (with confirmation), reorder (drag and drop, or arrow buttons for
  keyboard users), a cover image, validation in the browser and on the server, "Save draft", and opening
  any draft later to keep editing. The dashboard's project list and counter use real data.
- **API** (`/api/v1/projects/*`, `/api/v1/files/images/*`): see `docs/API_DESIGN.md` §4.0.
- **Uploads**: JPEG, PNG, WebP; 10 MB and 10 images per project by default (`UPLOADS_*`). The type is
  read from the file's bytes, the picture is fully decoded, pixel limits stop decompression bombs, and
  nothing is stored for a rejected file. Originals are stored **unchanged** on a private disk under
  generated names; thumbnails are separate JPEGs; files are reachable only through temporary signed links.
- **Needs** the PHP `gd` and `exif` extensions (added to the Docker image and CI).

## Phase 6: Python AI microservices

Five independent, private FastAPI services under `services/` (shared library: `services/common`):
`image-analyzer`, `description-analyzer`, `video-generator`, `video-renderer`, `video-optimizer`.
Contracts: [`contracts/ai/*.openapi.json`](contracts/ai/) and `docs/AI_MICROSERVICES.md` §12.

- **Run the tests (no Docker):** `pip install -e "services/common[dev]" -e services/<name>[dev]` then
  `make test-ai` (needs `ffmpeg` and `fonts-dejavu-core`; real FFmpeg is used on synthetic media).
- **Compose:** `ai-image-analyzer`, `ai-description-analyzer`, `ai-video-generator`, `ai-video-renderer`,
  `ai-video-optimizer`, one shared `ai-media` volume, no published ports. Set provider/vision/LLM keys only in
  your own `.env` (see `.env.example`); the `fake` video provider is for local use and refused in production.
- **Not wired to Laravel yet** (Phase 7). Known limits are listed in `docs/AI_MICROSERVICES.md` §12.9.

## Deviations from the Phase 0 docs

- Phase 2 ownership is per **user** (`user_id` on every record), not per workspace; workspaces can be
  added later without reshaping these tables. Video review state is a column on `generated_videos`
  (`approval_status`, `reviewed_at`, `review_comment`) instead of a `video_reviews` table.

- Social OAuth: Instagram uses its own provider (Instagram Login) instead of going through a Facebook
  Page, the callback is handled by the SPA, and YouTube's upload quota is 100 calls/day (not about 6).
  See `docs/SOCIAL_MEDIA_INTEGRATION.md` §0.
- Product images are uploaded through Laravel (multipart) rather than direct-to-S3 presigned PUTs, so every
  file can be inspected before it is stored. See `docs/API_DESIGN.md` §4.0.
- Laravel skeleton is API-only (no `web` routes, no `/up`): health lives under `/api/v1/health`.
- A single `docker-compose.yml` (dev) instead of base + override; production targets come in the
  deployment phase.

## Known issues

- Packagist was unreachable from the authoring environment, so PHPUnit and Pint run in GitHub
  Actions (tests on SQLite, plus a migrate up/down/up and seed against MySQL 8.4 and Redis). Run
  `make install && make test-api && make lint-api` locally as well.
- The Phase 6 service images were not built (no Docker daemon); a clean `pip install` and `uvicorn` start of
  each service was verified instead.
- The full Docker stack and MySQL connectivity were not run in the authoring environment (no Docker
  daemon). Compose config is validated; first `make up` is the real test.
- No `composer.lock` yet; it is created by the first `make install` and should then be committed.
