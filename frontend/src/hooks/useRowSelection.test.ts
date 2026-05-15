import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useRowSelection } from './useRowSelection';

describe('useRowSelection', () => {
  it('starts empty', () => {
    const { result } = renderHook(() => useRowSelection());
    expect(result.current.selectedCount).toBe(0);
    expect(result.current.selectedIds).toEqual([]);
    expect(result.current.isSelected(1)).toBe(false);
  });

  it('toggle adds then removes a single id', () => {
    const { result } = renderHook(() => useRowSelection());
    act(() => result.current.toggle(5));
    expect(result.current.isSelected(5)).toBe(true);
    expect(result.current.selectedCount).toBe(1);
    act(() => result.current.toggle(5));
    expect(result.current.isSelected(5)).toBe(false);
    expect(result.current.selectedCount).toBe(0);
  });

  it('toggleMany(ids, true) selects all and is idempotent', () => {
    const { result } = renderHook(() => useRowSelection());
    act(() => result.current.toggleMany([1, 2, 3], true));
    act(() => result.current.toggleMany([2, 3], true));
    expect(result.current.selectedCount).toBe(3);
    expect(result.current.selectedIds.slice().sort()).toEqual([1, 2, 3]);
  });

  it('toggleMany(ids, false) deselects only the given ids', () => {
    const { result } = renderHook(() => useRowSelection());
    act(() => result.current.toggleMany([1, 2, 3], true));
    act(() => result.current.toggleMany([2], false));
    expect(result.current.selectedIds.slice().sort()).toEqual([1, 3]);
  });

  it('clear empties the selection', () => {
    const { result } = renderHook(() => useRowSelection());
    act(() => result.current.toggleMany([1, 2], true));
    act(() => result.current.clear());
    expect(result.current.selectedCount).toBe(0);
  });
});
