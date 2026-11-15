import React from 'react';

interface InteraLogoProps {
  variant?: 'light' | 'dark';
  size?: number;
  showWordmark?: boolean;
}

/** Transparent brand mark with a selectable wordmark for light or dark surfaces. */
export const InteraLogo: React.FC<InteraLogoProps> = ({ variant = 'light', size = 32, showWordmark = true }) => (
  <span role="img" aria-label="Intera" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.65rem', flexShrink: 0 }}>
    <img src={`${import.meta.env.BASE_URL}brand/intera-mark.svg`} alt="" width={size} height={size} style={{ display: 'block', objectFit: 'contain' }} />
    {showWordmark && (
      <span aria-hidden="true" style={{ color: variant === 'dark' ? '#f4f7f5' : '#263c38', fontSize: size >= 48 ? '1.75rem' : '1.2rem', fontWeight: 650, letterSpacing: '-0.035em', lineHeight: 1.1 }}>intera</span>
    )}
  </span>
);
