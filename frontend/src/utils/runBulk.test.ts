import { describe, it, expect, vi } from 'vitest';
import { runBulk } from './runBulk';

describe('runBulk', () => {
  it('runs the action for every id sequentially and reports success', async () => {
    const order: number[] = [];
    const res = await runBulk([1, 2, 3], async (id) => {
      order.push(id);
    });
    expect(order).toEqual([1, 2, 3]);
    expect(res.succeeded).toEqual([1, 2, 3]);
    expect(res.failed).toEqual([]);
  });

  it('stops at the first failure, keeping prior successes', async () => {
    const attempted: number[] = [];
    const res = await runBulk([1, 2, 3], async (id) => {
      attempted.push(id);
      if (id === 2) throw new Error('boom');
    });
    expect(attempted).toEqual([1, 2]); // 3 never attempted
    expect(res.succeeded).toEqual([1]);
    expect(res.failed).toEqual([{ id: 2, error: 'boom' }]);
  });

  it('reports progress as done/total', async () => {
    const progress = vi.fn();
    await runBulk([10, 20], async () => {}, progress);
    expect(progress.mock.calls).toEqual([
      [1, 2],
      [2, 2],
    ]);
  });
});
