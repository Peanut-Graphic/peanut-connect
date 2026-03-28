import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';
import { clsx } from 'clsx';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
  hint?: string;
  leftIcon?: ReactNode;
  rightIcon?: ReactNode;
  fullWidth?: boolean;
}

const Input = forwardRef<HTMLInputElement, InputProps>(
  (
    {
      className,
      label,
      error,
      hint,
      leftIcon,
      rightIcon,
      fullWidth = true,
      id,
      ...props
    },
    ref
  ) => {
    const inputId = id || props.name;

    return (
      <div className={clsx(fullWidth && 'w-full')}>
        {label && (
          <label
            htmlFor={inputId}
            className="flex items-center gap-1.5 text-sm font-medium text-slate-700 mb-1"
          >
            {label}
            {props.required && <span className="text-red-500">*</span>}
          </label>
        )}

        <div className="relative">
          {leftIcon && (
            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
              {leftIcon}
            </div>
          )}

          <input
            ref={ref}
            id={inputId}
            style={leftIcon ? { paddingLeft: '44px' } : undefined}
            className={clsx(
              'block rounded-lg border bg-white text-slate-900 dark:text-slate-100 placeholder-slate-400',
              'focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500',
              'disabled:bg-slate-50 disabled:text-slate-500 disabled:cursor-not-allowed',
              fullWidth && 'w-full',
              !leftIcon && 'pl-3',
              rightIcon ? 'pr-10' : 'pr-3',
              'py-2 text-sm',
              error
                ? 'border-red-300 focus:ring-red-500 focus:border-red-500'
                : 'border-slate-300',
              className
            )}
            {...props}
          />

          {rightIcon && (
            <div className="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400">
              {rightIcon}
            </div>
          )}
        </div>

        {error && (
          <p className="mt-1 text-sm text-red-600">{error}</p>
        )}

        {hint && !error && (
          <p className="mt-1 text-sm text-slate-500">{hint}</p>
        )}
      </div>
    );
  }
);

Input.displayName = 'Input';

export default Input;
