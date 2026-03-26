import DOMPurify from 'dompurify';

/**
 * Sanitize HTML string to prevent XSS attacks
 * Allows common safe HTML tags and attributes
 */
export function sanitizeHtml(dirty: string): string {
  return DOMPurify.sanitize(dirty, {
    ALLOWED_TAGS: [
      'b',
      'i',
      'em',
      'strong',
      'a',
      'p',
      'br',
      'ul',
      'ol',
      'li',
      'span',
      'div',
      'h1',
      'h2',
      'h3',
      'h4',
      'h5',
      'h6',
      'img',
      'table',
      'thead',
      'tbody',
      'tr',
      'td',
      'th',
      'code',
      'pre',
    ],
    ALLOWED_ATTR: ['href', 'target', 'rel', 'class', 'id', 'src', 'alt', 'width', 'height', 'style'],
    ALLOW_DATA_ATTR: false,
  });
}

/**
 * Sanitize plain text (strip all HTML)
 * Use this for user-generated text that should never contain HTML
 */
export function sanitizeText(dirty: string): string {
  return DOMPurify.sanitize(dirty, { ALLOWED_TAGS: [] });
}

/**
 * Create sanitized HTML for dangerouslySetInnerHTML
 * Returns an object safe for React's dangerouslySetInnerHTML prop
 */
export function createSafeHtml(dirty: string): { __html: string } {
  return { __html: sanitizeHtml(dirty) };
}
