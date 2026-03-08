import { humanize, htmlify } from 'tm-text';

/**
 * Strips all ManiaPlanet style codes from a nickname, returning plain text.
 * Example: '$o$n$fftop' → 'top'
 */
export function stripMpStyles(text: string): string {
  return humanize(text);
}

/**
 * Converts ManiaPlanet style codes to HTML spans with inline styles.
 * Example: '$f00Rouge' → '<span style="color:#ff0000">Rouge</span>'
 */
export function mpToHtml(text: string): string {
  return htmlify(text);
}

/**
 * Returns true if the string contains at least one ManiaPlanet style code.
 */
export function hasMpStyles(text: string): boolean {
  return text.includes('$');
}
