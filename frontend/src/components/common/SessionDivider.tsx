interface SessionDividerProps {
  timestamp?: string | null;
}

export function SessionDivider({ timestamp }: SessionDividerProps) {
  let label = 'Conversación anterior finalizada';

  if (timestamp) {
    const date = new Date(timestamp);
    if (!Number.isNaN(date.getTime())) {
      const datePart = date.toLocaleDateString([], {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
      });
      const timePart = date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
      });
      label = `Conversación anterior finalizada · ${datePart} ${timePart}`;
    }
  }

  return (
    <div
      role="separator"
      aria-label={label}
      data-testid="session-divider"
      style={{
        width: '100%',
        display: 'flex',
        alignItems: 'center',
        gap: '0.75rem',
        margin: '1rem 0 0',
        paddingBottom: '15px',
      }}
    >
      <span style={{ flex: 1, borderTop: '1px solid #dbe2ea' }} />
      <span
        style={{
          flexShrink: 0,
          padding: '0.3rem 0.8rem',
          background: '#eaf1ee',
          color: '#61726d',
          border: '1px solid #d9e3de',
          borderRadius: '9999px',
          fontSize: '0.72rem',
          fontWeight: 600,
          textAlign: 'center',
        }}
      >
        {label}
      </span>
      <span style={{ flex: 1, borderTop: '1px solid #dbe2ea' }} />
    </div>
  );
}
