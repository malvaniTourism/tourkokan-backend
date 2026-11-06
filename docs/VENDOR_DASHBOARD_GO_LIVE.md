# Vendor dashboard — production go-live requirements

Read this before deploying the web vendor dashboard (M9) to `tourkokan.com` /
`api.tourkokan.com`. Written 2026-11-06, after the same checklist's absence
nearly took out Google sign-in on api-test.

The feature itself is almost entirely web: vendor management moved out of the
app into `/vendor/*` on the website. The backend gained **no new endpoints**.
It gained exactly one thing that can break production, and it is below.

---

## 1. The one that breaks sign-in — do this FIRST, before the code

`GOOGLE_ALLOWED_AUDIENCES` **must be set in the production `.env` before the
new `config/services.php` reaches the server.**

Commit `5008667` moved the Google `aud` allow-list out of code into env and
**deliberately left no default** — an empty list is not a safe fallback, so
`googleAuth` refuses outright and logs instead of accepting a token minted for
anyone. The consequence on a server where the variable is missing:

> **Every Google sign-in fails — website, Android app AND iOS app.**
> Not web-only. The apps authenticate through the same `googleAuth` path.

Add to `/path/to/prod/.env`:

```
GOOGLE_ALLOWED_AUDIENCES=203571229982-9uv6encu2akkkh57hsbbmap8jtklvfnl.apps.googleusercontent.com,203571229982-e04hpgiu5k11ff4qg8oamlm6hruvqj2i.apps.googleusercontent.com,941471956439-bbglsl9ok9o1h2cr82hv9uob1cc7he0d.apps.googleusercontent.com
```

Three clients, comma separated, no spaces:

| id prefix | client |
|---|---|
| `…9uv6encu2akkkh57hsbbmap8jtklvfnl` | website (Google Identity Services) |
| `…e04hpgiu5k11ff4qg8oamlm6hruvqj2i` | Android app |
| `…bbglsl9ok9o1h2cr82hv9uob1cc7he0d` | iOS app |

An Android client authorises by package name + certificate fingerprint and has
no JavaScript origins, which is why the browser needs a client of its own
rather than reusing the app's. All three must stay listed as long as all three
platforms are in use.

**Then clear the config cache**, or the old compiled config keeps winning:

```bash
php artisan config:clear
```

If production caches config (`config:cache`), re-cache after. The app runs
**Laravel Octane** (`laravel/octane` ^2.19) — a cached-config change needs the
Octane workers restarted, not just the cache rebuilt, or the running processes
keep serving the stale value.

**Verify before you believe it:**

```bash
php artisan tinker --execute="print_r(config('services.google.allowed_audiences'));"
```

Must print an array of **3** strings. An empty array means sign-in is dead.

**Verify after deploying:** sign in with Google on the website *and* on an app
build pointed at prod. The web alone is not sufficient evidence — the apps use
different audiences, and the whole point of the list is that each is checked.

---

## 2. Google Cloud Console — not code, and easy to forget

The web OAuth client `203571229982-9uv6…` needs **`https://tourkokan.com`** in
its *Authorized JavaScript origins*. Add `https://www.tourkokan.com` too if that
hostname is served rather than redirected.

Symptom when it is missing: Google's popup fails with `origin_mismatch` and the
backend logs **nothing** — there is no token to reject, so this looks like a
frontend bug and is not one. This is what happened on `test.tourkokan.com`.

Android and iOS clients need no change for this feature.

---

## 3. Web deploy

`.env.production` is tracked and already carries
`NEXT_PUBLIC_GOOGLE_WEB_CLIENT_ID` — nothing to add by hand.

```bash
npm run deploy:prod   # worker "tourkokan"
```

Note that `deploy:prod` sets `NEXT_PUBLIC_API_URL` and `NEXT_PUBLIC_FTP_PATH`
inline but **not** the client id, which comes from `.env.production` via
`next build`. Keep that file in sync; an inline override here would silently
shadow it.

⚠️ **Cloudflare Workers Builds may already auto-deploy on push.** On
`test.tourkokan.com` a manual `wrangler deploy` reported success while
Cloudflare's own build superseded it — the served bundle was not the one that
had just been uploaded. Confirm which branch maps to the prod worker before
pushing, and verify the deploy by the **served chunk name**, not by the CLI's
success message.

---

## 4. Verify the deploy actually went live

Success messages are not evidence. For each of the two deploys:

- **Backend** — hit an endpoint and confirm the response came from the new code
  (`config:clear` done, Octane restarted, `allowed_audiences` prints 3).
- **Web** — load `/vendor` and check the served JS chunk hash changed.

---

## 5. App side (Phase 4 — not shipped yet)

If the app changes ship in the same release:

- Add **`WEB_URL=https://tourkokan.com`** to `.env.production`. Do **not** reuse
  `APP_URL` (that is the Play Store link) or `DEEP_LINK_HOST` (App Links
  manifest placeholder). Metro must restart with `--reset-cache` — `@env` is
  content-keyed and resolves on `APP_ENV`.
- Release scripts **must** set `APP_ENV` or the build bakes in the test API
  endpoint. This is keyed on `APP_ENV`, not on the gradle flavour.
- **`SubmitPlaceScreen.js:438`** does `navigation.replace(ADD_PRODUCT)`. It must
  be repointed at the web form **before** the marketplace routes are
  unregistered, or submitting a place crashes at the "Add products" step — the
  vendor happy path.

---

## 6. Deploy mechanism — confirm before prod day

`.github/workflows/deploy.yml` fires only on push to **`prod`** and runs:

```
rsync -avz --delete ./ pranav@80.225.208.168:/var/www/html/probyte/products/probytesolution/prod
```

Two things about that, both worth settling *before* release day rather than
during it:

1. **`--delete` with a source that has no `.env` and no `vendor/`.** Both are
   gitignored, so the GitHub checkout does not contain them, and `--delete`
   removes destination files absent from the source. As written, this deploy
   would delete production's `.env` and `vendor/`. Either the workflow is not
   how prod is actually deployed, or `.env` is restored and `composer install`
   re-run each time. **Find out which** — if it is the latter, the env var in
   §1 has to be re-added after every deploy, which is precisely the failure
   mode this document exists to prevent.
2. **There is no api-test equivalent.** api-test lives at
   `/www/wwwroot/test/api-test.tourkokan.com`, is **not** a git checkout, and is
   owned by `www:www` — deploys there are a manual file copy, and `pranav` has
   neither write access nor passwordless sudo.

---

## 7. Still a launch gate, independent of this feature

- **M3 legal copy** — vendor terms / liability / DPDP consent is clearly marked
  PLACEHOLDER (`VENDOR.CONSENT_DPDP` in the app, `vendor.consentPlaceholder` on
  web). Needs legal sign-off. See `../../tourkokan-v2/docs/vendor-onboarding-plan.md`.
- **S3 ops task** — restrict public read on the `<env>/sites/docs/` prefix.
  Verification documents land there.

---

## Order of operations, condensed

1. Google Console: add `https://tourkokan.com` to the web client's origins.
2. Prod `.env`: add `GOOGLE_ALLOWED_AUDIENCES` (all three ids).
3. `php artisan config:clear` (+ re-cache and restart Octane if cached).
4. Verify `config('services.google.allowed_audiences')` prints 3 entries.
5. Deploy the backend code.
6. Deploy the web.
7. Verify Google sign-in on **web and app**, and that both deploys are actually
   live by served-artifact identity.

Steps 1–4 before 5. Reversing that order is the outage.
