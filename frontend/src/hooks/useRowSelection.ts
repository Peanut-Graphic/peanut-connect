import { useCallback, useMemo, useState } from 'react';

export interface RowSelection {
  selectedIds: number[];
  selectedCount: number;
  isSelected: (id: number) => boolean;
  toggle: (id: number) => void;
  toggleMany: (ids: number[], on: boolean) => void;
  clear: () => void;
}

// Tracks a set of selected row ids for the UTMs table. Pure state — no
// network. `toggleMany` is used for select-all-visible / bulk deselect.
export function useRowSelection(): RowSelection {
  const [selected, setSelected] = useState<Set<number>>(() => new Set());

  const toggle = useCallback((id: number) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }, []);

  const toggleMany = useCallback((ids: number[], on: boolean) => {
    setSelected((prev) => {
      const next = new Set(prev);
      for (const id of ids) {
        if (on) {
          next.add(id);
        } else {
          next.delete(id);
        }
      }
      return next;
    });
  }, []);

  const clear = useCallback(() => setSelected(new Set()), []);

  const isSelected = useCallback((id: number) => selected.has(id), [selected]);

  const selectedIds = useMemo(() => Array.from(selected), [selected]);

  return {
    selectedIds,
    selectedCount: selectedIds.length,
    isSelected,
    toggle,
    toggleMany,
    clear,
  };
}
