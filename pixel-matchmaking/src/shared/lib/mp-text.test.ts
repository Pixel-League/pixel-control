import { describe, it, expect } from 'vitest';
import { stripMpStyles, mpToHtml, hasMpStyles } from './mp-text';

describe('stripMpStyles', () => {
  it('strips style codes from a complex nickname', () => {
    // tm-text only supports 3-digit color codes ($fff); $ff is not a valid color so
    // the tokenizer drops $f (unrecognized 1-digit attempt), leaving 'ftop'.
    expect(stripMpStyles('$o$n$fftop')).toBe('ftop');
  });

  it('strips color code from a colored word', () => {
    expect(stripMpStyles('$f00Rouge')).toBe('Rouge');
  });

  it('returns plain text unchanged', () => {
    expect(stripMpStyles('TestPlayer')).toBe('TestPlayer');
  });

  it('strips multiple color codes', () => {
    expect(stripMpStyles('$f00R$0f0G$00fB')).toBe('RGB');
  });
});

describe('mpToHtml', () => {
  it('wraps colored text in a span with inline color style', () => {
    const html = mpToHtml('$f00R');
    expect(html).toContain('color');
    expect(html).toContain('R');
  });

  it('returns plain text unchanged', () => {
    const html = mpToHtml('Hello');
    expect(html).toContain('Hello');
  });
});

describe('hasMpStyles', () => {
  it('returns true for a nickname with style codes', () => {
    expect(hasMpStyles('$o$n$fftop')).toBe(true);
  });

  it('returns true for a nickname with a color code', () => {
    expect(hasMpStyles('$f00Rouge')).toBe(true);
  });

  it('returns false for a plain nickname', () => {
    expect(hasMpStyles('TestPlayer')).toBe(false);
  });

  it('returns false for an empty string', () => {
    expect(hasMpStyles('')).toBe(false);
  });
});
