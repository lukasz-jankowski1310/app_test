// Keep access tokens only in this module's memory. Never put them in URLs or browser storage.
const applicationUrl = new URL('../', import.meta.url);
let current;

export async function context() {
    if (!current || Date.parse(current.expiresAt) < Date.now() + 60_000) {
        const response = await fetch(new URL('_zapq/context', applicationUrl), { credentials: 'same-origin', cache: 'no-store' });
        if (!response.ok) {
            current = undefined;
            throw new Error(`Zapq context: HTTP ${response.status}`);
        }

        current = await response.json();
    }

    return current;
}

export async function applicationFetch(path, options = {}) {
    const url = new URL(path, applicationUrl);
    if (url.origin !== applicationUrl.origin || !url.pathname.startsWith(applicationUrl.pathname)) {
        throw new Error('The request must stay inside this application.');
    }

    const session = await context();
    const headers = new Headers(options.headers);
    headers.set(session.csrf.headerName, session.csrf.token);
    return fetch(url, { ...options, headers, credentials: 'same-origin', cache: 'no-store' });
}

export async function apiFetch(path, options = {}) {
    const session = await context();
    const base = new URL(session.apiUrl, location.origin);
    const url = new URL(path, base);
    if (url.origin !== base.origin || !url.pathname.startsWith(base.pathname)) {
        throw new Error('The request must stay inside the Zapq API.');
    }

    const headers = new Headers(options.headers);
    headers.set('Authorization', `Bearer ${session.token}`);
    return fetch(url, { ...options, headers, credentials: 'omit', cache: 'no-store', redirect: 'error' });
}
