# Changelog

All notable changes to `laravel-notify-africa` will be documented in this file.

## Unreleased

### Added

- Config options `http_retry_attempts` and `http_retry_delay_ms` (env: `NOTIFY_AFRICA_HTTP_RETRY_ATTEMPTS`, `NOTIFY_AFRICA_HTTP_RETRY_DELAY_MS`) for limited retries on connection errors and HTTP 408, 425, 429, and 5xx responses.
- Container alias `notify-africa` for `TechLegend\LaravelNotifyAfrica\LaravelNotifyAfrica`.

### Changed

- Clearer `InvalidArgumentException` and `NotifyAfricaRequestException` messages, including `[Notify Africa]` prefixes where helpful.
- README: auto-discovery note, HTTP retry documentation, env table updates, container alias example.

### Fixed

- n/a
