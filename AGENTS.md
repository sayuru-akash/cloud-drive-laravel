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
