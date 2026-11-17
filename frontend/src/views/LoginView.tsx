import React, { useState } from 'react';
import { useAuth } from '../context/useAuth';
import { InteraLogo } from '../components/InteraLogo';
import { GitBranch, Lock, Mail, MessageSquare, RefreshCw, ShieldCheck, Users } from 'lucide-react';
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
    <main className="login-shell">
      <section className="login-story" aria-labelledby="login-story-title">
        <div className="login-story-inner">
          <div className="login-brand"><InteraLogo variant="dark" size={46} /></div>

          <div className="login-story-copy">
            <p className="login-eyebrow">ESPACIO DE CONVERSACIONES</p>
            <h1 id="login-story-title">Conversaciones que hacen equipo.</h1>
            <p className="login-story-description">
              Atiende mensajes, automatiza flujos y acompaña a cada persona desde un mismo lugar.
            </p>

            <ul className="login-highlights" aria-label="Herramientas de Intera">
              <li><MessageSquare size={18} aria-hidden="true" /><span>Canales conectados en una sola bandeja</span></li>
              <li><GitBranch size={18} aria-hidden="true" /><span>Flujos conversacionales para responder a tiempo</span></li>
              <li><Users size={18} aria-hidden="true" /><span>Un equipo coordinado alrededor de cada chat</span></li>
            </ul>
          </div>

          <div className="login-security-note">
            <ShieldCheck size={16} aria-hidden="true" />
            <span>Tu espacio cuenta con controles de seguridad para proteger el acceso.</span>
          </div>
        </div>
      </section>

      <section className="login-form-side" aria-labelledby="login-form-title">
        <div className="login-card">
          <div className="login-mobile-brand">
            <InteraLogo variant="dark" size={46} />
            <p>Conversaciones que hacen equipo.</p>
          </div>

          <div className="login-form-heading">
            <p className="login-eyebrow">BIENVENIDO DE NUEVO</p>
            <h2 id="login-form-title">Iniciar sesión</h2>
            <p>Ingresa tus credenciales para acceder a Intera.</p>
          </div>

          <form onSubmit={handleSubmit}>
            <div className="form-group">
              <label htmlFor="login-email" className="form-label login-field-label">
                <Mail size={15} aria-hidden="true" /> Correo electrónico
              </label>
              <input
                id="login-email"
                type="email"
                className="form-input"
                placeholder="nombre@empresa.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="username"
              />
            </div>

            <div className="form-group">
              <label htmlFor="login-password" className="form-label login-field-label">
                <Lock size={15} aria-hidden="true" /> Contraseña
              </label>
              <input
                id="login-password"
                type="password"
                className="form-input"
                placeholder="Ingresa tu contraseña"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                minLength={12}
                autoComplete="current-password"
                aria-describedby="login-password-hint"
              />
              <span id="login-password-hint" className="login-field-hint">Mínimo 12 caracteres</span>
            </div>

            <button
              type="submit"
              className="btn btn-primary login-submit"
              disabled={loading}
            >
              {loading ? (
                <>
                  <RefreshCw size={16} className="spin" aria-hidden="true" />
                  <span>Verificando credenciales…</span>
                </>
              ) : (
                'Iniciar sesión'
              )}
            </button>
          </form>

        </div>
      </section>
    </main>
  );
};
