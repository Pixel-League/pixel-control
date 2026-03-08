/**
 * Type shim for tm-text.
 * The package has types but its exports field lacks a "types" condition,
 * which TypeScript's "bundler" moduleResolution requires.
 * This shim provides the subset of types used by this project.
 */
declare module 'tm-text' {
  /** Strips all ManiaPlanet style/color codes from the input string. */
  export function humanize(input: string): string;

  /** Converts ManiaPlanet style/color codes to HTML spans with inline styles. */
  export function htmlify(input: string): string;
}
