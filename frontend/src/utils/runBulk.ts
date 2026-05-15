export interface BulkResult<T> {
  succeeded: T[];
  failed: { id: T; error: string }[];
}

/**
 * Run `fn` for each id sequentially (never in parallel — keeps us well under
 * the Hub's per-site marketing rate limit). Stops at the first failure,
 * preserving the successes already made; remaining ids are left unattempted
 * so the caller can report honestly and the user can retry.
 */
export async function runBulk<T>(
  ids: T[],
  fn: (id: T) => Promise<unknown>,
  onProgress?: (done: number, total: number) => void
): Promise<BulkResult<T>> {
  const succeeded: T[] = [];
  const total = ids.length;

  for (let i = 0; i < total; i++) {
    const id = ids[i];
    try {
      await fn(id);
      succeeded.push(id);
      onProgress?.(i + 1, total);
    } catch (e) {
      const error = e instanceof Error ? e.message : String(e);
      return { succeeded, failed: [{ id, error }] };
    }
  }

  return { succeeded, failed: [] };
}
