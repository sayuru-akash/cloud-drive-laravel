# Cloud Drive Laravel — Agent Guide

## Project Overview

A standalone Laravel 13 Cloud Drive application for private workspace file management. Uses Vue 3, Inertia 3, TypeScript, Tailwind CSS 4, and Backblaze B2 presigned URLs — file bytes move directly between the browser and object storage, never proxied through the Laravel app.

**Stack:** Laravel 13.x / PHP 8.3+ / Vue 3 / Inertia 3 / TypeScript / Vite 8 / Tailwind CSS 4 / shadcn-vue / PostgreSQL / Backblaze B2 (S3-compatible) / Fortify / Pest / Pint / ESLint / Prettier

**Architecture:**
```
Browser → Inertia pages & JSON APIs → Laravel 13 (metadata, policy, audit logs)
                                           ↓ presigned PUT/UploadPart/GET URLs
                                      Backblaze B2 private bucket
```

The app creates short-lived signed URLs. Files, folders, shares, uploads, and audit events live in PostgreSQL metadata tables.

## Directory Map

```
app/
├── Actions/Fortify/         # CreateNewUser, ResetUserPassword
├── Concerns/                # PasswordValidationRules, ProfileValidationRules
├── Enums/                   # FileStatus, ResourceVisibility, ShareMode, ShareResourceType, UploadStatus
├── Http/
│   ├── Controllers/         # 13 controllers: Drive, Auth, Admin, Audit, Share, Health
│   │   └── Settings/        # ProfileController, SecurityController
│   ├── Middleware/          # EnsureAdmin, EnsureUserIsActive, HandleAppearance, HandleInertiaRequests
│   └── Requests/Settings/   # Form requests for profile operations
├── Models/
│   └── Concerns/            # HasPrefixedId trait
├── Providers/               # AppServiceProvider, FortifyServiceProvider
├── Services/                # ObjectStorage, DrivePermission, DriveQuery, AuditLogger, AppSettings
└── Support/                 # PrefixedIds utility

config/
├── drive.php                # B2 storage, upload policy, blocked extensions
├── fortify.php              # Auth features (2FA, registration, email verification)
└── inertia.php              # SSR config, page discovery

database/
├── migrations/              # Users + drive tables (folders, files, file_versions, uploads, share_links, audit_logs, app_settings)
└── seeders/
    ├── DatabaseSeeder.php
    └── DemoWorkspaceSeeder.php   # admin@example.com / password, demo files/folders/audit

resources/js/
├── app.ts                   # Inertia app boot: layouts, theme init, flash toast
├── actions/                 # Inertia actions
├── components/
│   ├── cloud/               # Drive-specific components (PageHeader, StatusBadge, etc.)
│   └── ui/                  # shadcn-vue components (reka-ui based)
├── composables/             # useAppearance, useCurrentUrl, useInitials, useTwoFactorAuth
├── layouts/                 # AppLayout, AuthLayout, SettingsLayout
├── lib/                     # flashToast, format, utils
├── pages/                   # Inertia page components grouped by domain
│   ├── admin/               # Admin users & settings
│   ├── audit/               # Audit log viewer
│   ├── auth/                # Login, Register, 2FA, password reset
│   ├── deleted/             # Trash/recycle bin
│   ├── files/               # File browser (Index.vue), direct B2 uploads
│   ├── public/              # Privacy page, PublicShare download page
│   ├── settings/            # Profile, Security, Appearance
│   └── shared/              # Active share links
├── routes/                  # Wayfinder auto-generated route types
└── types/                   # global.d.ts, index.ts, auth.ts, navigation.ts, ui.ts, vue-shims.d.ts

routes/
├── web.php                  # Main routes: drive, admin, audit, upload API, public shares
├── settings.php             # Profile & security routes
└── console.php              # Artisan commands

tests/
├── Feature/                 # HTTP integration tests (Pest)
│   ├── Auth/                # Authentication flow tests
│   ├── Settings/            # Profile/security tests
│   ├── DashboardTest.php
│   ├── DriveActionsTest.php # Upload, delete, restore, share, permission tests
│   └── PublicSeoTest.php    # Public page SEO & robots tests
└── Unit/                    # Pure unit tests
    ├── DrivePermissionServiceTest.php
    ├── ObjectStorageServiceTest.php
    └── PrefixedIdsTest.php
```

