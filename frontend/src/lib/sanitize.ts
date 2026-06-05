/**
 * Email Body Sanitizer
 *
 * PURPOSE:
 * Cleans raw email body content for safe, readable display in the dashboard.
 * Email bodies often contain HTML tags, tracking pixel URLs, Outlook safelinks,
 * and other noise that makes them unreadable as plain text.
 *
 * WHY client-side sanitization instead of cleaning on the backend:
 * The backend stores the raw email content as received from Gmail. This
 * preserves the original for future use (re-classification, forwarding,
 * audit). The frontend cleans it only for display. If we cleaned on
 * storage, we'd lose data we might need later.
 *
 * SECURITY:
 * We NEVER render raw HTML from emails. Even body_html is not rendered
 * with dangerouslySetInnerHTML. All content is converted to plain text
 * and rendered as text nodes, which prevents XSS from malicious emails.
 */

/**
 * Clean email body text for display.
 *
 * Strips HTML tags, decodes entities, removes tracking URLs and
 * excessive whitespace. Returns readable plain text.
 */
export function sanitizeEmailBody(text: string | null | undefined): string {
  if (!text) return '(No content)';

  let cleaned = text;

  // Remove HTML tags. Emails often have the HTML version in body_text
  // when the plain text version is unavailable.
  cleaned = cleaned.replace(/<[^>]+>/g, ' ');

  // Decode common HTML entities that survive tag stripping.
  cleaned = cleaned.replace(/&nbsp;/gi, ' ');
  cleaned = cleaned.replace(/&amp;/gi, '&');
  cleaned = cleaned.replace(/&lt;/gi, '<');
  cleaned = cleaned.replace(/&gt;/gi, '>');
  cleaned = cleaned.replace(/&quot;/gi, '"');
  cleaned = cleaned.replace(/&#39;/gi, "'");

  // Remove tracking URLs (long encoded URLs from email tracking systems).
  // These are URLs containing encoded redirects (e.g., Outlook safelinks,
  // Cisco email security, HubSpot tracking). They add no readable value
  // and can be hundreds of characters long.
  cleaned = cleaned.replace(/https?:\/\/\S{200,}/g, '[link]');

  // Remove Outlook safelinks wrapper URLs specifically.
  // These wrap every link in the email with a Microsoft redirect.
  cleaned = cleaned.replace(/https?:\/\/nam\d+\.safelinks\.protection\.outlook\.com\/\S+/g, '[link]');

  // Remove data URIs (base64 images embedded in HTML emails).
  cleaned = cleaned.replace(/data:[^;]+;base64,[A-Za-z0-9+/=]+/g, '');

  // Collapse multiple spaces and newlines into readable whitespace.
  cleaned = cleaned.replace(/[ \t]+/g, ' ');
  cleaned = cleaned.replace(/\n{3,}/g, '\n\n');

  return cleaned.trim() || '(No content)';
}
