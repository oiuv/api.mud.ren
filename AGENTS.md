# Repository Guidelines

## Project Structure & Architecture

This is the mud.ren forum API, using Laravel 13, PHP 8.3+, MySQL 5.7.40+, and Passport 13. Keep runtime and dependency upgrades separate from routine fixes.

Models live directly in `app/` (`Thread.php`, `User.php`, `Content.php`). Controllers and resources are in `app/Http/`; policies, validators, observers, and jobs have their own directories under `app/`. Schema changes and fixtures belong in `database/migrations/` and `database/factories/`. Tests live in `tests/Feature/` and `tests/Unit/`.

`routes/api.php` exposes routes without an `/api` prefix, such as `/threads` and `/user/reset-password`. Literal database search and safe highlights live in `app/Services/ThreadSearch.php`; no search server is required. Keep historical model namespaces and integer OAuth client IDs. Social login and Qiniu are unused and removed; retain email and password login.

## Development Commands

- `composer install`: install the locked dependencies, including development tools.
- `php artisan serve`: start the local API after configuring development services.
- `php vendor/bin/phpunit`: run the regression suite; PDO SQLite is required.
- `php tests/runtime-smoke.php`: verify route/config cache generation and cached startup with isolated configuration.
- `php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --dry-run app/Thread.php`: check a changed file. Omit `--dry-run` to format it.

See [readme.md](readme.md) for installation, service configuration, and deployment.

## Coding Style & Naming

Follow `.editorconfig`: UTF-8, LF, four spaces for PHP, and two for YAML. Use PascalCase classes, camelCase methods, and descriptive `*Controller`, `*Policy`, and `*Test` names. Follow the Symfony-based rules in `.php-cs-fixer.php`; restrict formatting to changed files.

## Testing Guidelines

Use PHPUnit 12 and name test files `*Test.php`. Extend `Tests\TestCase` for API tests; use class-based factories under `Database\Factories`. The bootstrap forces SQLite in memory, generates ephemeral Passport keys, and skips local environment files and production configuration caches. Keep mail, queues and CAPTCHA isolated; avoid real services in PHPUnit tests. `tests/mysql-smoke.php` is an explicit check for a disposable MySQL 5.7.40 container only; see the upgrade guide. Cover authorization, invalid input, and normal behavior for security-sensitive changes. No numeric coverage threshold is configured.

## Security & Integration

Authorize the actual target model and allowlist writable fields. Thread edits use `saveWithContent`; public reads must not publish drafts or write request content. Apply publication checks to both search results and their total count. Preserve existing application/Passport keys during upgrades; client-secret hashing is irreversible, so rollback requires the database backup.

RAG reads many posts per query: retain the removal of the shared API quota while preserving authentication, CAPTCHA, and posting-frequency checks. Never commit credentials, `.env`, or Passport private keys.

## Commits & Pull Requests

Use concise, behavior-focused messages; recent fixes use `fix:` with Chinese descriptions. Describe the problem, resulting behavior, tests, and deployment steps in pull requests. Link related issues when available.
