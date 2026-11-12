# Social provider setup (Facebook · Instagram · YouTube)

**Status:** Phase 4 (as built). Written against Meta and Google developer documentation checked on 2026-11-12. Console menu names change often; where a label below differs from what you see, follow the provider's current documentation.

The application never fakes a connection. A platform whose credentials are empty shows **Not set up** in the UI, its Connect button is disabled, and `POST /api/v1/social/{platform}/connect` answers `503 integration_not_configured`. Nothing else is needed to ship the code; you only need the credentials below to connect real accounts.

## 1. What you configure

| Platform | Provider app | Environment variables | Redirect URI to register |
|---|---|---|---|
| Facebook Pages | Meta app, **Facebook Login** | `META_APP_ID`, `META_APP_SECRET`, `META_GRAPH_VERSION` (default `v26.0`) | `{FRONTEND_URL}/connections/callback/facebook` |
| Instagram | Same Meta app, **Instagram API with Instagram Login** | `INSTAGRAM_APP_ID`, `INSTAGRAM_APP_SECRET` (shown under the Instagram product; they differ from the Facebook ones) | `{FRONTEND_URL}/connections/callback/instagram` |
| YouTube | Google Cloud project, OAuth client type **Web application** | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | `{FRONTEND_URL}/connections/callback/youtube` |

`{FRONTEND_URL}` is the public address of the web app (`http://localhost:8080` in the Docker stack). The provider redirects the browser to the web app, which forwards the code to the API. That is why the redirect URI is a web-app URL and not an API URL.

Put the values in the repository-root `.env` (Docker Compose) or `apps/api/.env` (running Laravel directly), then check them:

```bash
docker compose -f infra/compose/docker-compose.yml exec api php artisan social:check-config
```

The command prints, per platform, whether credentials are present, the exact redirect URL to register, and the permissions requested. `--strict` exits non-zero when anything is missing (useful in a deploy pipeline).

Never commit credentials. They are read only by the API; nothing here is exposed to the browser bundle.

## 2. Meta (Facebook Pages and Instagram)

1. Create an app at developers.facebook.com (business type). Add the **Facebook Login** product and the **Instagram** product (use *API setup with Instagram login*).
2. Facebook Login: add `{FRONTEND_URL}/connections/callback/facebook` to *Valid OAuth Redirect URIs*. Copy the App ID / App Secret from *App settings → Basic* into `META_APP_ID` / `META_APP_SECRET`.
3. Instagram login: add `{FRONTEND_URL}/connections/callback/instagram` as the redirect URL. Copy the **Instagram app ID / secret** into `INSTAGRAM_APP_ID` / `INSTAGRAM_APP_SECRET`.
4. Permissions requested by the app:
   - Facebook: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`.
   - Instagram: `instagram_business_basic`, `instagram_business_content_publish`.
5. **Development mode (no approval needed).** Only people with a role on the app can connect. Add yourself as an admin/developer/tester. For Instagram, add the Instagram account as an **Instagram tester** and accept the invitation inside Instagram. You must also hold a role/task on the Facebook Page to publish to it (a Page `CREATE_CONTENT` task).
6. **Live mode (real users).** Switch the app to Live and pass **App Review** (Advanced Access) for the permissions above, plus Business Verification where Meta asks for it. You will need a privacy policy URL, and Meta requires a **Deauthorize callback** and a **Data deletion request URL** before the app can go live (see *Known gaps*).
7. Redirect URIs: Meta accepts `http://localhost` for development apps in most products. If it rejects a URI, put an HTTPS tunnel in front of the local gateway and set `FRONTEND_URL`, `APP_URL`, `CORS_ALLOWED_ORIGINS` and `SANCTUM_STATEFUL_DOMAINS` to that host.

Account requirements:
- **Facebook**: you share the Pages you manage during Meta's consent dialog. Pages where you cannot create content are skipped.
- **Instagram**: only **Business** or **Creator** accounts work. Personal accounts are refused with instructions to switch the account type (Instagram → Settings → Account type and tools).

