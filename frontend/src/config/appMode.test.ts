import { describe, it, expect, afterEach } from 'vitest';
import { getAppMode } from './appMode';

afterEach(() => {
  (window as any).peanutConnect = undefined;
});

describe('getAppMode', () => {
  it('returns builder when the localized mode is builder', () => {
    (window as any).peanutConnect = { mode: 'builder' };
    expect(getAppMode()).toBe('builder');
  });

  it('defaults to full when mode is absent or anything else', () => {
    expect(getAppMode()).toBe('full');
    (window as any).peanutConnect = { mode: 'full' };
    expect(getAppMode()).toBe('full');
  });
});
