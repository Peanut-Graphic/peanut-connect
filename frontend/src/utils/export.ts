import type { HealthData } from '@/types';
import type { Utm } from '@/api/marketing';

const UTM_CSV_COLUMNS = [
  'name',
  'source',
  'medium',
  'target_url',
  'full_url',
  'campaign',
  'clicks',
] as const;

// RFC 4180: wrap every field in quotes and double any embedded quote.
function csvField(value: string | number): string {
  return `"${String(value).replace(/"/g, '""')}"`;
}

// Build an RFC 4180 CSV for the given UTMs. Pure (no DOM) so it can be
// unit-tested; an empty list still yields the header row.
export function buildUtmCsv(utms: Utm[]): string {
  const header = UTM_CSV_COLUMNS.map(csvField).join(',');
  const rows = utms.map((u) =>
    [
      u.name,
      u.utm_source,
      u.utm_medium,
      u.base_url,
      u.full_url,
      u.utm_campaign,
      u.click_count ?? 0,
    ]
      .map(csvField)
      .join(',')
  );
  return [header, ...rows].join('\r\n');
}

// Download the selected UTMs as a CSV file.
export function exportUtmsCsv(utms: Utm[], filename = 'utms'): void {
  const blob = new Blob([buildUtmCsv(utms)], { type: 'text/csv;charset=utf-8' });
  downloadBlob(blob, `${filename}-${formatDateForFilename()}.csv`);
}

// Export health data as JSON file
export function exportAsJson(data: HealthData, filename: string = 'health-report'): void {
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
  downloadBlob(blob, `${filename}-${formatDateForFilename()}.json`);
}

// Export health data as formatted text report
export function exportAsText(data: HealthData, siteUrl: string = ''): void {
  const report = generateTextReport(data, siteUrl);
  const blob = new Blob([report], { type: 'text/plain' });
  downloadBlob(blob, `health-report-${formatDateForFilename()}.txt`);
}

// Export health data as HTML (can be printed to PDF)
export function exportAsHtml(data: HealthData, siteUrl: string = ''): void {
  const html = generateHtmlReport(data, siteUrl);
  const blob = new Blob([html], { type: 'text/html' });
  downloadBlob(blob, `health-report-${formatDateForFilename()}.html`);
}

// Open print dialog for PDF export
export function printReport(data: HealthData, siteUrl: string = ''): void {
  const html = generateHtmlReport(data, siteUrl);
  const printWindow = window.open('', '_blank');
  if (printWindow) {
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.onload = () => {
      printWindow.print();
    };
  }
}

function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

function formatDateForFilename(): string {
  const now = new Date();
  return now.toISOString().split('T')[0];
}

