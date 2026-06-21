import { describe, it, expect } from 'vitest';
import config from '../tailwind.config';

describe('tailwind theme tokens (Branded Navy)', () => {
  const colors = (config.theme?.extend?.colors ?? {}) as Record<string, unknown>;

  it('exposes the expanded shadcn token set', () => {
    for (const key of ['card', 'muted', 'secondary', 'accent', 'destructive', 'popover', 'input', 'success', 'warning', 'info']) {
      expect(colors, `missing color token: ${key}`).toHaveProperty(key);
    }
  });

  it('exposes the sidebar token scope', () => {
    expect(colors).toHaveProperty('sidebar');
    const sidebar = colors.sidebar as Record<string, unknown>;
    expect(sidebar).toHaveProperty('DEFAULT');
    expect(sidebar).toHaveProperty('accent');
    expect(sidebar).toHaveProperty('foreground');
  });
});
