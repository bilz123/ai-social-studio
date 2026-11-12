# Development Roadmap

**Status:** Draft v0.1 · Design only — **no implementation starts until you approve**
Related: [PROJECT_ARCHITECTURE](PROJECT_ARCHITECTURE.md) · [AI_MICROSERVICES](AI_MICROSERVICES.md) · [SOCIAL_MEDIA_INTEGRATION](SOCIAL_MEDIA_INTEGRATION.md)

Estimates assume **1–2 full-stack developers** and are rough (±40 %). Calendar time is dominated by external reviews (Meta, Google), which run in parallel with development.

## 1. Guiding principles

1. **Thin vertical slice first:** get one image → video → preview working end-to-end with a *fake* provider before spending money on real models.
2. **Reduce the biggest unknowns early:** (a) video quality/cost, (b) platform approvals and quotas.
3. **Publish to the easiest target first** (see Phase 5 order) to validate the publishing framework.
4. **Every phase ends demo-able and tested**; no phase leaves the main branch broken.
5. **Contracts before code:** OpenAPI + internal AI schemas are written and reviewed first.

## 2. Phase overview

| Phase | Name | Est. | Exit demo |
|---|---|---|---|
| 0 | Foundations & infrastructure | 1 wk | `docker compose up` brings up everything; CI green on empty apps |
| 1 | Identity, workspaces, media, public pages (+ start external reviews) | 2–3 wk | Register → create workspace → upload & validate images; privacy/ToS/data-deletion pages live |
| 2 | AI pipeline with fake provider, then **provider bake-off** | 3 wk | Brief + images → real 10 s MP4 in S3 via the full Laravel↔Python protocol |
| 3 | Studio UI: wizard, progress, preview, approval | 2–3 wk | Non-technical user completes brief → watch → approve in the browser |
| 4 | Social connections (Meta + Google OAuth) | 2 wk | Connect FB Page, IG, YouTube channel in dev mode; accounts listed/selectable; health checks |
| 5 | Publishing engine + platforms | 3 wk | Approved video published to ≥ 3 targets; partial-failure + retry demonstrated |
| 6 | Hardening: security, quotas, observability, load, a11y | 2 wk | Pen-test checklist clear; SLOs dashboard; quotas enforced |
| 7 | Platform approvals, beta, launch | 2 wk dev + 4–8 wk external (parallel) | Live apps approved; closed beta users publish for real |
| 8 | Post-MVP | ongoing | Extra renditions, scheduling UI, AI music/voice, self-hosted GPU, more platforms, billing |

Critical path: **P0 → P1 → P2 → P3 → P5** (with P4 parallel to late P2/P3). Approvals (P7 external) start in **P1**.

## 3. Phase details

### Phase 0 — Foundations (≈ 1 wk)
- Monorepo scaffolding per ARCHITECTURE §6: Laravel app, Vite React TS + Tailwind app, FastAPI service skeleton.
- Docker Compose (nginx, api, horizon, scheduler, reverb, web, mysql, redis, minio, ai-api, ai-worker, mailpit); `.env.example`; Makefile (`make up`, `make test`, `make fresh`).
- CI: lint + tests per app, image build, migration dry-run.
- Tooling: Pint/Larastan/Pest, ESLint/Prettier/Vitest, ruff/mypy/pytest; pre-commit hooks; Dependabot/Renovate; Sentry wiring.
- Write `contracts/` skeleton (OpenAPI stub, AI job/webhook JSON Schemas).
- **Definition of done:** all services healthy locally; CI green; README documents exact versions.

### Phase 1 — Identity, tenancy, media, public pages (≈ 2–3 wk)
- Migrations: users, workspaces, members, media, campaigns, campaign_assets, audit_logs.
- Auth (Sanctum SPA): register/login/logout/verify/reset; throttling; workspace creation; roles + policies; tenant-isolation test suite.
- Media: presigned upload, `complete`, validation job (decode, EXIF strip, size/dimension rules), preview URLs, lifecycle rules.
- React: auth screens, workspace shell, upload component with progress.
- Public Blade pages: home, privacy, terms, **data-deletion instructions**, contact; sitemap/robots/meta/JSON-LD; `/app` noindex.
- **Kick off external processes (non-blocking):** register Meta app + begin Business Verification; set up Google Cloud project/consent screen; draft YouTube quota/audit request.
- **DoD:** cross-tenant access tests pass; upload of malicious/oversized/mislabeled files rejected; Lighthouse ≥ 90 on public pages.

