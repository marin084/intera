import React, { useState } from 'react';
import { MessageSquare, Smartphone, AlertTriangle, Layers } from 'lucide-react';

interface MultichannelPreviewProps {
  title: string;
  options: Array<{ id: string; text: string; is_hidden?: boolean; visible?: boolean }>;
}

export const MultichannelPreview: React.FC<MultichannelPreviewProps> = ({ title, options }) => {
  const [selectedChannel, setSelectedChannel] = useState<'webchat' | 'whatsapp'>('webchat');

  const visibleOptions = options.filter((opt) => !opt.is_hidden && opt.visible !== false);
  const optionCount = visibleOptions.length;
  const isWhatsAppButtons = optionCount <= 3;
  const isWhatsAppList = optionCount > 3 && optionCount <= 10;
  const isWhatsAppOverLimit = optionCount > 10;

  return (
    <div style={{ background: '#f4f7f5', border: '1px solid #d9e3de', borderRadius: '8px', overflow: 'hidden', marginTop: '1rem' }}>
      {/* Channel Tabs */}
      <div style={{ display: 'flex', borderBottom: '1px solid #d9e3de', background: '#eaf1ee' }}>
        <button
          type="button"
          onClick={() => setSelectedChannel('webchat')}
          style={{
            flex: 1,
            padding: '0.5rem',
            border: 'none',
            background: selectedChannel === 'webchat' ? '#ffffff' : 'transparent',
            fontWeight: selectedChannel === 'webchat' ? 700 : 500,
            fontSize: '0.75rem',
            color: selectedChannel === 'webchat' ? '#263c38' : '#61726d',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '0.35rem',
            cursor: 'pointer',
          }}
        >
          <MessageSquare size={13} color="#5d8f82" /> Webchat (Real)
        </button>
        <button
          type="button"
          onClick={() => setSelectedChannel('whatsapp')}
          style={{
            flex: 1,
            padding: '0.5rem',
            border: 'none',
            background: selectedChannel === 'whatsapp' ? '#ffffff' : 'transparent',
            fontWeight: selectedChannel === 'whatsapp' ? 700 : 500,
            fontSize: '0.75rem',
            color: selectedChannel === 'whatsapp' ? '#263c38' : '#61726d',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '0.35rem',
            cursor: 'pointer',
          }}
        >
          <Smartphone size={13} color="#5d8f82" /> WhatsApp Meta (Previsualización)
        </button>
      </div>

      <div style={{ padding: '0.85rem' }}>
        {selectedChannel === 'webchat' ? (
          <div>
            <div style={{ fontSize: '0.7rem', color: '#61726d', marginBottom: '0.5rem' }}>
              Representación interactiva en el widget de chat (botones dinámicos):
            </div>

            <div style={{ background: '#ffffff', border: '1px solid #d9e3de', borderRadius: '8px', padding: '0.75rem', boxShadow: '0 1px 2px rgba(0,0,0,0.05)' }}>
              <div style={{ fontSize: '0.8rem', color: '#35564f', marginBottom: '0.6rem', lineHeight: '1.4' }}>
                {title || 'Selecciona una opción:'}
              </div>

              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.4rem' }}>
                {visibleOptions.map((opt, idx) => (
                  <div
                    key={opt.id || idx}
                    style={{
                      background: '#eaf1ee',
                      color: '#456c61',
                      border: '1px solid #a9c8bb',
                      borderRadius: '6px',
                      padding: '0.35rem 0.65rem',
                      fontSize: '0.75rem',
                      fontWeight: 600,
                      cursor: 'default',
                    }}
                  >
                    {opt.text || `Opción ${idx + 1}`}
                  </div>
                ))}
              </div>
            </div>
          </div>
        ) : (
          <div>
            {/* WhatsApp Meta Capability Matrix */}
            <div style={{ fontSize: '0.7rem', color: '#61726d', marginBottom: '0.6rem' }}>
              <strong>Formato adaptativo de Meta API:</strong>{' '}
              {isWhatsAppButtons && (
                <span style={{ color: '#5d8f82', fontWeight: 600 }}>Botones interactivos Quick Reply (≤ 3 opciones)</span>
              )}
              {isWhatsAppList && (
                <span style={{ color: '#5d8f82', fontWeight: 600 }}>Lista interactiva Interactive List (4 a 10 opciones)</span>
              )}
              {isWhatsAppOverLimit && (
                <span style={{ color: '#dc2626', fontWeight: 600 }}>Excede límite de Meta (máx. 10 opciones por lista)</span>
              )}
            </div>

            {isWhatsAppOverLimit && (
              <div style={{ padding: '0.5rem 0.65rem', background: '#fee2e2', border: '1px solid #fecaca', borderRadius: '6px', fontSize: '0.72rem', color: '#991b1b', marginBottom: '0.65rem', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                <AlertTriangle size={14} /> La API oficial de WhatsApp soporta hasta 10 opciones en listas interactivas. Considera dividir el menú en submenús o categorías.
              </div>
            )}

            {/* WhatsApp Phone Mockup */}
            <div
              style={{
                background: '#e5ddd5',
                borderRadius: '8px',
                padding: '0.85rem',
                border: '1px solid #d9e3de',
                fontFamily: 'system-ui, sans-serif',
              }}
            >
              <div
                style={{
                  background: '#ffffff',
                  borderRadius: '7.5px',
                  padding: '0.6rem 0.75rem',
                  maxWidth: '92%',
                  boxShadow: '0 1px 0.5px rgba(0,0,0,0.13)',
                  position: 'relative',
                }}
              >
                <div style={{ fontSize: '0.8rem', color: '#263c38', marginBottom: '0.5rem', whiteSpace: 'pre-wrap' }}>
                  {title || 'Selecciona una opción:'}
                </div>

                {isWhatsAppButtons ? (
                  /* WhatsApp 1-3 Buttons */
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem', marginTop: '0.5rem', borderTop: '1px solid #eaf1ee', paddingTop: '0.4rem' }}>
                    {visibleOptions.map((opt, idx) => {
                      const isLong = (opt.text || '').length > 20;
                      return (
                        <div
                          key={opt.id || idx}
                          style={{
                            textAlign: 'center',
                            color: '#00a884',
                            fontWeight: 600,
                            fontSize: '0.75rem',
                            padding: '0.35rem',
                            background: '#f4f7f5',
                            borderRadius: '4px',
                            border: '1px solid #d9e3de',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            gap: '0.3rem',
                          }}
                        >
                          {opt.text || `Opción ${idx + 1}`}
                          {isLong && (
                            <span style={{ color: '#d97706', fontSize: '0.65rem' }} title="Meta limita botones a 20 caracteres">
                              ⚠️
                            </span>
                          )}
                        </div>
                      );
                    })}
                  </div>
                ) : (
                  /* WhatsApp 4-10 Interactive List */
                  <div style={{ marginTop: '0.5rem', borderTop: '1px solid #eaf1ee', paddingTop: '0.4rem' }}>
                    <div
                      style={{
                        textAlign: 'center',
                        color: '#00a884',
                        fontWeight: 600,
                        fontSize: '0.75rem',
                        padding: '0.4rem',
                        background: '#eaf1ee',
                        borderRadius: '4px',
                        border: '1px solid #a9c8bb',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        gap: '0.3rem',
                      }}
                    >
                      <Layers size={13} /> Ver opciones ({visibleOptions.length})
                    </div>
                  </div>
                )}

                <div style={{ textAlign: 'right', fontSize: '0.65rem', color: '#6b7280', marginTop: '0.3rem' }}>
                  12:00 PM ✓✓
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};
