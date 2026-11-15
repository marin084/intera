import React from 'react';
import ReactDOM from 'react-dom/client';
import { WebchatApp } from './WebchatApp';

const rootEl = document.getElementById('webchat-root');
if (rootEl) {
  ReactDOM.createRoot(rootEl).render(
    <React.StrictMode>
      <WebchatApp />
    </React.StrictMode>
  );
}
