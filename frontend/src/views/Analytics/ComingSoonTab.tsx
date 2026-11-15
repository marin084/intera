import React from 'react';
import { Clock, Sparkles } from 'lucide-react';

interface ComingSoonTabProps {
  title: string;
  description: string;
}

export const ComingSoonTab: React.FC<ComingSoonTabProps> = ({ title, description }) => {
  return (
    <div
      className="card"
      style={{
        padding: '4rem 2rem',
        textAlign: 'center',
        background: 'var(--bg-card)',
        borderRadius: 'var(--radius)',
        boxShadow: 'var(--shadow)',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        gap: '1rem',
      }}
      data-testid="coming-soon-tab"
    >
      <div
        style={{
          width: '64px',
          height: '64px',
          borderRadius: '50%',
          background: 'var(--primary-light)',
          color: 'var(--primary)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          marginBottom: '0.5rem',
        }}
      >
        <Clock size={32} />
      </div>

      <div style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem', background: '#fef3c7', color: '#b45309', padding: '0.25rem 0.75rem', borderRadius: '9999px', fontSize: '0.75rem', fontWeight: 600 }}>
        <Sparkles size={12} /> Próximamente en Fase 2
      </div>

      <h2 style={{ fontSize: '1.5rem', fontWeight: 700, margin: 0, color: 'var(--text-main)' }}>
        {title}
      </h2>

      <p style={{ maxWidth: '540px', color: 'var(--text-muted)', fontSize: '0.9rem', lineHeight: 1.5, margin: 0 }}>
        {description}
      </p>
    </div>
  );
};
