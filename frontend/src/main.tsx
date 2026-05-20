import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './styles.css';
import { registerServiceWorker } from './push';

registerServiceWorker().catch(() => {
  // The app remains usable without service worker registration.
});

ReactDOM.createRoot(document.getElementById('root') as HTMLElement).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
