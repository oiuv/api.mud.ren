# Repository Guidelines

## Project Structure & Architecture

This is the mud.ren forum API, using Laravel 6, PHP 7.4, MySQL, Passport, and Elasticsearch 6 with the IK plugin. Keep runtime and dependency upgrades separate from routine fixes.

Models live directly in `app/` (`Thread.php`, `User.php`, `Content.php`). Controllers and resources are in `app/Http/`; policies, validators, observers, and jobs have their own directories under `app/`. Schema changes and fixtures belong in `database/migrations/` and `database/factories/`. Tests live in `tests/Feature/` and `tests/Unit/`.

`routes/api.php` exposes routes without an `/api` prefix, such as `/threads` and `/user/reset-password`. Search configuration is in `config/scout.php`, with custom result mapping in `app/Services/EsEngine.php`.

## Development Commands

- `composer install`: install the locked dependencies, including development tools.
- `php artisan serve`: start the local API after configuring development services.
- `php vendor/bin/phpunit`: run the regression suite; PDO SQLite is required.
- `php vendor/bin/php-cs-fixer fix --config=.php_cs --dry-run app/Thread.php`: check a changed file. Omit `--dry-run` to format it.

See [readme.md](readme.md) for installation, service configuration, and deployment.

## Coding Style & Naming

Follow `.editorconfig`: UTF-8, LF, four spaces for PHP, and two for YAML. Use PascalCase classes, camelCase methods, and descriptive `*Controller`, `*Policy`, and `*Test` names. Follow the Symfony-based rules in `.php_cs`; restrict formatting to changed files.

## Testing Guidelines

Use PHPUnit 8 and name test files `*Test.php`. Extend `Tests\TestCase` for API tests. The bootstrap forces SQLite in memory and skips local environment files and production configuration caches. Keep mail, queues, CAPTCHA, and Elasticsearch isolated; avoid real services in tests. Cover authorization, invalid input, and normal behavior for security-sensitive changes. No numeric coverage threshold is configured.

## Security & Integration

Authorize the actual target model and allowlist writable fields. Thread edits use `saveWithContent`; public reads must not publish drafts or write request content. Preserve publication checks when mapping search hits.

RAG reads many posts per query: retain the removal of the shared API quota while preserving authentication, CAPTCHA, and posting-frequency checks. Never commit credentials, `.env`, or Passport private keys.

## Commits & Pull Requests

Use concise, behavior-focused messages; recent fixes use `fix:` with Chinese descriptions. Describe the problem, resulting behavior, tests, and deployment steps in pull requests. Link related issues when available.