Tokens:
- Facebook Page tokens (obtained from a long-lived user token) do not expire, but stop working if the user changes their password, removes the app or loses their Page role. The daily check detects this (`debug_token`) and marks the account **Needs reconnect**.
- Instagram long-lived tokens last 60 days. The daily job renews them (`ig_refresh_token`) once fewer than 14 days remain. Instagram only renews tokens that are unexpired and at least 24 hours old, so a token left to expire needs a reconnect.

## 3. Google (YouTube)

1. In Google Cloud Console create (or pick) a project and enable **YouTube Data API v3**.
2. Configure the **OAuth consent screen** (External). Add the scopes `https://www.googleapis.com/auth/youtube.upload` and `https://www.googleapis.com/auth/youtube.readonly`.
3. Create an **OAuth client ID**, type **Web application**, with the authorized redirect URI `{FRONTEND_URL}/connections/callback/youtube`. Copy the client ID and secret into `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`.
4. **Testing status.** While the consent screen is in *Testing*, only listed **test users** can connect and Google expires refresh tokens after **7 days**, so connections need renewing weekly. Publish the app to *In production* for real use. The upload scope is a sensitive scope, so Google requires **OAuth app verification** (privacy policy, homepage, a demo video of the flow).
5. The Google account must own a YouTube **channel**; accounts without one are refused with instructions.
6. Google's granular consent lets the user untick scopes. The API checks the granted scopes and refuses a connection that lacks upload access.
7. **Quota.** Google's current documentation counts uploads in a separate *Video Uploads* quota bucket of **100 upload calls per project per day** (1 unit each). This supersedes the older assumption (about 1,600 units per upload, roughly 6 uploads a day) in the Phase 0 design. The limit is per Google Cloud project, shared by all users. A quota error surfaces as `provider_rate_limited`. Confirm the figure on your project's quota page and check Google's current policy on whether videos from unverified API projects are restricted to private.

## 4. Verifying a real connection (development)

1. Fill the credentials, restart the API container, run `social:check-config`.
2. Sign in to the web app, open **Connected Accounts**, choose a platform. Approve the permissions on the provider's screen.
3. You return to `/connections/callback/{platform}`, which shows the connected accounts. Use **Check connection** on a card to run the same health check the daily job runs.

What the automated tests cover (no credentials, no network): the full code-exchange, discovery, refresh, revocation and error paths for all three providers against mocked provider responses (`apps/api/tests/Feature/Social`), and the UI flows against a mocked API (`apps/web/src/features/social`). **No real Meta or Google round trip has been executed**; do the steps above with a development app before relying on it.

## 5. Operations

- Scheduler: `schedule:work` (the `scheduler` Compose service) runs `social:refresh-tokens` daily at 03:15 (renews due tokens, re-reads each account, marks `needs_reconnect` / `restricted` with a reason) and `model:prune` for expired OAuth state rows.
- Manual run: `php artisan social:refresh-tokens --all`.
- Audit trail (`audit_logs`, visible to each user in *Settings → Security*): `social.connect.started|failed|unavailable`, `social.account.connected|disconnected|updated|checked|needs_reconnect`, `social.token.refreshed`. No tokens, codes or state values are ever logged.
- Provider failures are logged as `social.provider_error` with status, provider error code and a truncated message, never credentials.

## 6. Known gaps (not part of Phase 4)

- **Meta deauthorize callback and data-deletion URL** are not implemented. Meta requires both before an app can go Live; add them with the publishing phase.
- Facebook publishing uses the Page video endpoint with a `file_url`; the dedicated Page **Reels** upload flow is not wired yet.
- YouTube `publishVideo` downloads the file and sends it in one request (fine for 10-second videos). Chunked resumable upload comes with the publishing engine.
- `me/accounts` is read up to 100 Pages; pagination is not followed.
- All Pages the user shares are connected; there is no per-Page picker (the user chooses in Meta's dialog).
