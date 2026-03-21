# Changelog

All notable changes to `laravel-notify-africa` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- README and `composer.json` authors credit [iPF Softwares](https://github.com/iPFSoftwares) for the Notify Africa SMS API; documented suggested wording for release tags and GitHub Releases.

## [1.0.0] - 2026-03-21

### Added

- Initial stable release: Notify Africa SMS integration for Laravel (PHP ^8.4, `illuminate/*` ^11 through ^13).
- HTTP client for single send, batch send, and message status lookup using Laravel’s HTTP client and Bearer auth.
- `config/notify-africa.php` for API token, sender ID, base URL, timeouts, optional default country calling code, and optional HTTP retries.
- Fluent `NotifyAfricaMessage` builder and readonly response DTOs (`SendSmsResponse`, `BulkSmsResponse`, `MessageStatusResponse`).
- Custom exceptions: `NotifyAfricaAuthenticationException`, `NotifyAfricaValidationException`, `NotifyAfricaRequestException` (base `NotifyAfricaException`).
- `LaravelNotifyAfrica` entry service, `LaravelNotifyAfrica` facade, and `NotifyAfricaChannel` for `toNotifyAfrica()` / `routeNotificationForNotifyAfrica()`.
- `PhoneNumberNormalizer` for international-style numbers without a leading `+`.
- Optional transient HTTP retries (`http_retry_attempts`, `http_retry_delay_ms` and matching `NOTIFY_AFRICA_*` env vars).
- Container alias `notify-africa` for `TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica`.
- Pest tests with `Http::fake` and CI workflow (Laravel 13, PHP 8.4).

### Changed

- Validation and API error messages use a consistent `[Notify Africa]` prefix where helpful.