### Phase 2 — AI pipeline & provider bake-off (≈ 3 wk)
- Internal API + HMAC auth + webhook handler (idempotent, ordered) + polling reconciliation.
- `video_generations`/clips/events/videos migrations; state machine; cancel/regenerate.
- AI service: ingest → analyze → plan → generate → QA → post-process → deliver; **FakeProvider** first (sample clips) so the protocol and FFmpeg stage are proven with zero spend.
- FFmpeg master recipe + golden tests (10.00 ± 0.05 s, 1080×1920, H.264/AAC, +faststart).
- **Bake-off (week 3):** adapters for 2–3 candidate providers; ~20-image test set; score fidelity, motion quality, failure rate, latency, $/video; choose primary + fallback; document results in `docs/AI_PROVIDER_EVALUATION.md` (new).
- Cost ledger + per-generation `max_cost_cents`; moderation pre-check.
- **DoD:** real image set → 10 s MP4 meeting spec, p50 ≤ 5 min; failure paths tested (provider down, QA fail, worker crash, duplicate webhook, out-of-order events).
- **Decision gate:** if no provider reaches acceptable fidelity/cost → revisit approach (e.g. more constrained motion presets, ken-burns hybrid, self-hosted models) *before* building more UI.

### Phase 3 — Studio UI (≈ 2–3 wk)
- Campaign wizard (images → brief → style/options → generate); Reverb realtime + polling fallback.
- Progress stepper; storyboard view; error states with actionable messages.
- Review screen: player, regenerate with notes (version history), Approve / Reject / Request changes → `video_reviews`.
- Copy suggestions (`/copy/suggest`) for captions/titles/hashtags.
- Quotas surfaced in UI.
- **DoD:** Playwright E2E happy path (fake provider); keyboard/screen-reader pass on core flow; mobile-responsive.

### Phase 4 — Social connections (≈ 2 wk; parallel with late Phase 2/3)
- `SocialPlatform` interface + Meta and Google implementations for connect/callback/discover/revoke.
- Encrypted token storage, `oauth_states`, account selection UI, token health job, reconnect UX.
- Meta deauthorize + data-deletion callbacks.
- Fake platform server for CI.
- **DoD:** connect/disconnect works for all three platforms with test accounts in dev mode; expired/revoked token paths verified.

### Phase 5 — Publishing engine (≈ 3 wk)
Order: **Facebook Page Reel → Instagram Reel → YouTube** (Meta flows verified and symmetric; YouTube gated by quota/audit, so it launches in limited mode).
- `publications`, targets, attempts; `PublishToTarget` + polling jobs; rate-limit gates; duplicate-post protection; scheduling.
- Per-platform constraint validation (UI + API); approval gate enforced in DB + service.
- Publish screen + status page with per-target retry.
- YouTube quota accountant + honest "limited beta" UI until quota extension.
- **DoD:** approved video published to FB + IG + YT test accounts; partial failure + retry demonstrated; no duplicates under forced retries/concurrency (tested).

### Phase 6 — Hardening (≈ 2 wk)
- Security review against OWASP ASVS L2 subset; dependency + container scans; secrets rotation runbook; CSP/headers; optional 2FA.
- Observability dashboards and alerts (queue depth, generation SLO, publish success, quota, token expiry).
- Load test: 50 concurrent generations (fake provider) and 200 concurrent UI sessions.
- Backup/restore drill for MySQL and S3 versioning; disaster-recovery notes.
- Accessibility audit; performance budget enforcement.
- **DoD:** checklist signed off; SLOs defined and visible.

### Phase 7 — Approvals, beta, launch
- Submit Meta App Review (per permission, with screencasts), complete Business Verification; Google OAuth verification; YouTube compliance audit/quota extension.
- Closed beta (5–20 users) on staging→prod with spend caps; feedback loop; fix list.
- Support runbooks; status page; ToS/privacy final legal review.
- **DoD:** Meta and Google approvals in hand (or YouTube explicitly in limited mode); beta success metrics met (generation success ≥ 90 %, publish success ≥ 95 %).

