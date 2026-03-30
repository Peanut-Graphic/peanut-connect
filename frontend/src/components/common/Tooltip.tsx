import { useState, useRef, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { HelpCircle } from 'lucide-react';

interface TooltipProps {
  content: string | React.ReactNode;
  children?: React.ReactNode;
  position?: 'top' | 'bottom' | 'left' | 'right';
  showIcon?: boolean;
  iconSize?: 'sm' | 'md' | 'lg';
  maxWidth?: string;
}

export function Tooltip({
  content,
  children,
  position = 'top',
  showIcon = false,
  iconSize = 'sm',
  maxWidth = '280px',
}: TooltipProps) {
  const [isVisible, setIsVisible] = useState(false);
  const [coords, setCoords] = useState({ top: 0, left: 0 });
  const [actualPosition, setActualPosition] = useState(position);
  const triggerRef = useRef<HTMLSpanElement>(null);
  const tooltipRef = useRef<HTMLDivElement>(null);

  const iconSizes = {
    sm: 'w-3.5 h-3.5',
    md: 'w-4 h-4',
    lg: 'w-5 h-5',
  };

  useEffect(() => {
    if (isVisible && triggerRef.current) {
      const rect = triggerRef.current.getBoundingClientRect();
      const scrollY = window.scrollY;
      const scrollX = window.scrollX;

      // Estimate tooltip height (will adjust after render)
      const estimatedTooltipHeight = 100;

      // Determine best position based on available space
      let bestPosition = position;

      if (position === 'top' && rect.top < estimatedTooltipHeight + 20) {
        bestPosition = 'bottom';
      } else if (position === 'bottom' && (window.innerHeight - rect.bottom) < estimatedTooltipHeight + 20) {
        bestPosition = 'top';
      }

      setActualPosition(bestPosition);

      let top = 0;
      let left = 0;

      switch (bestPosition) {
        case 'top':
          top = rect.top + scrollY - 8;
          left = rect.left + scrollX + rect.width / 2;
          break;
        case 'bottom':
          top = rect.bottom + scrollY + 8;
          left = rect.left + scrollX + rect.width / 2;
          break;
        case 'left':
          top = rect.top + scrollY + rect.height / 2;
          left = rect.left + scrollX - 8;
          break;
        case 'right':
          top = rect.top + scrollY + rect.height / 2;
          left = rect.right + scrollX + 8;
          break;
      }

      // Clamp left position to stay within viewport
      const maxWidthNum = parseInt(maxWidth) || 280;
      const minLeft = scrollX + maxWidthNum / 2 + 10;
      const maxLeft = scrollX + window.innerWidth - maxWidthNum / 2 - 10;
      left = Math.max(minLeft, Math.min(maxLeft, left));

      setCoords({ top, left });
    }
  }, [isVisible, position, maxWidth]);

  const getTooltipStyle = (): React.CSSProperties => {
    const base: React.CSSProperties = {
      position: 'absolute',
      zIndex: 99999,
      maxWidth,
    };

    switch (actualPosition) {
      case 'top':
        return { ...base, bottom: `calc(100vh - ${coords.top}px)`, left: coords.left, transform: 'translateX(-50%)' };
      case 'bottom':
        return { ...base, top: coords.top, left: coords.left, transform: 'translateX(-50%)' };
      case 'left':
        return { ...base, top: coords.top, right: `calc(100% - ${coords.left}px)`, transform: 'translateY(-50%)' };
      case 'right':
        return { ...base, top: coords.top, left: coords.left, transform: 'translateY(-50%)' };
    }
  };

  const arrowClasses = {
    top: 'bottom-0 left-1/2 -translate-x-1/2 translate-y-full border-t-slate-800 border-x-transparent border-b-transparent',
    bottom: 'top-0 left-1/2 -translate-x-1/2 -translate-y-full border-b-slate-800 border-x-transparent border-t-transparent',
    left: 'right-0 top-1/2 -translate-y-1/2 translate-x-full border-l-slate-800 border-y-transparent border-r-transparent',
    right: 'left-0 top-1/2 -translate-y-1/2 -translate-x-full border-r-slate-800 border-y-transparent border-l-transparent',
  };

  const tooltipContent = isVisible && (
    <div ref={tooltipRef} style={getTooltipStyle()}>
      <div className="bg-slate-800 text-white text-xs leading-relaxed px-3 py-2 rounded-lg shadow-lg relative">
        {content}
        <span className={`absolute w-0 h-0 border-4 ${arrowClasses[actualPosition]}`} />
      </div>
    </div>
  );

  return (
    <>
      <span
        ref={triggerRef}
        className="relative inline-flex items-center"
        onMouseEnter={() => setIsVisible(true)}
        onMouseLeave={() => setIsVisible(false)}
        onFocus={() => setIsVisible(true)}
        onBlur={() => setIsVisible(false)}
      >
        {showIcon ? (
          <HelpCircle className={`${iconSizes[iconSize]} text-slate-400 hover:text-slate-600 cursor-help transition-colors`} />
        ) : (
          children
        )}
      </span>
      {typeof document !== 'undefined' && createPortal(tooltipContent, document.body)}
    </>
  );
}

// Inline help icon with tooltip
export function HelpTooltip({
  content,
  size = 'sm'
}: {
  content: string | React.ReactNode;
  size?: 'sm' | 'md' | 'lg';
}) {
  return (
    <Tooltip content={content} showIcon iconSize={size} />
  );
}