## Data Model

**Users** — `role` (`member`|`admin`|`super_admin`), `is_active`, 2FA columns (Fortify). First registered user is `super_admin`; subsequent are `member`.

**Folders** — Prefixed ID (`folder_*`), hierarchical via `parent_folder_id`, `ResourceVisibility` (`private`|`workspace`), soft-delete.

**DriveFile** — Prefixed ID (`file_*`), `FileStatus` (`pending`|`ready`|`failed`|`deleted`), `ResourceVisibility`, `folder_id`, `current_version_id`.

**FileVersion** — Prefixed ID (`version_*`), `storage_bucket`, `storage_key`, linked to file. Created on upload completion.

**Upload** — Prefixed ID (`upload_*`), `UploadStatus` (`initiated`|`uploading`|`completed`|`failed`|`cancelled`), `provider_upload_id` (multipart), `storage_key`, `expires_at`.

**ShareLink** — Prefixed ID (`share_*`), `token_hash` (SHA-256), `ShareMode` (`view`|`download`), `expires_at`, `is_revoked`.

**AuditLog** — Prefixed ID (`audit_*`), `action_type`, `resource_type`, `resource_id`, `metadata_json`, `actor_user_id`, `ip_address`, `user_agent`.

**AppSetting** — Key-value store (`maxUploadSizeBytes`, `retentionDays`, `shareExpiryDays`, `blockedExtensions`).

## Key Architectural Patterns

### 1. Prefixed ULID IDs
All domain models use `HasPrefixedId` trait. IDs follow `{prefix}_{ulid}` format (e.g., `file_01JQ...`, `folder_01JQ...`). Generated in `bootHasPrefixedId()` via `App\Support\PrefixedIds::make($prefix)`. Uses `public $incrementing = false` and `protected $keyType = 'string'`.

### 2. Direct-to-B2 Upload Pipeline
Authenticated users upload file bytes directly to Backblaze B2 via presigned URLs:
1. `POST /api/files/initiate-upload` — validates size/extension, creates DriveFile+Upload rows, returns presigned URL (or multipart metadata for large files)
2. Browser uploads to B2 directly, shows live progress
3. `POST /api/files/{file}/complete-upload` — verifies object exists, creates FileVersion, marks file Ready
4. Multipart support: `POST /api/files/{file}/multipart-part` for each part URL, `complete` assembles all ETags
5. Cancel: `POST /api/files/{file}/cancel-upload` — aborts multipart, soft-deletes file

### 3. Permission Model
`DrivePermissionService` checks:
- **Admin** (`admin`|`super_admin` role): can view and manage everything
- **Owner**: `owner_user_id === user.id` → can view and manage
- **Workspace visibility**: `ResourceVisibility::Workspace` → view only, not manage

Route middleware: `auth`, `verified`, `active` → inner; `admin` → admin-only routes.

### 4. Audit Logging
Every state-changing action is logged via `AuditLogger::log(action, resourceType, resourceId, metadata, request)`. Captures actor, email, IP, user agent, timestamp. Used in all controllers for file uploads, folder CRUD, shares, settings changes, and deletions.

### 5. Service Layer
Five focused services:
- `ObjectStorageService` — S3Client factory, presigned PUT/GET/UploadPart URLs, multipart orchestration, download disposition
- `DrivePermissionService` — canView / canManage / isAdmin checks
- `DriveQueryService` — folder browsing with filters, breadcrumbs, descendant folder traversal, unique name resolution
- `AppSettingsService` — workspace policy reads and admin updates
- `AuditLogger` — writes audit events

### 6. Enum-Driven State
All status and type fields use backed string enums: `FileStatus`, `ResourceVisibility`, `ShareMode`, `ShareResourceType`, `UploadStatus`. Models use `casts()` for automatic enum mapping.

