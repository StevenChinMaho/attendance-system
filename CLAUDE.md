# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

國中校園點名系統 (a junior-high-school attendance system). Full business context, permission model, and database ERD live in [system_structure.md](system_structure.md) — read it before touching schema, roles, or attendance business logic. Accounts/roles, school classes/students/teachers, the core attendance recording flow (`attendance_sessions`/`attendance_records`), and the follow-up/audit-log domain (`attendance_follow_ups`, activitylog integration) are implemented; the live status dashboard is not yet built (see system_structure.md's 開發流程規劃 for the phase order).

## Commands

This project is developed exclusively through **Laravel Sail**. Do not run bare `composer`/`php artisan`/`npm` on the host — host PHP/Node versions are not guaranteed to match the container, and the project's dev guideline requires Sail.

```bash
./vendor/bin/sail up -d                                        # start containers (app + mariadb)
./vendor/bin/sail artisan migrate --seed                       # run migrations + seed
./vendor/bin/sail artisan test                                 # full test suite
./vendor/bin/sail artisan test --filter=test_method_name        # single test
./vendor/bin/sail artisan test tests/Feature/ExampleTest.php    # single file
./vendor/bin/sail artisan pint                                  # code style fix
./vendor/bin/sail artisan tinker
./vendor/bin/sail npm run dev                                   # Vite dev server
./vendor/bin/sail npm run build                                 # production assets
```

The `composer.json` `dev` script (`composer run dev`) invokes bare `php artisan serve`/`npm run dev` outside any container — don't use it; it's a leftover from the Laravel skeleton and bypasses Sail.

## Architecture

**Auth is username-based, not email-based.** The `users` table (see `database/migrations/0001_01_01_000000_create_users_table.php`) has no `email` column and no `password_reset_tokens` table — there is no self-service registration or password-reset flow by design (accounts are provisioned by an admin; a disabled account must be reset by an admin manually). `App\Models\User` casts `is_active`/`last_login_at` and uses spatie's `HasRoles` trait for role/permission checks.

**Identity is split into two layers, deliberately:**
- *Login identity* (`users` table + spatie roles/permissions) answers "can this account do X at all."
- *Business identity* (`students`/`teachers` tables) answers "which real person/class does this account represent." A `User` optionally links to at most one `Student` (only 副班長 have accounts) or one `Teacher` via a nullable, unique `user_id` FK on the child table — most students/teachers have no linked account. `App\Rules\UserAccountIsUnlinked` enforces at the validation layer that one account can never be linked to both a student and a teacher; `User::ownSchoolClass()` is the single place that resolves "which class does this account belong to" (student's class, or the teacher's most recent homeroom by academic year/semester) — reuse it rather than re-deriving the relation inline.
- Row-level scoping (e.g. "only for my own class") is **not** something spatie/laravel-permission handles — it only answers the role/permission question. Scoping is implemented in Laravel Policies (see `App\Policies\SchoolClassPolicy::recordAttendance`, wired onto `SchoolClass` via the `#[UsePolicy]` attribute) that check `$user->ownSchoolClass()` against the target resource.

**Livewire components are not re-authorized by route middleware after the initial page load — this is the single most important gotcha in this codebase.** Route middleware (`auth`, `role:admin`, `can:...`) only runs on the first full-page request. Subsequent `wire:click`/`wire:model` interactions hit Livewire's own `/livewire/update` endpoint, which Livewire re-applies only a small hardcoded allowlist of middleware to (`Illuminate\Auth\Middleware\Authenticate`/`Authorize`, `SubstituteBindings`, a few others — see `Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware::$persistentMiddleware`). Custom middleware like `EnsureAccountIsActive` and package middleware like spatie's `role`/`permission` are **not** in that list and silently do not get re-checked on interactions. `Livewire::test()` makes this worse to catch: it never exercises this mechanism at all (`PersistentMiddleware` explicitly skips "fake requests such as a test"), so a component can look fully protected in tests while being wide open to a mid-session role/permission change in production.
  - The fix used throughout this codebase: authorize *inside the component*, in a hook that reruns on every request, not just `mount()` (which only runs once). Use `boot()` for a single component (see `App\Livewire\Attendance\Recorder::boot()`, guarded with `isset($this->schoolClass)` since `boot()` fires *before* `mount()`'s own body on the very first load) or a trait with a `boot{TraitName}()` method for a check shared across components (see `App\Livewire\Concerns\RequiresAdminRole`, used by all four `Admin\*Manager` components). Both hooks fire on `mount()` **and** every subsequent `hydrate()` (i.e. every interaction), and — unlike route middleware persistence — they *do* run under `Livewire::test()`, so they're actually covered by the test suite.
  - A public Livewire array property (e.g. `Recorder::$statuses`, keyed by `student_id`) is also client-writable on every update request — validating only the *values* isn't enough. `Recorder::submit()` builds its write set by iterating the server-derived roster (`$this->students()`) and looking up each student's status from `$statuses`, rather than iterating `$statuses`' own keys, specifically to stop an extra injected key (a student id from another class) from being written.

**A nested `<livewire:...>` component keyed only by a record's ID goes stale when the parent re-renders with updated data for that same record — this cost real debugging time once already, don't repeat it.** Livewire treats a nested component matched by an unchanged `wire:key` as "the same already-mounted child" and does not force it to re-mount/re-fetch just because the parent recomputed the model passed into it; the child only refreshes when *its own* actions fire. Concretely: `resources/views/livewire/attendance/recorder.blade.php` keys `<livewire:attendance.follow-up-manager>` as `'follow-up-'.$record->id.'-'.$record->status->value` (not just the id) specifically so correcting a status forces the child to remount and reflect the record's current follow-ups — with only the id in the key, the exact same markup renders and the debug values in the parent's Blade context are correct, but the mounted child silently keeps showing what it showed when it was first mounted. If a future nested component depends on data that can change without the parent's own key inputs changing, extend the key (or find another explicit refresh path) rather than assuming a parent re-render is enough.

**`spatie/laravel-activitylog`'s `LogsActivity` trait lives at `Spatie\Activitylog\Models\Concerns\LogsActivity` in the installed version**, not the `Spatie\Activitylog\Traits\LogsActivity` path shown in older docs/tutorials (same for `LogOptions`, at `Spatie\Activitylog\Support\LogOptions`) — get the import wrong and it's a "trait not found" error, not a subtler bug. Separately: `AttendanceRecord::upsert()` in `Recorder::submit()` is a bulk query-builder write and does **not** fire Eloquent's `saving`/`saved` events, so `LogsActivity`'s automatic dirty-attribute logging never triggers for it — the audit trail for status changes is written manually instead (`Recorder::logStatusChanges()`, using the `activity()` helper directly, wrapped together with the upsert in one `DB::transaction()` with `lockForUpdate()` on the "previous state" read so a corrected status and its audit entry can't diverge under concurrent submits). If you add another bulk-write path against an audited model, it needs the same manual-logging treatment — the trait alone won't catch it.

**Routing/auth middleware is applied per route group in `routes/web.php`**, not globally in `bootstrap/app.php`. New route groups must explicitly wrap protected routes in `Route::middleware('auth')`; the login page (`/`) is the only route under `guest` middleware and must stay the only thing an unauthenticated visitor can reach.

**Database:** MariaDB via the dedicated `mariadb` Laravel connection (`DB_CONNECTION=mariadb` in `.env`), not the `mysql` driver — `config/database.php` already ships both. `spatie/laravel-permission` and `spatie/laravel-activitylog` migrations have already been published into `database/migrations/` (`create_permission_tables.php`, `create_activity_log_table.php`) — don't re-publish them.

**Frontend:** Blade + Livewire 4 + Tailwind CSS 4 (via `@tailwindcss/vite`). No SPA/API layer — Sanctum is not installed. The shared layout is an anonymous Blade component at `resources/views/components/layouts/app.blade.php` (`<x-layouts.app>`), which already includes `@livewireStyles`/`@livewireScripts`.

**Attendance status is a native PHP backed enum** (`App\Enums\AttendanceStatus`, values like `PRESENT`/`LATE`/`ABSENT`), cast on `AttendanceRecord::status` — not a DB-level enum column, so adding a status only touches the enum class. **`attendance_sessions.period` is deliberately a plain string, not an enum** (currently only `MORNING`/`NOON`/`AFTERNOON` are valid, enforced at the application layer in `Recorder::PERIODS`) — this is intentional flexibility for a possible future move to per-period attendance without a schema change; don't "fix" it into a stricter type without checking system_structure.md's rationale first. `attendance_sessions` existing for a given `(school_class_id, date, period)` *is* the "attendance was taken" signal — there's no separate submitted/draft flag.

**Production deployment is a separate, minimal Docker setup behind `cloudflared`** (see system_structure.md) — it does not use Sail's `compose.yaml`, which is dev-only.
