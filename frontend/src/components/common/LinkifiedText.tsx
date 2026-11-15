import React, { useMemo } from 'react';

export interface LinkifiedTextProps {
  text: string;
  className?: string;
  linkClassName?: string;
  style?: React.CSSProperties;
  linkStyle?: React.CSSProperties;
}

const URL_REGEX = /(https?:\/\/[^\s<]+[^<.,:;"')\]\s])/g;

export const LinkifiedText: React.FC<LinkifiedTextProps> = ({
  text,
  className = '',
  linkClassName = 'text-blue-600 dark:text-blue-400 underline hover:text-blue-800 break-words inline-block',
  style,
  linkStyle,
}) => {
  const parts = useMemo(() => {
    if (!text) return [];
    return text.split(URL_REGEX);
  }, [text]);

  if (!text) return null;

  return (
    <div
      className={`whitespace-pre-wrap break-words ${className}`}
      style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', ...style }}
    >
      {parts.map((part, index) => {
        if (/^https?:\/\//i.test(part)) {
          return (
            <a
              key={index}
              href={part}
              target="_blank"
              rel="noopener noreferrer"
              onClick={(e) => e.stopPropagation()}
              className={linkClassName}
              style={{
                wordBreak: 'break-all',
                textDecoration: 'underline',
                cursor: 'pointer',
                ...linkStyle,
              }}
            >
              {part}
            </a>
          );
        }
        return <React.Fragment key={index}>{part}</React.Fragment>;
      })}
    </div>
  );
};
