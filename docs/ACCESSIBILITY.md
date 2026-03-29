# Peanut Connect - Accessibility Guide

Peanut Connect is committed to being accessible to all users, regardless of ability. This guide documents our accessibility standards, testing procedures, and how to report accessibility issues.

## Accessibility Commitment

Peanut Connect aims to meet **WCAG 2.1 Level AA** accessibility standards across the entire platform. We believe that a fully accessible site benefits all users and is essential for inclusive design.

### Core Accessibility Principles

1. **Perceivable** - Information and UI components must be presented in ways users can perceive
2. **Operable** - All functionality must be accessible via keyboard and other input methods
3. **Understandable** - Text, layout, and interaction must be clear and predictable
4. **Robust** - Content must work with assistive technologies and across browsers

## Features

### Keyboard Navigation

- All interactive elements (buttons, links, form fields) are reachable via keyboard
- Logical tab order follows visual flow from left to right, top to bottom
- Visible focus indicators on all focusable elements
- Escape key closes modals and dropdowns
- Enter/Space keys activate buttons and toggles

### Screen Reader Support

- Semantic HTML structure provides meaningful navigation landmarks
- ARIA labels and descriptions for complex components
- Live regions announce dynamic content updates
- Form inputs properly associated with labels
- Status indicators include text alternatives (not color alone)

### Visual Accessibility

- Sufficient color contrast (7:1 for text, 4.5:1 for large text per WCAG AA)
- No information conveyed by color alone
- Responsive design works at all zoom levels (up to 200%)
- Support for operating system dark mode preferences
- Clear focus states for all interactive elements

### Form Accessibility

- All form inputs have associated labels
- Required fields marked and described with `aria-required`
- Error messages linked to invalid fields with `aria-describedby`
- Help text available with `aria-describedby` attributes
- Radio groups and checkboxes grouped in `<fieldset>` with `<legend>`

### Content Accessibility

- Proper heading hierarchy (h1, h2, h3, etc.)
- Data tables have proper header markup with `scope="col"`
- Activity feeds and lists use semantic list markup
- Abbreviations and acronyms defined on first use
- Links have descriptive text (avoid "click here")

## Dashboard Accessibility

The Dashboard page includes multiple widgets for site monitoring:

- **Health Status Cards** - Use icons + text (not color alone) to indicate status
- **Performance Metrics** - Numeric values displayed with appropriate context
- **Site List Table** - Proper table headers with row labels
- **Status Indicators** - Status symbols accompanied by descriptive text

All dashboard widgets support keyboard navigation and screen reader announcement of real-time updates.

## Health Monitoring Pages

The Health page provides detailed monitoring information:

- **Status Indicators** - Visual indicators paired with text descriptions
- **Metric Tables** - Properly structured with headers and row associations
- **Alert Cards** - Use ARIA live regions to announce critical alerts
- **Update Buttons** - Keyboard accessible refresh controls

## Error Log Accessibility

The Error Log page displays error information:

- **Table Headers** - Proper `scope` attributes for row/column associations
- **Timestamps** - `datetime` attributes for machine-readable dates
- **Error Descriptions** - Full text descriptions (not truncated)
- **Status Updates** - Live announcements for resolved errors

## Settings Accessibility

The Settings page contains form controls:

- **Form Grouping** - Related settings grouped with `<fieldset>` and `<legend>`
- **Input Labels** - All inputs have associated labels
- **Toggle Switches** - Include accessible ARIA attributes
- **Save Button** - Clear feedback on successful save
- **Error Messages** - Associated with form fields using `aria-describedby`

## Activity Feed Accessibility

The Activity page shows recent events:

- **Semantic List Markup** - Uses `<ul>` and `<li>` for event lists
- **Timestamps** - `<time>` elements with `datetime` attributes
- **Event Descriptions** - Clear text describing what happened
- **Status Indicators** - Text labels accompanying visual indicators

## Testing for Accessibility

### Automated Testing

Automated accessibility tests use the `jest-axe` library with vitest:

```bash
# Run all accessibility tests
npm run test:a11y

# Run tests in watch mode
./scripts/a11y-check.sh --watch

# Run specific test file
npx vitest run frontend/src/test/accessibility/ -- a11y
```

### Manual Testing

1. **Keyboard Navigation** - Verify all functionality with Tab, Enter, Escape, and arrow keys
2. **Screen Reader Testing** - Test with NVDA (Windows), JAWS (Windows), or VoiceOver (macOS)
3. **Color Contrast** - Use WebAIM contrast checker or browser DevTools
4. **Zoom Testing** - Zoom to 200% and verify layout and functionality
5. **Voice Control** - Test voice-activated navigation on supported platforms

### Testing with Screen Readers

#### macOS/iOS - VoiceOver
- Enable: Cmd + F5
- Web rotor: VO + U
- Navigate: VO + Arrow Keys
- Click: VO + Space

#### Windows - NVDA
- Download: https://www.nvaccess.org/
- Start: Insert + N
- Browse mode: B
- Navigate: Arrow Keys
- Click: Enter or Space

#### Windows - JAWS
- Commercial screen reader
- Supports most web accessibility features
- Common shortcuts similar to NVDA

### Browser DevTools Testing

1. **Chrome/Edge DevTools**
   - Open DevTools (F12)
   - Elements tab → Accessibility tree view
   - Run Lighthouse accessibility audit

2. **Firefox DevTools**
   - Open DevTools (F12)
   - Inspector → Accessibility tab
   - Review ARIA attributes and semantic roles

## Local Testing Instructions

### Prerequisites

- Node.js 20+
- npm 10+
- Access to Peanut Connect source repository

