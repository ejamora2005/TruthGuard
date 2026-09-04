import { getAdminConfig } from './admin-config';

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export async function fetchAdminJson(endpoint) {
    const config = getAdminConfig();
    const response = await fetch(`${config.apiBaseUrl}${endpoint}`, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (response.status === 419 || response.status === 401) {
        let redirectUrl = config.loginUrl;

        try {
            const payload = await response.json();
            redirectUrl = payload.redirect_url || redirectUrl;
        } catch (error) {
            // Keep the configured login URL when the response is not JSON.
        }

        window.location.assign(redirectUrl);
        throw new Error('Your session expired. Please sign in again.');
    }

    if (!response.ok) {
        throw new Error('Unable to load admin data right now.');
    }

    return response.json();
}

export async function sendAdminJson(endpoint, { method = 'POST', body = {} } = {}) {
    const config = getAdminConfig();
    const response = await fetch(`${config.apiBaseUrl}${endpoint}`, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    if (response.status === 419 || response.status === 401) {
        let redirectUrl = config.loginUrl;

        try {
            const payload = await response.json();
            redirectUrl = payload.redirect_url || redirectUrl;
        } catch (error) {
            // Keep the configured login URL when the response is not JSON.
        }

        window.location.assign(redirectUrl);
        throw new Error('Your session expired. Please sign in again.');
    }

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationMessage = payload.errors
            ? Object.values(payload.errors).flat().find(Boolean)
            : null;

        throw new Error(validationMessage || payload.message || 'Unable to save this source right now.');
    }

    return payload;
}

export async function postLogout() {
    const config = getAdminConfig();

    await fetch(config.logoutUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({}),
    });

    window.location.assign(config.loginUrl);
}
