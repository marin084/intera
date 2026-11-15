import React, { useState } from 'react';
import { api } from '../services/api';
import { useAuth } from '../context/useAuth';
import { Lock, CheckCircle2, AlertCircle, Shield, User } from 'lucide-react';

export const AccountView: React.FC = () => {
  const { user, roles } = useAuth();
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const handlePasswordSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSuccessMessage(null);
    setErrorMessage(null);
    setFieldErrors({});

    if (password.length < 12) {
      setErrorMessage('La nueva contraseña debe tener al menos 12 caracteres.');
      return;
    }

    if (password !== passwordConfirmation) {
      setErrorMessage('La confirmación de la nueva contraseña no coincide.');
      return;
    }

    setLoading(true);
    try {
      const res = await api.changePassword({
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      });

      setSuccessMessage(res.message || 'Contraseña actualizada exitosamente.');
      setCurrentPassword('');
      setPassword('');
      setPasswordConfirmation('');
    } catch (err: any) {
      if (err.data?.errors) {
        setFieldErrors(err.data.errors);
      }
      setErrorMessage(err.data?.message || err.message || 'Error al actualizar la contraseña.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="account-page">
      {/* Account Info Card */}
      <div className="card" style={{ marginBottom: '1.5rem' }}>
        <div className="card-header">
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            <User size={22} color="#5d8f82" />
            <div>
              <h2 className="card-title">Perfil y sesión</h2>
              <p style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>
                Detalles del perfil y sesión activa
              </p>
            </div>
          </div>
        </div>
        <div className="account-profile-grid" style={{ fontSize: '0.9rem' }}>
          <div>
            <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', display: 'block' }}>Nombre</span>
            <span style={{ fontWeight: 600 }}>{user?.name}</span>
          </div>
          <div>
            <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', display: 'block' }}>Correo Electrónico</span>
            <span style={{ fontWeight: 600 }}>{user?.email}</span>
          </div>
          <div>
            <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', display: 'block' }}>Rol Asignado</span>
            <span className="badge badge-info">{roles[0] || 'Usuario'}</span>
          </div>
          <div>
            <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', display: 'block' }}>Estado</span>
            <span className="badge badge-success">Activo</span>
          </div>
        </div>
      </div>

      {/* Change Password Card */}
      <div className="card">
        <div className="card-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            <Lock size={22} color="#5d8f82" />
            <div>
              <h2 className="card-title">Cambiar Contraseña</h2>
              <p style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>
                Actualiza tu credencial de acceso a la plataforma (mínimo 12 caracteres)
              </p>
            </div>
          </div>
        </div>

        {successMessage && (
          <div
            className="alert alert-success"
            data-testid="password-success-message"
            style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '1.25rem' }}
          >
            <CheckCircle2 size={18} />
            <span>{successMessage}</span>
          </div>
        )}

        {errorMessage && (
          <div
            className="alert alert-danger"
            data-testid="password-error-message"
            style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '1.25rem' }}
          >
            <AlertCircle size={18} />
            <span>{errorMessage}</span>
          </div>
        )}

        <form onSubmit={handlePasswordSubmit} className="account-password-form">
          <div className="form-group">
            <label className="form-label" htmlFor="current_password">
              Contraseña Actual *
            </label>
            <input
              id="current_password"
              data-testid="current-password-input"
              type="password"
              className="form-input"
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              required
              disabled={loading}
              placeholder="Ingresa tu contraseña actual"
            />
            {fieldErrors.current_password && (
              <span style={{ color: '#dc2626', fontSize: '0.75rem', marginTop: '0.25rem', display: 'block' }}>
                {fieldErrors.current_password[0]}
              </span>
            )}
          </div>

          <div className="form-group">
            <label className="form-label" htmlFor="new_password">
              Nueva Contraseña (mínimo 12 caracteres) *
            </label>
            <input
              id="new_password"
              data-testid="new-password-input"
              type="password"
              className="form-input"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              minLength={12}
              disabled={loading}
              placeholder="Mínimo 12 caracteres seguros"
            />
            {fieldErrors.password && (
              <span style={{ color: '#dc2626', fontSize: '0.75rem', marginTop: '0.25rem', display: 'block' }}>
                {fieldErrors.password[0]}
              </span>
            )}
          </div>

          <div className="form-group">
            <label className="form-label" htmlFor="new_password_confirmation">
              Confirmar Nueva Contraseña *
            </label>
            <input
              id="new_password_confirmation"
              data-testid="confirm-password-input"
              type="password"
              className="form-input"
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              required
              minLength={12}
              disabled={loading}
              placeholder="Vuelve a ingresar la nueva contraseña"
            />
            {password && passwordConfirmation && (
              <span
                style={{
                  fontSize: '0.75rem',
                  marginTop: '0.35rem',
                  display: 'flex',
                  alignItems: 'center',
                  gap: '0.25rem',
                  color: password === passwordConfirmation ? '#5d8f82' : '#dc2626',
                  fontWeight: 500,
                }}
              >
                {password === passwordConfirmation
                  ? '✓ Las contraseñas coinciden'
                  : '⚠ Las contraseñas no coinciden aún'}
              </span>
            )}
          </div>

          <div className="account-password-security" style={{ background: '#f4f7f5', padding: '0.75rem 1rem', borderRadius: '6px', border: '1px solid #d9e3de', marginBottom: '0.5rem', fontSize: '0.8rem', color: '#61726d' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', fontWeight: 600, color: '#56736b', marginBottom: '0.25rem' }}>
              <Shield size={14} /> Requisitos de Seguridad:
            </div>
            • Mínimo 12 caracteres.<br />
            • Al cambiar la contraseña, tu sesión actual se mantiene activa y las sesiones antiguas se invalidan de forma segura.
          </div>

          <button
            type="submit"
            data-testid="submit-change-password-btn"
            className="btn btn-primary"
            disabled={loading}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem' }}
          >
            <Lock size={15} />
            {loading ? 'Actualizando contraseña...' : 'Actualizar Contraseña'}
          </button>
        </form>
      </div>
    </div>
  );
};