### 7. Frontend: Inertia + Vue 3 + shadcn-vue
- Pages render via `Inertia::render('component', props)` with typed props in Vue
- Layout resolution in `app.ts` based on component name prefix
- shadcn-vue (reka-ui) for accessible UI primitives
- Lucide-vue-next for icons
- `vue-sonner` for toasts, wired via `flashToast.ts` listening for Inertia flash events
- Dark mode via `useAppearance` composable with localStorage + cookie persistence
- `format.ts` provides `formatBytes`, `formatDate` helpers

## Quality Gate (CI)

Run all before shipping changes:

```bash
composer run lint:check      # Pint (dry-run)
php artisan test              # Pest
npm run lint:check            # ESLint
npm run types:check           # vue-tsc --noEmit
npm run build                 # Vite production build
php artisan route:list --except-vendor
```

For storage changes, test a real browser upload against the B2 bucket and confirm CORS exposes `ETag`.

## DevOps / Production

**Setup:**
```bash
composer run setup            # Full setup: install + env + keygen + migrate + npm
composer run dev              # Starts server, queue worker, logs, and vite concurrently
```

**Production commands:**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
```

**Demo seed (never in production):**
```bash
php artisan migrate:fresh && php artisan db:seed --class=DemoWorkspaceSeeder
# Login: admin@example.com / password
```

## Coding Conventions

### PHP / Laravel
- PHP 8.3+ with typed properties, backed enums, `#[Fillable]`/`#[Hidden]` attributes
- Controllers use `__invoke` for single-action; multi-action use named methods
- Dependency injection with constructor or method injection (no facades for core services)
- Validation uses inline `$request->validate([...])` arrays
- Auth checks use `abort_unless(…)` pattern, not `if/return`
- Permission checks in controllers, not in routes
- `DB::transaction()` for multi-table writes (e.g., upload completion, folder cascade delete)
- `rescue(fn() => …, report: false)` for non-critical B2 cleanup (abort multipart, delete objects)
- Prefixed ULIDs — never auto-increment integers for domain models

### Vue / TypeScript
- `<script setup lang="ts">` with typed `defineProps<>()` for all components
- Custom types in `resources/js/types/` (e.g., `FlashToast`, `Appearance`)
- ESLint enforces: `consistent-type-imports` (separate), import ordering, 1TBS braces, padding around control statements
- Prettier for formatting (integrated with eslint-config-prettier)
- No `any` type (explicitly allowed via ESLint for pragmatic cases)

### Testing (Pest)
- `RefreshDatabase` trait on all Feature tests
- `User::factory()->create()` for test users
- HTTP tests use `$this->actingAs($user)->get/post/patch/delete(…)`
- Arrange-act-assert style, often combined with `expect(…)->toBe(…)`
- Unit tests instantiate services directly, construct model instances without database when testing pure logic
- Testing DB uses SQLite in-memory (`phpunit.xml`)

### Common Pitfalls
- **CORS**: B2 bucket CORS must match app origin, allow PUT/GET/HEAD, expose ETag
- **SignatureDoesNotMatch**: browser must send the exact `Content-Type` Laravel signed
- **Multipart completion**: every part ETag must match its part number exactly
- **Download inline**: app signs downloads with `ResponseContentDisposition: attachment` — clear cache if browser ignores it
- **First user not admin**: another user already exists in DB
- **Inactive user**: EnsureUserIsActive middleware auto-logs out inactive accounts
- **Blocked extensions**: stored in config and DB app_settings; checked during upload initiation

## Development Commands

```bash
composer run dev               # All services at once
composer run lint              # Fix code style (Pint)
composer run lint:check        # Check code style (Pint dry-run)
composer run test              # Full test suite
npm run dev                    # Vite dev server only
npm run build                  # Vite production build
npm run lint:check             # ESLint check
npm run types:check            # vue-tsc type check
php artisan route:list --except-vendor
```

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/vue3 (INERTIA_VUE) - v3
- tailwindcss (TAILWINDCSS) - v4
- vue (VUE) - v3
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
