// @vitest-environment node
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { build } from 'vite';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/css/themes/tokens.css'), 'utf8');
const themes = ['confectionery', 'pyro', 'neutral'] as const;

function declarations(block: string): Record<string, string> {
  return Object.fromEntries(
    [...block.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)].map((match) => [match[1], match[2].trim()]),
  );
}

const base = declarations(source.match(/@theme static\s*\{([^}]+)\}/)?.[1] ?? '');

function themeTokens(theme: string): Record<string, string> {
  const block = source.match(new RegExp(`\\[data-theme=['"]${theme}['"]\\]\\s*\\{([^}]+)\\}`));
  if (!block) throw new Error(`Missing theme selector: ${theme}`);
  return { ...base, ...declarations(block[1]) };
}

function luminance(hex: string): number {
  if (!/^#[0-9a-f]{6}$/i.test(hex))
    throw new Error(`Expected an opaque six-digit color, received ${hex}`);
  const linear = hex
    .slice(1)
    .match(/../g)!
    .map((channel) => {
      const value = Number.parseInt(channel, 16) / 255;
      return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
  return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
}

function contrast(first: string, second: string): number {
  const a = luminance(first);
  const b = luminance(second);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

describe('actual theme role contrast', () => {
  it.each(themes)('%s: active text is at least 4.5:1', (theme) => {
    const tokens = themeTokens(theme);
    const pairs: [string, string][] = [];
    for (const foreground of ['ink', 'ink-muted', 'link', 'brand-primary']) {
      for (const background of ['bg', 'surface', 'sidebar', 'surface-muted'])
        pairs.push([foreground, background]);
    }
    pairs.push(
      ['on-action', 'action'],
      ['on-action', 'action-hover'],
      ['on-nav-active', 'nav-active'],
    );
    pairs.push(
      ['on-brand-primary', 'brand-primary'],
      ['brand-primary', 'brand-primary-soft'],
      ['on-brand-accent', 'brand-accent'],
    );
    for (const semantic of ['success', 'warning', 'danger', 'info']) {
      pairs.push([`${semantic}-ink`, `${semantic}-soft`], ['on-action', semantic]);
    }
    for (const [foreground, background] of pairs) {
      const actual = contrast(tokens[`--color-${foreground}`], tokens[`--color-${background}`]);
      expect(
        actual,
        `${theme}: ${foreground} on ${background} = ${actual.toFixed(2)}:1`,
      ).toBeGreaterThanOrEqual(4.5);
    }
  });

  it.each(themes)(
    '%s: interactive icons and focus are at least 3:1',
    (theme) => {
      const tokens = themeTokens(theme);
      for (const foreground of ['icon', 'focus']) {
        for (const background of ['bg', 'surface', 'sidebar', 'surface-muted']) {
          const actual = contrast(tokens[`--color-${foreground}`], tokens[`--color-${background}`]);
          expect(
            actual,
            `${theme}: ${foreground} on ${background} = ${actual.toFixed(2)}:1`,
          ).toBeGreaterThanOrEqual(3);
        }
      }
    },
  );

  it('limits brand overrides to brand roles and preserves semantic actions', () => {
    expect(Object.keys(base).length).toBeGreaterThan(40);
    for (const theme of themes) {
      const tokens = themeTokens(theme);
      const changed = Object.keys(tokens).filter((key) => tokens[key] !== base[key]);
      expect(changed.every((key) => key.startsWith('--color-brand-'))).toBe(true);
      expect(tokens['--color-action']).toBe(base['--color-action']);
    }
    expect(new Set(themes.map((theme) => themeTokens(theme)['--color-brand-primary'])).size).toBe(
      3,
    );
  });
});

it('ships the unchanged Cyrillic and Latin font files with their license', () => {
  const fonts = {
    cyrillic: 'c268b459a9329e59fecf39a17618efd44c71735532048d60b12aab76a8c14914',
    latin: 'a30ddcd349703aff7464c34bef3fffdff405ee50c113440d7c8693c02d210972',
  };
  for (const [subset, checksum] of Object.entries(fonts)) {
    const bytes = readFileSync(resolve(`public/fonts/manrope-${subset}.woff2`));
    expect(bytes.subarray(0, 4).toString()).toBe('wOF2');
    expect(createHash('sha256').update(bytes).digest('hex')).toBe(checksum);
    expect(source).toContain(`/fonts/manrope-${subset}.woff2`);
  }
  const license = readFileSync(resolve('public/fonts/OFL-Manrope.txt'), 'utf8');
  expect(license).toContain('SIL OPEN FONT LICENSE Version 1.1');
  expect(license).toContain('The Manrope Project Authors');
  expect(source.match(/font-display:\s*swap/g)).toHaveLength(2);
});

it('emits every token and the Tailwind CSS variable consumer recipe through the actual Vite pipeline', async () => {
  const result = await build({
    logLevel: 'silent',
    build: {
      write: false,
      rollupOptions: {
        input: [
          'resources/css/app.css',
          'resources/js/app.tsx',
          'tests/Unit/ThemeTokens/utility-fixture.css',
        ],
      },
    },
  });
  const outputs = Array.isArray(result) ? result : [result];
  const css = outputs
    .flatMap((output) => ('output' in output ? output.output : []))
    .filter((output) => output.type === 'asset' && output.fileName.endsWith('.css'))
    .map((output) =>
      output.type === 'asset'
        ? typeof output.source === 'string'
          ? output.source
          : new TextDecoder().decode(output.source)
        : '',
    )
    .join('\n');

  expect(css).not.toContain('@theme');
  for (const key of Object.keys(base))
    expect(css, `Missing emitted token: ${key}`).toMatch(new RegExp(`${key}\\s*:`));
  for (const theme of themes)
    expect(css).toMatch(new RegExp(`\\[data-theme=["']?${theme}["']?\\]`));
  for (const declaration of [
    'background-color:var(--color-bg)',
    'color:var(--color-ink)',
    'border-color:var(--color-control-border)',
    'border-radius:var(--radius-card)',
    'font-family:var(--font-sans)',
  ])
    expect(css.replace(/\s+/g, '')).toContain(declaration);
  expect(css).toContain('/fonts/manrope-cyrillic.woff2');
  expect(css).toContain('/fonts/manrope-latin.woff2');
}, 30_000);
