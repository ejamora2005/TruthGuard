import './bootstrap';
import './pwa';
import '../css/app.css';
import './admin/admin-ui.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './admin/App';

const target = document.getElementById('admin-root');

if (target) {
    createRoot(target).render(
        <React.StrictMode>
            <App />
        </React.StrictMode>,
    );
}