### Phase 8 — Post-MVP backlog
Extra renditions (1:1, 16:9), richer scheduling calendar, AI music/voice-over, brand kits (logo/colors/fonts), template styles, analytics pull-back (views/likes), TikTok/LinkedIn/X via `SocialPlatform`, team approval workflows, billing/credits, self-hosted GPU workers, multilingual UI/RTL.

## 4. Team & roles (suggested)
| Role | Focus |
|---|---|
| Full-stack lead (Laravel) | P0–P1, P4–P5, API contracts |
| Frontend (React) | P1, P3, publish UX |
| Python/ML-ops | P2, bake-off, FFmpeg, later GPU |
| Product/Compliance owner (you/ops) | Platform accounts, Business Verification, legal pages, music licensing, review submissions |

One strong full-stack developer can cover it sequentially; expect the calendar time to roughly double.

## 5. Testing & quality gates (per phase)
Unit + feature tests green · contract tests green · static analysis clean · migrations reversible in CI · Playwright happy path (from P3) · no secrets in repo (gitleaks) · docs updated in the same PR as behavior changes.

## 6. Technical risk register

| # | Risk | Likelihood | Impact | Mitigation | Owner/Phase |
|---|---|---|---|---|---|
| R1 | Meta App Review / Business Verification delays or rejection | High | High | Start P1; dev-mode testing; precise screencasts; minimal permissions; keep fallback of "bring your own Meta app" for early users | P1/P7 |
| R2 | YouTube quota (~handful of uploads/day by default) and audit/private-lock behavior ⚠️ | High | High | File audit/quota request early; quota accountant; limited-beta UI; sequence YouTube last | P1/P5/P7 |
| R3 | AI video fidelity (logo/text/shape warping), motion artifacts | High | High | Image-conditioned only; bake-off; QA scoring; regenerate; human approval; curated style presets | P2 |
| R4 | Cost per video exceeds margin | Med | High | Provider routing, cost caps, caching analysis, quotas, cost ledger from day 1; later self-host | P2/P8 |
| R5 | Provider/API churn or outage | Med | Med | Adapter layer + fallback + contract tests + circuit breaker | P2 |
| R6 | Instagram needs public video URL / Professional account / PPA | Med | Med | Presigned URLs with adequate TTL or resumable upload; clear onboarding checks and error messages | P4/P5 |
| R7 | Daily publish caps (FB 30 Reels/Page, IG 50 posts/account) | Low | Med | Cap-aware scheduler; surface remaining capacity | P5 |
| R8 | Token expiry/revocation → silent failures | High | Med | Health checks, proactive notifications, reconnect UX, never claim "published" without platform confirmation | P4/P5 |
| R9 | Duplicate posts from retries/webhook replays | Med | High | Persist container/external ids before polling; unique constraints; idempotency keys; chaos tests | P5 |
| R10 | Cross-tenant data leak | Low | Critical | Scoped bindings, policies, automated isolation tests, ULIDs | P1/P6 |
| R11 | Malicious uploads (polyglots, huge images, SSRF via URLs) | Med | High | Decode-and-re-encode, caps, no user-provided URLs fetched, AV scan optional | P1/P6 |
| R12 | FFmpeg CPU saturation/long queues | Med | Med | Dedicated worker pool, concurrency limits, autoscale on queue depth | P2/P6 |
| R13 | Legal: music licensing, AI-content disclosure, likeness/brand rights | Med | High | License-vetted library only; disclosure flags + UI reminders; ToS puts rights on user; legal review before launch | P7 |
| R14 | Scope creep (extra platforms/features) | High | Med | MVP boundary in ARCHITECTURE §1; backlog in P8 | All |
| R15 | Laravel↔Python protocol bugs (ordering, replays) | Med | Med | Idempotent handler, row locks, event ordering rules, polling reconciliation, protocol tests | P2 |

## 7. Approval checklist for starting implementation

Before Phase 0 begins, please confirm (see ARCHITECTURE §17):
1. Production hosting target and budget ceiling.
2. Single-user vs team workspaces at launch.
3. Video provider preference/budget per video (or approve the bake-off approach).
4. Audio approach for MVP (silent vs licensed library).
5. UI/copy languages (RTL needed?).
6. Who owns Meta/Google developer accounts and Business Verification.
7. Approve this roadmap and the proposed folder structure, or request changes.
