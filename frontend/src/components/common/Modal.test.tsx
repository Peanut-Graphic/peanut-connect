import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import Modal, { ConfirmModal } from './Modal';

describe('Modal', () => {
  it('renders children when open', () => {
    render(
      <Modal isOpen={true} onClose={() => {}}>
        Modal content
      </Modal>
    );

    expect(screen.getByText('Modal content')).toBeInTheDocument();
  });

  it('does not render when closed', () => {
    render(
      <Modal isOpen={false} onClose={() => {}}>
        Modal content
      </Modal>
    );

    expect(screen.queryByText('Modal content')).not.toBeInTheDocument();
  });

  it('renders title when provided', () => {
    render(
      <Modal isOpen={true} onClose={() => {}} title="Modal Title">
        Content
      </Modal>
    );

    expect(screen.getByText('Modal Title')).toBeInTheDocument();
  });

  it('renders description when provided', () => {
    render(
      <Modal
        isOpen={true}
        onClose={() => {}}
        title="Title"
        description="Modal description"
      >
        Content
      </Modal>
    );

    expect(screen.getByText('Modal description')).toBeInTheDocument();
  });

  // Close button tests
  it('shows close button by default', () => {
    render(
      <Modal isOpen={true} onClose={() => {}} title="Title">
        Content
      </Modal>
    );

    // Close button is identified by the X icon
    // Two buttons render when showClose is on: the full-screen backdrop
    // (a <button> that closes on click) and the header X button.
    expect(screen.getAllByRole('button')).toHaveLength(2);
  });

  it('renders only the backdrop button when showClose is false', () => {
    render(
      <Modal isOpen={true} onClose={() => {}} title="Title" showClose={false}>
        Content
      </Modal>
    );

    // The backdrop is itself a <button>, so even with showClose=false
    // there's still one button (the backdrop). The header X is what's hidden.
    expect(screen.getAllByRole('button')).toHaveLength(1);
  });

  it('calls onClose when the header close button is clicked', () => {
    const onClose = vi.fn();
    render(
      <Modal isOpen={true} onClose={onClose} title="Title">
        Content
      </Modal>
    );

    // Header X button is the second one (backdrop is rendered first).
    const buttons = screen.getAllByRole('button');
    fireEvent.click(buttons[1]);

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  // Backdrop tests
  it('calls onClose when backdrop is clicked', () => {
    const onClose = vi.fn();
    render(
      <Modal isOpen={true} onClose={onClose}>
        Content
      </Modal>
    );

    // Click on backdrop (first fixed div)
    const backdrop = document.querySelector('.bg-black\\/50');
    fireEvent.click(backdrop!);

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('does not close when modal content is clicked', () => {
    const onClose = vi.fn();
    render(
      <Modal isOpen={true} onClose={onClose}>
        Content
      </Modal>
    );

    fireEvent.click(screen.getByText('Content'));

    expect(onClose).not.toHaveBeenCalled();
  });

  // Size tests
  it('applies sm size styles', () => {
    render(
      <Modal isOpen={true} onClose={() => {}} size="sm">
        Content
      </Modal>
    );

    const modal = screen.getByText('Content').closest('.max-w-md');
    expect(modal).toBeInTheDocument();
  });

  it('applies md size styles by default', () => {
    render(
      <Modal isOpen={true} onClose={() => {}}>
        Content
      </Modal>
    );

    const modal = screen.getByText('Content').closest('.max-w-lg');
    expect(modal).toBeInTheDocument();
  });

  it('applies lg size styles', () => {
    render(
      <Modal isOpen={true} onClose={() => {}} size="lg">
        Content
      </Modal>
    );

    const modal = screen.getByText('Content').closest('.max-w-2xl');
    expect(modal).toBeInTheDocument();
  });

  it('applies xl size styles', () => {
    render(
      <Modal isOpen={true} onClose={() => {}} size="xl">
        Content
      </Modal>
    );

    const modal = screen.getByText('Content').closest('.max-w-4xl');
    expect(modal).toBeInTheDocument();
  });

  // Custom className
  it('applies custom className', () => {
    render(
      <Modal isOpen={true} onClose={() => {}} className="custom-modal-class">
        Content
      </Modal>
    );

    const modal = screen.getByText('Content').closest('.custom-modal-class');
    expect(modal).toBeInTheDocument();
  });
});

describe('ConfirmModal', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    onConfirm: vi.fn(),
    title: 'Confirm Action',
    message: 'Are you sure?',
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders title', () => {
    render(<ConfirmModal {...defaultProps} />);

    expect(screen.getByText('Confirm Action')).toBeInTheDocument();
  });

  it('renders message', () => {
    render(<ConfirmModal {...defaultProps} />);

    expect(screen.getByText('Are you sure?')).toBeInTheDocument();
  });

  it('renders confirm button with default text', () => {
    render(<ConfirmModal {...defaultProps} />);

    expect(screen.getByText('Confirm')).toBeInTheDocument();
  });

  it('renders cancel button with default text', () => {
    render(<ConfirmModal {...defaultProps} />);

    expect(screen.getByText('Cancel')).toBeInTheDocument();
  });

  it('renders custom confirm text', () => {
    render(<ConfirmModal {...defaultProps} confirmText="Delete" />);

    expect(screen.getByText('Delete')).toBeInTheDocument();
  });

  it('renders custom cancel text', () => {
    render(<ConfirmModal {...defaultProps} cancelText="Nevermind" />);

    expect(screen.getByText('Nevermind')).toBeInTheDocument();
  });

  it('calls onConfirm when confirm button is clicked', () => {
    const onConfirm = vi.fn();
    render(<ConfirmModal {...defaultProps} onConfirm={onConfirm} />);

    fireEvent.click(screen.getByText('Confirm'));

    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('calls onClose when cancel button is clicked', () => {
    const onClose = vi.fn();
    render(<ConfirmModal {...defaultProps} onClose={onClose} />);

    fireEvent.click(screen.getByText('Cancel'));

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('disables buttons when loading', () => {
    render(<ConfirmModal {...defaultProps} loading={true} />);

    expect(screen.getByText('Cancel')).toBeDisabled();
    expect(screen.getByText('Confirm')).toBeDisabled();
  });

  it('applies danger variant styles', () => {
    render(<ConfirmModal {...defaultProps} variant="danger" />);

    const confirmButton = screen.getByText('Confirm');
    expect(confirmButton).toHaveClass('bg-red-600');
  });

  it('applies primary variant styles by default', () => {
    render(<ConfirmModal {...defaultProps} />);

    const confirmButton = screen.getByText('Confirm');
    expect(confirmButton).toHaveClass('bg-primary-600');
  });

  it('does not render when closed', () => {
    render(<ConfirmModal {...defaultProps} isOpen={false} />);

    expect(screen.queryByText('Confirm Action')).not.toBeInTheDocument();
  });
});
