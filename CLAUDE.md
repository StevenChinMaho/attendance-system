# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

國中校園點名系統 (a junior-high-school attendance system). Full business context, permission model, and database ERD live in [system_structure.md](system_structure.md) — read it before touching schema, roles, or attendance business logic. This project is early-stage: only the account/login layer exists so far; the attendance domain (school classes, students, teachers, attendance sessions/records) described in system_structure.md is not yet implemented.

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
- *Business identity* (`students`/`teachers` tables, not yet created) answers "which real person/class does this account represent." A `User` optionally links to at most one `Student` (only 副班長 have accounts) or one `Teacher` via a nullable, unique `user_id` FK on the child table — most students/teachers have no linked account. `User::student()`/`User::teacher()` relations are already declared on the model even though the `Student`/`Teacher` models don't exist yet — this is intentional forward scaffolding, not a bug.
- Row-level scoping (e.g. "only for my own class") is **not** something spatie/laravel-permission handles — it only answers the role/permission question. Scoping must be implemented in Laravel Policies that check `$user->student->school_class_id` / `$user->teacher->school_class_id` against the target resource.

**Routing/auth middleware is applied per route group in `routes/web.php`**, not globally in `bootstrap/app.php`. New route groups must explicitly wrap protected routes in `Route::middleware('auth')`; the login page (`/`) is the only route under `guest` middleware and must stay the only thing an unauthenticated visitor can reach.

**Database:** MariaDB via the dedicated `mariadb` Laravel connection (`DB_CONNECTION=mariadb` in `.env`), not the `mysql` driver — `config/database.php` already ships both. `spatie/laravel-permission` and `spatie/laravel-activitylog` migrations have already been published into `database/migrations/` (`create_permission_tables.php`, `create_activity_log_table.php`) — don't re-publish them.

**Frontend:** Blade + Livewire 4 + Tailwind CSS 4 (via `@tailwindcss/vite`). No SPA/API layer — Sanctum is not installed. The shared layout is an anonymous Blade component at `resources/views/components/layouts/app.blade.php` (`<x-layouts.app>`), which already includes `@livewireStyles`/`@livewireScripts`.

**Production deployment is a separate, minimal Docker setup behind `cloudflared`** (see system_structure.md) — it does not use Sail's `compose.yaml`, which is dev-only.
