import React, { useState } from 'react';
import { useAuth } from '../context/useAuth';
import { InteraLogo } from '../components/InteraLogo';
import { Lock, Mail, RefreshCw } from 'lucide-react';
import { useToast } from '../context/ToastContext';

export const LoginView: React.FC = () => {
  const { login } = useAuth();
  const { showToast } = useToast();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);

    try {
      await login({ email, password });
    } catch (err: any) {
      showToast(err.message || 'Error al iniciar sesión.', 'error');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-shell" style={{
      display: 'flex',
      minHeight: '100vh',
      alignItems: 'center',
      justifyContent: 'center',
      background: 'linear-gradient(135deg, #263c38 0%, #35564f 100%)',
      padding: '1.5rem',
    }}>
      <div className="login-card" style={{
        width: '100%',
        maxWidth: '420px',
        background: '#ffffff',
        borderRadius: '12px',
        padding: '2.5rem 2rem',
        boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1)',
      }}>
        <div style={{ textAlign: 'center', marginBottom: '2rem' }}>
          <h1 style={{ margin: '0 0 0.75rem', display: 'flex', justifyContent: 'center' }}>
            <InteraLogo size={52} />
          </h1>
          <p style={{ color: '#61726d', fontSize: '0.875rem', marginTop: '0.25rem' }}>
            Conversaciones que hacen equipo.
          </p>
        </div>

        <form onSubmit={handleSubmit}>
          <div className="form-group">
            <label className="form-label" style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
              <Mail size={14} /> Correo Electrónico
            </label>
            <input
              type="email"
              className="form-input"
              placeholder="admin@empresa.com"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              autoFocus
            />
          </div>

          <div className="form-group">
            <label className="form-label" style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
              <Lock size={14} /> Contraseña (mínimo 12 caracteres)
            </label>
            <input
              type="password"
              className="form-input"
              placeholder="••••••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              minLength={12}
            />
          </div>

          <button
            type="submit"
            className="btn btn-primary"
            style={{ width: '100%', justifyContent: 'center', marginTop: '1rem', padding: '0.65rem', display: 'flex', alignItems: 'center', gap: '0.4rem' }}
            disabled={loading}
          >
            {loading ? (
              <>
                <RefreshCw size={16} className="spin" />
                <span>Verificando credenciales...</span>
              </>
            ) : (
              'Iniciar Sesión'
            )}
          </button>
        </form>

        <div style={{ marginTop: '1.5rem', paddingTop: '1.25rem', borderTop: '1px solid #d9e3de', textAlign: 'center' }}>
          <p style={{ fontSize: '0.75rem', color: '#80918c' }}>
            Protección contra fuerza bruta y rate-limiting activados.
          </p>
        </div>
      </div>
    </div>
  );
};
