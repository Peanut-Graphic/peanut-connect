# Peanut End to End

> Plugin slug: `peanut-connect` · REST namespace: `peanut-connect/v1` · Brand: **Peanut End to End**
>
> The slug, namespace, and option keys remain `peanut-connect` for backwards compatibility with existing installs and the Peanut License Server / mu-plugin update flow. The product name is "Peanut End to End."

End-to-end campaign and site platform for WordPress. Installed on a client (or your own) WordPress site, it runs marketing campaigns and on-site experiences — UTM links, popups, event banners, form capture, first-party tracker, conversion funnels — and pairs that with site health monitoring, plugin/theme updates, and backups, all coordinated through a central Peanut Hub.

## What it actually does

**Campaign side (the marketing surface):**
- Campaign wizard with draft auto-save (localStorage) and step-by-step navigation
- UTM-tagged short links and QR codes
- Popups (modal / slide-in / bar / toast / fullscreen) and event banners, served server-side from the on-site agent so they survive ad-blockers
- First-party `tracker.js` (`phub` global) — pageviews, scroll, form submissions, conversion events
- Form capture with marketing-API proxy to Hub
- Analytics in-plugin — campaigns, journeys, conversion funnels, Sankey flows, devices, regions, time-series
- Demo seeder for populating realistic data during onboarding

**Site side (the original "connector" surface, still fully supported):**
- Site health (WordPress / PHP / MySQL versions, SSL, disk, memory)
- Plugin / theme / core update visibility and remote-trigger
- Backup integration
- ML-flavored anomaly detection on health metrics
- Security hardening: disable XML-RPC, hide WP version, custom login URL, disable comments
- Hide-login support
- Local error log + activity log

**Hub link (the connective tissue):**
- Marketing API proxy to Hub (campaigns, journeys, analytics live in Hub; Connect is the on-site executor)
- Hub request auth: HMAC-SHA256 request signing (key never transmitted, timestamp + single-use nonce for anti-replay), with a static Bearer fallback for sites/Hubs not yet emitting signed requests
- Hub Mode toggle: standard, hide-Suite, disable-Suite (controls how it coexists with Peanut Suite)
- Manual Hub-key onboarding flow as fallback when auto-pairing isn't available

## Requirements

- WordPress 6.0+
- PHP 8.0+
- A Peanut Hub installation to pair with (campaign authoring + cross-site rollups live there)

## Installation

### From WordPress Admin

1. Download `peanut-connect.zip`
2. Go to **Plugins > Add New > Upload Plugin**
3. Upload and activate the plugin
4. Navigate to **Settings > Peanut Connect**
5. Enter your Site Key and Manager URL

### Configuration

After activation, configure the plugin at **Settings > Peanut End to End** (menu may still read "Peanut Connect" until the SPA strings are migrated):

| Setting | Description |
|---------|-------------|
| **Site Key** | Unique key provided by your Peanut Hub |
| **Hub URL** | URL of your Peanut Hub installation |

## How It Works

1. **Authentication**: All requests from the manager site must include a valid site key
2. **Health Checks**: The manager periodically polls connected sites for health data
3. **Updates**: The manager can view and trigger plugin/theme updates remotely
4. **Analytics**: Site analytics can be synced to the central dashboard

## Security

- All Hub-facing API endpoints require authentication (HMAC-SHA256 signed request, or a static Bearer site key as fallback)
- The shared Hub key is held in `wp_options` and used as the HMAC signing secret — so signed requests never put the key on the wire. (It is stored as-is, not hashed, because an HMAC secret must be recoverable to verify a signature; protect it with database/transport security. A future hardening track is encryption-at-rest + a verification token distinct from the signing secret.)
- High-impact capabilities (remote updates, content publishing, remote restore, the outbound proxy) are gated by per-site permission flags that default to OFF — the owner opts in
- Communication should always use HTTPS
- Rate limiting prevents brute force attacks
- WordPress capabilities are checked for update operations

## REST API Endpoints

Base URL: `/wp-json/peanut-connect/v1`

### Authentication

All endpoints require the `Authorization` header with a Bearer token:

```
Authorization: Bearer <your-site-key>
```

Optionally, include the manager site URL:

```
X-Peanut-Manager: https://your-manager-site.com
```

### Health Check

```
GET /health
```

Returns comprehensive site health data:

```json
{
  "wordpress_version": "6.4.2",
  "php_version": "8.1.0",
  "mysql_version": "8.0.32",
  "ssl_enabled": true,
  "multisite": false,
  "active_theme": {
    "name": "Theme Name",
    "version": "1.0.0",
    "update_available": false
  },
  "plugins": {
    "active": 12,
    "inactive": 3,
    "updates_available": 2
  },
  "disk_space": {
    "total": "50GB",
    "used": "15GB",
    "free": "35GB",
    "percent_used": 30
  },
  "last_backup": "2024-01-15T10:30:00Z",
  "debug_mode": false,
  "memory_limit": "256M",
  "max_execution_time": 300
}
```

### Available Updates

```
GET /updates
```

Returns list of available plugin and theme updates:

```json
{
  "plugins": [
    {
      "slug": "plugin-name",
      "name": "Plugin Name",
      "current_version": "1.0.0",
      "new_version": "1.1.0"
    }
  ],
  "themes": [
    {
      "slug": "theme-name",
      "name": "Theme Name",
      "current_version": "2.0.0",
      "new_version": "2.1.0"
    }
  ],
  "core": {
    "current": "6.4.1",
    "new": "6.4.2",
    "available": true
  }
}
```

### Run Updates

```
POST /updates
Content-Type: application/json

{
  "type": "plugin",
  "items": ["plugin-slug-1", "plugin-slug-2"]
}
```

Triggers updates for specified plugins, themes, or core:

- `type`: `plugin`, `theme`, or `core`
- `items`: Array of slugs to update (ignored for core)

### Verify Connection

```
GET /verify
```

Simple endpoint to verify the site key is valid:

```json
{
  "status": "connected",
  "site_url": "https://example.com",
  "site_name": "Example Site"
}
```

## Directory Structure

```
peanut-connect/
├── peanut-connect.php           # Main plugin file
├── readme.txt                   # WordPress.org readme
├── README.md                    # This file
├── assets/
│   └── dist/                    # Built frontend assets
│       ├── js/main.js
│       └── css/main.css
├── includes/
│   ├── class-connect-auth.php   # Authentication handler
│   ├── class-connect-health.php # Health data collection
│   ├── class-connect-updates.php # Update management
│   └── class-connect-api.php    # REST API endpoints
└── frontend/                    # React SPA source code
    ├── src/
    │   ├── components/          # Reusable UI components
    │   ├── pages/               # Page components
    │   ├── api/                 # API client and endpoints
    │   ├── contexts/            # React contexts (theme)
    │   ├── services/            # Activity logging, etc.
    │   ├── utils/               # Export utilities
    │   └── types/               # TypeScript definitions
    └── package.json
```

## Admin Dashboard Features

The plugin includes a modern React-based admin dashboard with:

### Pages

| Page | Description |
|------|-------------|
| **Dashboard** | Connection status, health summary, quick stats |
| **Health** | Detailed health metrics with scores and recommendations |
| **Updates** | Available plugin/theme/core updates with one-click updates |
| **Activity** | Local activity log tracking health checks, updates, etc. |
| **Settings** | Connection settings, permissions, danger zone actions |

### UI Features

- **Skeleton Loading** - Smooth loading states instead of spinners
- **Tooltips** - Contextual help throughout the interface
- **Info Panels** - Collapsible explanations for each section
- **Security Alerts** - Visual warnings for security issues
- **Danger Zones** - Protected destructive actions with confirmations
- **Health Score** - Calculated score (0-100) based on site health
- **Export Reports** - Export health data as JSON, text, HTML, or PDF
- **Dark Mode** - Toggle between light, dark, and system theme
- **Activity Log** - Track health checks, updates, and connection events

### Frontend Development

To build the frontend:

```bash
cd frontend
npm install
npm run build
```

The build outputs to `assets/dist/` which is served by WordPress.

For development with hot reload:

```bash
npm run dev
```

### Tech Stack

- React 19 with TypeScript
- Vite for build tooling
- Tailwind CSS 4.0 for styling
- React Query for data fetching
- React Router for navigation
- date-fns for date formatting
- Lucide React for icons

## Hooks & Filters

### Actions

```php
// Fired when a health check is performed
do_action('peanut_connect_health_check', $health_data);

// Fired before updates are applied
do_action('peanut_connect_before_updates', $updates);

// Fired after updates complete
do_action('peanut_connect_after_updates', $results);
```

### Filters

```php
// Modify health data before sending
add_filter('peanut_connect_health_data', function($data) {
    $data['custom_metric'] = get_custom_metric();
    return $data;
});

// Control which plugins can be updated remotely
add_filter('peanut_connect_allowed_plugins', function($plugins) {
    // Remove specific plugins from remote update capability
    unset($plugins['critical-plugin/critical-plugin.php']);
    return $plugins;
});
```

## Troubleshooting

### Connection Issues

1. **Invalid Site Key**: Verify the key matches exactly what's shown in Peanut Suite
2. **SSL Errors**: Ensure both sites use valid SSL certificates
3. **Firewall Blocking**: Check if your hosting blocks REST API requests

### Health Check Failures

1. **Timeout**: Increase `max_execution_time` in PHP settings
2. **Memory Issues**: Increase `memory_limit` in PHP settings
3. **Permission Errors**: Ensure WordPress can read plugin/theme directories

### Update Failures

1. **File Permissions**: WordPress needs write access to `wp-content`
2. **Disk Space**: Ensure sufficient free disk space
3. **Plugin Conflicts**: Some security plugins may block file modifications

## Uninstallation

When the plugin is deleted (not just deactivated):

1. All stored settings are removed
2. The site key hash is deleted
3. No data is left in the database

## Support

For support, please contact your Peanut Suite administrator or visit the main Peanut Suite documentation.

## Changelog

See [CHANGELOG.md](./CHANGELOG.md) for the full history. The plugin is currently at **3.7.9** (April 2026); the changelog above had been left at 1.x and was retired during the **Peanut End to End** rebrand.

## License

GPL v2 or later
