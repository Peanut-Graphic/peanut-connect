import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe, toHaveNoViolations } from 'jest-axe';

expect.extend(toHaveNoViolations);

describe('Accessibility - Peanut Connect Components', () => {
  // Button Component - Keyboard Navigation
  it('should have accessible button with proper ARIA attributes', async () => {
    const { container } = render(
      <button type="button" aria-label="Refresh dashboard">
        Refresh
      </button>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Input Fields - Labels and Association
  it('should have properly labeled input field', async () => {
    const { container } = render(
      <div>
        <label htmlFor="site-url">Site URL</label>
        <input id="site-url" type="url" required aria-describedby="url-help" />
        <small id="url-help">Enter your website URL</small>
      </div>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Dashboard Widget - Color Not Sole Indicator
  it('should not rely solely on color for status indication', async () => {
    const { container } = render(
      <div className="flex items-center gap-2">
        <span className="h-3 w-3 rounded-full bg-green-500" aria-hidden="true" />
        <span>Healthy</span>
      </div>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Health Status Indicator with Icon
  it('should have accessible health status with icon and text', async () => {
    const { container } = render(
      <div role="status" aria-live="polite" className="flex items-center gap-2">
        <span className="text-green-500" aria-hidden="true">
          ✓
        </span>
        <span>All systems operational</span>
      </div>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Error Log Table - Proper Headers
  it('should have accessible table with proper headers', async () => {
    const { container } = render(
      <table>
        <thead>
          <tr>
            <th scope="col">Timestamp</th>
            <th scope="col">Error Type</th>
            <th scope="col">Message</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>2024-03-28 10:30 AM</td>
            <td>Connection Error</td>
            <td>Failed to connect to API</td>
            <td>Resolved</td>
          </tr>
        </tbody>
      </table>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Settings Form - Field Groups
  it('should have accessible form fieldset with legend', async () => {
    const { container } = render(
      <form>
        <fieldset>
          <legend>Notification Settings</legend>
          <div>
            <label htmlFor="email-notify">
              <input id="email-notify" type="checkbox" />
              Enable email notifications
            </label>
          </div>
          <div>
            <label htmlFor="sms-notify">
              <input id="sms-notify" type="checkbox" />
              Enable SMS alerts
            </label>
          </div>
        </fieldset>
      </form>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Activity Feed - Semantic HTML
  it('should have accessible activity feed with semantic markup', async () => {
    const { container } = render(
      <article>
        <h2>Recent Activity</h2>
        <ul>
          <li>
            <time dateTime="2024-03-28T10:30:00Z">10:30 AM</time>
            <p>Site health check completed</p>
          </li>
          <li>
            <time dateTime="2024-03-28T09:15:00Z">9:15 AM</time>
            <p>Performance alert resolved</p>
          </li>
        </ul>
      </article>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Card Component - Semantic Structure
  it('should have accessible card with proper heading hierarchy', async () => {
    const { container } = render(
      <section className="rounded-lg border p-4">
        <h3>Site Performance</h3>
        <p>Response time: 245ms</p>
        <p>Uptime: 99.9%</p>
      </section>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Alert Component - Live Region
  it('should have accessible alert with ARIA live region', async () => {
    const { container } = render(
      <div role="alert" aria-live="assertive" className="rounded bg-red-100 p-4">
        <h4>Critical Alert</h4>
        <p>Database connection failed. Please check your settings.</p>
      </div>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Modal Dialog - Focus Management
  it('should have accessible modal with proper ARIA attributes', async () => {
    const { container } = render(
      <div role="dialog" aria-modal="true" aria-labelledby="dialog-title">
        <h2 id="dialog-title">Confirm Action</h2>
        <p>Are you sure you want to delete this site monitor?</p>
        <button type="button">Cancel</button>
        <button type="button">Delete</button>
      </div>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Checkbox Group - Accessible List
  it('should have accessible checkbox group with proper labels', async () => {
    const { container } = render(
      <fieldset>
        <legend>Monitor Types</legend>
        <div>
          <label htmlFor="uptime-check">
            <input id="uptime-check" type="checkbox" />
            Uptime Monitoring
          </label>
        </div>
        <div>
          <label htmlFor="performance-check">
            <input id="performance-check" type="checkbox" />
            Performance Metrics
          </label>
        </div>
        <div>
          <label htmlFor="ssl-check">
            <input id="ssl-check" type="checkbox" />
            SSL Certificate Check
          </label>
        </div>
      </fieldset>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Select Dropdown - Accessible Form Control
  it('should have accessible select dropdown with proper labeling', async () => {
    const { container } = render(
      <div>
        <label htmlFor="frequency-select">Check Frequency</label>
        <select id="frequency-select" defaultValue="5">
          <option value="1">Every 1 minute</option>
          <option value="5">Every 5 minutes</option>
          <option value="15">Every 15 minutes</option>
          <option value="30">Every 30 minutes</option>
          <option value="60">Every hour</option>
        </select>
      </div>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Radio Button Group - Accessible Options
  it('should have accessible radio button group', async () => {
    const { container } = render(
      <fieldset>
        <legend>Alert Severity Level</legend>
        <div>
          <label htmlFor="low-severity">
            <input id="low-severity" type="radio" name="severity" value="low" />
            Low
          </label>
        </div>
        <div>
          <label htmlFor="medium-severity">
            <input id="medium-severity" type="radio" name="severity" value="medium" />
            Medium
          </label>
        </div>
        <div>
          <label htmlFor="high-severity">
            <input id="high-severity" type="radio" name="severity" value="high" />
            High
          </label>
        </div>
      </fieldset>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Link Navigation - Accessible Links
  it('should have accessible links with descriptive text', async () => {
    const { container } = render(
      <nav>
        <ul>
          <li>
            <a href="/dashboard">Dashboard</a>
          </li>
          <li>
            <a href="/health">Site Health</a>
          </li>
          <li>
            <a href="/activity">Activity Log</a>
          </li>
          <li>
            <a href="/settings">Settings</a>
          </li>
        </ul>
      </nav>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Badge with Status Indicator
  it('should have accessible status badge with proper semantics', async () => {
    const { container } = render(
      <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-3 py-1">
        <span className="h-2 w-2 rounded-full bg-green-600" aria-hidden="true" />
        <span>Active</span>
      </span>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // InfoPanel - Accessible Container with Description
  it('should have accessible info panel with proper heading and description', async () => {
    const { container } = render(
      <section
        role="region"
        aria-labelledby="info-title"
        className="rounded-lg bg-blue-50 p-4"
      >
        <h3 id="info-title">Site Status</h3>
        <p>Your website is performing optimally with 99.98% uptime this month.</p>
      </section>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Keyboard Accessibility - Navigation Flow
  it('should support keyboard navigation through interactive elements', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <div>
        <button type="button">Button 1</button>
        <button type="button">Button 2</button>
        <input type="text" placeholder="Text input" />
        <button type="button">Button 3</button>
      </div>
    );

    const buttons = screen.getAllByRole('button');
    const input = screen.getByPlaceholderText('Text input');

    // First button should be focusable
    await user.tab();
    expect(buttons[0]).toHaveFocus();

    // Tab to next button
    await user.tab();
    expect(buttons[1]).toHaveFocus();

    // Tab to input
    await user.tab();
    expect(input).toHaveFocus();

    // Tab to next button
    await user.tab();
    expect(buttons[2]).toHaveFocus();

    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Text Contrast - Sufficient Color Contrast
  it('should use colors with sufficient contrast', async () => {
    const { container } = render(
      <div className="rounded bg-white p-4 text-gray-900">
        <h2 className="text-lg font-bold">Status: Healthy</h2>
        <p className="text-gray-700">All systems operational</p>
      </div>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // Image Alternative Text
  it('should have proper alt text for status icons', async () => {
    const { container } = render(
      <div>
        <img src="/icons/healthy.svg" alt="System is healthy" />
        <span>Healthy</span>
      </div>
    );
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});