### Setup

```bash
# Navigate to frontend directory
cd frontend

# Install dependencies
npm install

# Install testing libraries
npm install --save-dev jest-axe eslint-plugin-jsx-a11y
```

### Running Tests

```bash
# Run all accessibility tests once
npm run test:a11y

# Run tests in watch mode for development
./scripts/a11y-check.sh --watch

# Run ESLint with jsx-a11y rules
npx eslint --ext .tsx,.ts src/

# Check color contrast in styles
npm run build && npm run preview
```

### Test Coverage

Current accessibility test suite covers:

- 20+ component tests using jest-axe
- ARIA attribute validation
- Keyboard navigation patterns
- Form label associations
- Table header semantics
- Live region functionality
- Color contrast requirements
- Status indicator patterns

## Known Limitations

### Current Limitations

1. **Real-Time Charts** - Third-party charting libraries may not be fully accessible; we provide text summaries
2. **Drag-and-Drop** - Some site reordering uses mouse-based drag-and-drop (alternative keyboard method in progress)
3. **Custom Select Components** - Certain dropdowns use custom styling; standard HTML selects available as fallback
4. **PDF Exports** - Generated PDFs follow PDF accessibility standards but may require external readers for full compliance

### Browser Support

Accessibility features are tested on:

- Chrome/Edge 120+
- Firefox 121+
- Safari 17+
- Mobile Safari on iOS 17+
- Chrome on Android 12+

## Reporting Accessibility Issues

If you discover an accessibility issue in Peanut Connect, please report it immediately:

### How to Report

1. **Email** - Send details to: [support email]
2. **GitHub Issues** - Open an issue with `[a11y]` tag
3. **Support Form** - Use the in-app support contact form

### What to Include

- Brief description of the accessibility barrier
- Which page/feature has the issue
- Expected behavior for accessible use
- Current behavior
- Screen reader or assistive technology used
- Browser and OS version
- Steps to reproduce

### Response Timeline

- **Critical issues** - Fixed within 48 hours
- **High priority** - Fixed within 1 week
- **Medium priority** - Fixed within 2 weeks
- **Low priority** - Included in next release cycle

## Accessibility Resources

### WCAG 2.1 Guidelines
- [WCAG 2.1 Overview](https://www.w3.org/WAI/WCAG21/quickref/)
- [Understanding WCAG 2.1](https://www.w3.org/WAI/WCAG21/Understanding/)

### Testing Tools
- [axe DevTools](https://www.deque.com/axe/devtools/) - Browser extension
- [WAVE](https://wave.webaim.org/) - Automated accessibility auditor
- [WebAIM](https://webaim.org/) - Accessibility information and resources
- [jest-axe](https://github.com/nickcolley/jest-axe) - Automated testing library

### Screen Readers
- [NVDA](https://www.nvaccess.org/) - Free Windows screen reader
- [JAWS](https://www.freedomscientific.com/products/software/jaws/) - Commercial Windows screen reader
- [VoiceOver](https://www.apple.com/accessibility/voiceover/) - Built-in macOS/iOS screen reader

### Guides and References
- [MDN Web Accessibility](https://developer.mozilla.org/en-US/docs/Web/Accessibility)
- [A11yProject.com](https://www.a11yproject.com/) - Community-driven accessibility resource
- [WebAIM Articles](https://webaim.org/articles/)

## Contributing Accessible Code

### Code Standards

When contributing to Peanut Connect, follow these accessibility standards:

#### Semantic HTML

```tsx
// Good - Uses semantic elements
<form>
  <fieldset>
    <legend>Notification Preferences</legend>
    <label htmlFor="email-alerts">Email alerts</label>
    <input id="email-alerts" type="checkbox" />
  </fieldset>
</form>

// Bad - Non-semantic div-based layout
<div>
  <div>Notification Preferences</div>
  <div>
    <div>Email alerts</div>
    <div></div>
  </div>
</div>
```

#### ARIA Attributes

```tsx
// Good - ARIA describes dynamic content
<div
  role="status"
  aria-live="polite"
  aria-atomic="true"
>
  Upload complete: 5 files processed
</div>

// Bad - No indication of dynamic updates
<div>Upload complete: 5 files processed</div>
```

#### Keyboard Support

```tsx
// Good - Supports Enter and Space keys
<button
  onClick={handleClick}
  onKeyDown={(e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      handleClick();
    }
  }}
>
  Click me
</button>

// Bad - Mouse-only interaction
<div onClick={handleClick}>Click me</div>
```

#### Color Contrast

```tsx
// Good - 7:1 contrast ratio for text
<span className="text-gray-900 bg-white">
  Important status information
</span>

// Bad - Insufficient contrast
<span className="text-gray-400 bg-white">
  Important status information
</span>
```

### Testing New Components

Before submitting a pull request:

1. Add accessibility tests to `src/test/accessibility/`
2. Test with keyboard navigation
3. Test with at least one screen reader
4. Run `npm run test:a11y` and verify no violations
5. Check color contrast with WebAIM checker

## Continuous Accessibility Improvement

Peanut Connect maintains accessibility through:

- **Automated CI Testing** - Every PR runs accessibility checks
- **Manual Audits** - Quarterly manual accessibility reviews
- **User Testing** - Regular testing with users who use assistive technologies
- **Team Training** - Accessibility training for all developers
- **Standards Updates** - Regular review of WCAG updates and best practices

## Questions?

For accessibility questions, feature requests, or concerns:

- Review this documentation
- Check the WCAG 2.1 guidelines
- Contact the development team
- Open an issue with the `[a11y]` label

---

Last updated: March 2024
Version: 3.4.0
