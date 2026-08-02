import { useEffect, useRef, useState } from 'react';
import { ChevronDown, X } from 'lucide-react';

/**
 * Checkbox multi-select for campaigns: a dropdown of checkboxes plus removable
 * chips for the current selection. Extracted from Analytics so the Dominion
 * Funnel (and future pages) share one picker instead of hand-rolling variants.
 */
export function CampaignFilter({
  all,
  selected,
  onToggle,
  onClear,
  hint,
  countLabel = (n) => `${n} campaigns`,
}: {
  all: string[];
  selected: string[];
  onToggle: (c: string) => void;
  onClear: () => void;
  hint?: string;
  /** Button label when 2+ campaigns are selected (Analytics says "Comparing N campaigns"). */
  countLabel?: (n: number) => string;
}) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  // Close on Esc + click outside.
  useEffect(() => {
    if (!open) return;
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    const onPointerDown = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('mousedown', onPointerDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('mousedown', onPointerDown);
    };
  }, [open]);

  return (
    <div className="mb-4 flex flex-wrap items-center gap-2">
      <div className="relative" ref={containerRef}>
        <button
          type="button"
          onClick={() => setOpen((o) => !o)}
          className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium rounded border border-slate-300 bg-white hover:bg-slate-50"
          aria-haspopup="listbox"
          aria-expanded={open}
        >
          {selected.length === 0
            ? 'All campaigns'
            : selected.length === 1
            ? `Campaign: ${selected[0]}`
            : countLabel(selected.length)}
          <ChevronDown className="w-4 h-4 text-slate-400" />
        </button>
        {open && (
          <div
            className="absolute z-10 mt-1 min-w-[18rem] max-h-80 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg p-2"
            role="listbox"
            aria-multiselectable="true"
          >
            {all.length === 0 ? (
              <div className="px-2 py-2 text-sm text-slate-500">No campaigns yet.</div>
            ) : (
              all.map((c) => {
                const isSel = selected.includes(c);
                return (
                  <label
                    key={c}
                    className="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-slate-50 cursor-pointer text-sm"
                  >
                    <input
                      type="checkbox"
                      checked={isSel}
                      onChange={() => onToggle(c)}
                      className="rounded border-slate-300"
                    />
                    <span className="text-slate-800 truncate">{c}</span>
                  </label>
                );
              })
            )}
          </div>
        )}
      </div>

      {selected.map((c) => (
        <span
          key={c}
          className="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded bg-primary-50 text-primary-700"
        >
          {c}
          <button
            type="button"
            onClick={() => onToggle(c)}
            className="text-primary-500 hover:text-primary-700"
            aria-label={`Remove ${c}`}
          >
            <X className="w-3 h-3" />
          </button>
        </span>
      ))}

      {selected.length > 0 && (
        <button
          type="button"
          onClick={onClear}
          className="text-xs text-slate-500 hover:text-slate-700 underline-offset-2 hover:underline"
        >
          Clear
        </button>
      )}

      {hint && <span className="ml-auto text-xs text-slate-500">{hint}</span>}
    </div>
  );
}
