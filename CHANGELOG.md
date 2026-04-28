# Changelog

All notable changes to Peanut Connect will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.5.0] - 2026-04-28

### Added
- Marketing surface in wp-admin: Campaigns (campaign builder), UTMs, Links, Analytics, Tracking Setup pages.
- `Peanut_Connect_Marketing` server-side proxy at `/peanut-connect/v1/marketing/*` that forwards authenticated requests to the connected Hub install. The Hub API key never leaves the server.
- Tracking Setup page renders the connected Hub URL, masked Site Key, and the two GTM tag snippets (tracker loader + conversion fire) ready to paste.

## [3.4.1] - 2026-03-30

### Fixed
- Security Hardening toggles not working due to double-unwrapped API response in securityApi.get()

## [3.2.2] - 2026-02-02

### Fixed
- SSL detection now actually checks for certificate instead of relying on WordPress URL setting
- Sites with SSL certificates now correctly show as SSL enabled even if WordPress URL is http://

## [2.6.3] - 2026-01-11

### Added
- Rate limiting on tracking endpoints (track, identify, conversion, popup-interaction) to prevent abuse
- Daily cleanup cron job for synced records older than 90 days

### Changed
- Tracking endpoint rate limits: 120/min for events, 60/min for popups, 30/min for identify/conversion

## [2.6.2] - 2026-01-11

### Fixed
- Hub Mode disable_suite now fully disables Suite at file load time (early filter registration)

## [2.6.1] - 2026-01-11

### Fixed
- Hub Mode hide_suite now correctly hides Peanut Suite menu (was using wrong menu slug)

## [2.6.0] - 2026-01-11

### Added
- Hub Mode feature to control Peanut Suite behavior when connected to Hub
  - Standard: Suite works normally alongside Hub
  - Hide Suite Menu: Hides Peanut Suite from admin menu
  - Disable Suite: Fully disables Peanut Suite
- New `/settings/hub/mode` API endpoint

## [Unreleased]

### Added
- Rate limiting for API endpoints to prevent abuse
- Protected error log directory with .htaccess
- PHPUnit test infrastructure with Auth and Rate Limiter tests
- Vitest frontend testing setup
- OpenAPI 3.0 documentation
- React error boundary for graceful error handling
- Server-side activity logging
- Comprehensive inline documentation

### Changed
- Improved SQL query security with parameterized LIKE patterns
- Enhanced error log storage location (now in protected directory)

### Security
- Added rate limiting to bearer token authentication
- Parameterized SQL queries in self-updater cache clearing
- Protected error log files from direct web access

## [2.1.3] - 2024-12-31

### Added
- Release automation scripts (bump, package, release)
- npm scripts for version management
- GitHub release automation

### Fixed
- Version sync across all plugin files
- Duplicate file cleanup from iCloud sync

## [2.1.2] - 2024-12-30

### Changed
- Self-updater uses path parameters instead of query strings
- Improved update check caching

### Fixed
- Self-updater initialization timing for reliable update detection

## [2.1.1] - 2024-12-30

### Added
- React SPA admin interface
- Dashboard with connection status and health summary
- Health monitoring page with detailed system information
- Updates page for managing plugin/theme/core updates
- Error log viewer with filtering and export
- Settings page with permission controls
- Activity log (client-side)

### Changed
- Moved from legacy settings page to modern React interface
- Improved health check data collection

## [2.0.0] - 2024-12-29

### Added
- Error logging system for PHP errors and fatals
- Self-hosted auto-update system via peanutgraphic.com
- Peanut Suite integration for analytics sync

### Changed
- Major refactoring of API structure
- Improved authentication flow

## [1.0.0] - 2024-12-28

### Added
- Initial release
- Site health monitoring
- Plugin/theme update detection
- Remote update capability
- Secure token-based authentication
- Permission controls for manager actions
- Peanut Suite analytics integration

[Unreleased]: https://github.com/peanutgraphic/peanut-connect/compare/v2.1.3...HEAD
[2.1.3]: https://github.com/peanutgraphic/peanut-connect/compare/v2.1.2...v2.1.3
[2.1.2]: https://github.com/peanutgraphic/peanut-connect/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/peanutgraphic/peanut-connect/compare/v2.0.0...v2.1.1
[2.0.0]: https://github.com/peanutgraphic/peanut-connect/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/peanutgraphic/peanut-connect/releases/tag/v1.0.0
