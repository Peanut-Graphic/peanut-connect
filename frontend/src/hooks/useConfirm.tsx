import { useCallback, useState } from 'react';
import { ConfirmModal } from '@/components/common/Modal';

interface ConfirmOptions {
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  variant?: 'danger' | 'primary';
}

interface PendingPrompt extends ConfirmOptions {
  resolve: (value: boolean) => void;
}

/**
 * Promise-based confirm dialog using the existing ConfirmModal component.
 * Drop-in replacement for window.confirm() across the SPA. Returns true
 * if the user accepts, false on cancel/dismiss.
 *
 *   const confirm = useConfirm();
 *   if (await confirm({ title: 'Delete', message: 'Sure?', variant: 'danger' })) { ... }
 *
 * The returned `dialog` element must be rendered somewhere in the tree;
 * it conditionally shows the modal when a prompt is pending.
 */
export function useConfirm(): {
  confirm: (opts: ConfirmOptions) => Promise<boolean>;
  dialog: JSX.Element;
} {
  const [pending, setPending] = useState<PendingPrompt | null>(null);

  const confirm = useCallback(
    (opts: ConfirmOptions) =>
      new Promise<boolean>((resolve) => {
        setPending({ ...opts, resolve });
      }),
    [],
  );

  const handleClose = () => {
    if (pending) pending.resolve(false);
    setPending(null);
  };
  const handleConfirm = () => {
    if (pending) pending.resolve(true);
    setPending(null);
  };

  const dialog = (
    <ConfirmModal
      isOpen={pending !== null}
      onClose={handleClose}
      onConfirm={handleConfirm}
      title={pending?.title ?? ''}
      message={pending?.message ?? ''}
      confirmText={pending?.confirmText}
      cancelText={pending?.cancelText}
      variant={pending?.variant}
    />
  );

  return { confirm, dialog };
}