function generateTextReport(data: HealthData, siteUrl: string): string {
  const lines: string[] = [
    '═══════════════════════════════════════════════════════════════',
    '                    SITE HEALTH REPORT',
    '═══════════════════════════════════════════════════════════════',
    '',
    `Generated: ${new Date().toLocaleString()}`,
    siteUrl ? `Site URL: ${siteUrl}` : '',
    '',
    '───────────────────────────────────────────────────────────────',
    '                    CORE INFORMATION',
    '───────────────────────────────────────────────────────────────',
    '',
    `WordPress Version: ${data.wp_version.version} ${data.wp_version.needs_update ? `(Update available: ${data.wp_version.latest_version})` : '(Up to date)'}`,
    `PHP Version: ${data.php_version.version} ${data.php_version.recommended ? '(Recommended)' : '(Consider upgrading)'}`,
    `Debug Mode: ${data.debug_mode ? 'Enabled ⚠️' : 'Disabled ✅'}`,
    '',
    '───────────────────────────────────────────────────────────────',
    '                       SECURITY',
    '───────────────────────────────────────────────────────────────',
    '',
    `SSL Certificate: ${data.ssl.enabled ? 'Enabled ✅' : 'Not Enabled ❌'}`,
  ];

  if (data.ssl.enabled) {
    lines.push(`  - Valid: ${data.ssl.valid ? 'Yes' : 'No'}`);
    if (data.ssl.days_until_expiry !== null) {
      lines.push(`  - Days until expiry: ${data.ssl.days_until_expiry}`);
    }
    if (data.ssl.issuer) {
      lines.push(`  - Issuer: ${data.ssl.issuer}`);
    }
  }

  lines.push('');
  lines.push(`File Permissions: ${data.file_permissions.secure ? 'Secure ✅' : 'Issues Found ⚠️'}`);

  if (data.file_permissions.checks.wp_config) {
    lines.push(`  - wp-config.php: ${data.file_permissions.checks.wp_config.permissions} ${data.file_permissions.checks.wp_config.secure ? '✅' : '❌'}`);
  }
  if (data.file_permissions.checks.htaccess) {
    lines.push(`  - .htaccess: ${data.file_permissions.checks.htaccess.permissions} ${data.file_permissions.checks.htaccess.secure ? '✅' : '❌'}`);
  }

  lines.push('');
  lines.push('───────────────────────────────────────────────────────────────');
  lines.push('                    PLUGINS & THEMES');
  lines.push('───────────────────────────────────────────────────────────────');
  lines.push('');
  lines.push(`Plugins: ${data.plugins.total} total (${data.plugins.active} active, ${data.plugins.inactive} inactive)`);
  lines.push(`  - Updates available: ${data.plugins.updates_available}`);

  if (data.plugins.needing_update.length > 0) {
    lines.push('  - Plugins needing updates:');
    data.plugins.needing_update.forEach(p => {
      lines.push(`    • ${p.name}: ${p.version} → ${p.new_version}`);
    });
  }

  lines.push('');
  lines.push(`Themes: ${data.themes.total} total`);
  lines.push(`  - Active theme: ${data.themes.active} (v${data.themes.active_version})`);
  lines.push(`  - Updates available: ${data.themes.updates_available}`);

  if (data.themes.needing_update.length > 0) {
    lines.push('  - Themes needing updates:');
    data.themes.needing_update.forEach(t => {
      lines.push(`    • ${t.name}: ${t.version} → ${t.new_version}`);
    });
  }

  lines.push('');
  lines.push('───────────────────────────────────────────────────────────────');
  lines.push('                       SERVER');
  lines.push('───────────────────────────────────────────────────────────────');
  lines.push('');
  lines.push(`Server Software: ${data.server.software}`);
  lines.push(`PHP SAPI: ${data.server.php_sapi}`);
  lines.push(`Memory Limit: ${data.server.memory_limit}`);
  lines.push(`Max Execution Time: ${data.server.max_execution_time}`);
  lines.push(`Max Upload Size: ${data.server.max_upload_size_formatted}`);
  lines.push('');
  lines.push('PHP Extensions:');
  Object.entries(data.server.php_extensions).forEach(([ext, enabled]) => {
    lines.push(`  - ${ext}: ${enabled ? 'Installed ✅' : 'Not installed ❌'}`);
  });

  lines.push('');
  lines.push('───────────────────────────────────────────────────────────────');
  lines.push('                      DATABASE');
  lines.push('───────────────────────────────────────────────────────────────');
  lines.push('');
  lines.push(`Database Size: ${data.database.size_formatted}`);
  lines.push(`Tables: ${data.database.tables_count}`);
  lines.push(`Table Prefix: ${data.database.prefix}`);

  if (data.disk_space.available) {
    lines.push('');
    lines.push('───────────────────────────────────────────────────────────────');
    lines.push('                     DISK SPACE');
    lines.push('───────────────────────────────────────────────────────────────');
    lines.push('');
    lines.push(`Total: ${data.disk_space.total_formatted}`);
    lines.push(`Used: ${data.disk_space.used_formatted} (${data.disk_space.used_percent}%)`);
    lines.push(`Free: ${data.disk_space.free_formatted}`);
  }

  lines.push('');
  lines.push('───────────────────────────────────────────────────────────────');
  lines.push('                       BACKUPS');
  lines.push('───────────────────────────────────────────────────────────────');
  lines.push('');
  lines.push(`Backup Plugin: ${data.backup.plugin_detected || 'None detected ⚠️'}`);
  if (data.backup.last_backup) {
    lines.push(`Last Backup: ${data.backup.last_backup}`);
  }

  if (data.peanut_suite) {
    lines.push('');
    lines.push('───────────────────────────────────────────────────────────────');
    lines.push('                    PEANUT SUITE');
    lines.push('───────────────────────────────────────────────────────────────');
    lines.push('');
    lines.push(`Version: ${data.peanut_suite.version}`);
    lines.push(`Modules: ${data.peanut_suite.modules.join(', ')}`);
  }

  lines.push('');
  lines.push('═══════════════════════════════════════════════════════════════');
  lines.push('           Generated by End-to-End');
  lines.push('═══════════════════════════════════════════════════════════════');

  return lines.filter(l => l !== undefined).join('\n');
}

