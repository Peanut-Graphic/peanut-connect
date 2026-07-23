export type AppMode = 'full' | 'builder';

export function getAppMode(): AppMode {
  const w = window as unknown as { peanutConnect?: { mode?: string } };
  return w.peanutConnect?.mode === 'builder' ? 'builder' : 'full';
}