function generateHtmlReport(data: HealthData, siteUrl: string): string {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Site Health Report - ${new Date().toLocaleDateString()}</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #1e293b; padding: 40px; max-width: 800px; margin: 0 auto; }
    h1 { font-size: 24px; margin-bottom: 8px; color: #0f172a; }
    h2 { font-size: 18px; margin: 24px 0 12px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; color: #334155; }
    .meta { color: #64748b; font-size: 14px; margin-bottom: 24px; }
    .section { margin-bottom: 24px; }
    .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
    .card { background: #f8fafc; border-radius: 8px; padding: 16px; border: 1px solid #e2e8f0; }
    .card-title { font-weight: 600; font-size: 14px; color: #475569; margin-bottom: 8px; }
    .card-value { font-size: 20px; font-weight: 700; color: #0f172a; }
    .card-detail { font-size: 12px; color: #64748b; margin-top: 4px; }
    .status-ok { color: #16a34a; }
    .status-warn { color: #d97706; }
    .status-error { color: #dc2626; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
    .badge-ok { background: #dcfce7; color: #166534; }
    .badge-warn { background: #fef3c7; color: #92400e; }
    .badge-error { background: #fee2e2; color: #991b1b; }
    table { width: 100%; border-collapse: collapse; margin: 12px 0; }
    th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
    th { background: #f1f5f9; font-weight: 600; color: #475569; }
    .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; text-align: center; color: #94a3b8; font-size: 12px; }
    @media print { body { padding: 20px; } }
  </style>
</head>
<body>
  <h1>Site Health Report</h1>
  <p class="meta">
    Generated: ${new Date().toLocaleString()}<br>
    ${siteUrl ? `Site: ${siteUrl}` : ''}
  </p>

  <h2>Core Information</h2>
  <div class="grid">
    <div class="card">
      <div class="card-title">WordPress Version</div>
      <div class="card-value">${data.wp_version.version}</div>
      <div class="card-detail">
        ${data.wp_version.needs_update
          ? `<span class="badge badge-warn">Update available: ${data.wp_version.latest_version}</span>`
          : '<span class="badge badge-ok">Up to date</span>'}
      </div>
    </div>
    <div class="card">
      <div class="card-title">PHP Version</div>
      <div class="card-value">${data.php_version.version}</div>
      <div class="card-detail">
        ${data.php_version.recommended
          ? '<span class="badge badge-ok">Recommended</span>'
          : '<span class="badge badge-warn">Consider upgrading</span>'}
      </div>
    </div>
  </div>

  <h2>Security</h2>
  <div class="grid">
    <div class="card">
      <div class="card-title">SSL Certificate</div>
      <div class="card-value ${data.ssl.enabled ? 'status-ok' : 'status-error'}">${data.ssl.enabled ? 'Enabled' : 'Not Enabled'}</div>
      ${data.ssl.days_until_expiry !== null ? `<div class="card-detail">Expires in ${data.ssl.days_until_expiry} days</div>` : ''}
    </div>
    <div class="card">
      <div class="card-title">File Permissions</div>
      <div class="card-value ${data.file_permissions.secure ? 'status-ok' : 'status-warn'}">${data.file_permissions.secure ? 'Secure' : 'Issues Found'}</div>
    </div>
    <div class="card">
      <div class="card-title">Debug Mode</div>
      <div class="card-value ${data.debug_mode ? 'status-warn' : 'status-ok'}">${data.debug_mode ? 'Enabled' : 'Disabled'}</div>
      ${data.debug_mode ? '<div class="card-detail">Should be disabled in production</div>' : ''}
    </div>
    <div class="card">
      <div class="card-title">Backup Plugin</div>
      <div class="card-value ${data.backup.plugin_detected ? 'status-ok' : 'status-warn'}">${data.backup.plugin_detected || 'None'}</div>
    </div>
  </div>

  <h2>Plugins & Themes</h2>
  <div class="grid">
    <div class="card">
      <div class="card-title">Plugins</div>
      <div class="card-value">${data.plugins.total}</div>
      <div class="card-detail">${data.plugins.active} active, ${data.plugins.inactive} inactive</div>
      ${data.plugins.updates_available > 0 ? `<div class="card-detail"><span class="badge badge-warn">${data.plugins.updates_available} updates available</span></div>` : ''}
    </div>
    <div class="card">
      <div class="card-title">Themes</div>
      <div class="card-value">${data.themes.total}</div>
      <div class="card-detail">Active: ${data.themes.active}</div>
      ${data.themes.updates_available > 0 ? `<div class="card-detail"><span class="badge badge-warn">${data.themes.updates_available} updates available</span></div>` : ''}
    </div>
  </div>

  ${data.plugins.needing_update.length > 0 ? `
  <h3 style="font-size: 14px; margin: 16px 0 8px; color: #475569;">Plugins Needing Updates</h3>
  <table>
    <thead>
      <tr><th>Plugin</th><th>Current</th><th>Available</th></tr>
    </thead>
    <tbody>
      ${data.plugins.needing_update.map(p => `<tr><td>${p.name}</td><td>${p.version}</td><td>${p.new_version}</td></tr>`).join('')}
    </tbody>
  </table>
  ` : ''}

  <h2>Server</h2>
  <div class="grid">
    <div class="card">
      <div class="card-title">Server Software</div>
      <div class="card-value" style="font-size: 14px;">${data.server.software}</div>
    </div>
    <div class="card">
      <div class="card-title">Memory Limit</div>
      <div class="card-value">${data.server.memory_limit}</div>
    </div>
    <div class="card">
      <div class="card-title">Max Upload Size</div>
      <div class="card-value">${data.server.max_upload_size_formatted}</div>
    </div>
    <div class="card">
      <div class="card-title">Database Size</div>
      <div class="card-value">${data.database.size_formatted}</div>
      <div class="card-detail">${data.database.tables_count} tables</div>
    </div>
  </div>

  <h3 style="font-size: 14px; margin: 16px 0 8px; color: #475569;">PHP Extensions</h3>
  <table>
    <thead>
      <tr><th>Extension</th><th>Status</th></tr>
    </thead>
    <tbody>
      ${Object.entries(data.server.php_extensions).map(([ext, enabled]) => `
        <tr>
          <td>${ext}</td>
          <td><span class="badge ${enabled ? 'badge-ok' : 'badge-error'}">${enabled ? 'Installed' : 'Not Installed'}</span></td>
        </tr>
      `).join('')}
    </tbody>
  </table>

  ${data.disk_space.available ? `
  <h2>Disk Space</h2>
  <div class="grid">
    <div class="card">
      <div class="card-title">Total</div>
      <div class="card-value">${data.disk_space.total_formatted}</div>
    </div>
    <div class="card">
      <div class="card-title">Used</div>
      <div class="card-value">${data.disk_space.used_formatted}</div>
      <div class="card-detail">${data.disk_space.used_percent}% used</div>
    </div>
  </div>
  ` : ''}

  <div class="footer">
    Generated by End-to-End
  </div>
</body>
</html>`;
}
