import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../public/assets/zapq.js', import.meta.url), 'utf8');

test('all browser modules have valid JavaScript syntax', async () => {
    for (const file of ['zapq.js', 'pipeline.js']) {
        new vm.SourceTextModule(await readFile(new URL(`../public/assets/${file}`, import.meta.url), 'utf8'));
    }
});

async function client(prefix = '/firma', denyContext = false) {
    const calls = [];
    let now = Date.now();
    const context = vm.createContext({
        URL, Headers, location: { origin: 'https://app.example' },
        Date: class extends Date { static now() { return now; } },
        fetch: async (url, options) => {
            calls.push({ url: String(url), options });
            if (String(url).endsWith('_zapq/context')) {
                return new Response(JSON.stringify({
                    apiUrl: `${prefix}/api/`, token: `token-${calls.length}`,
                    expiresAt: new Date(now + 900_000).toISOString(),
                    csrf: { headerName: 'X-CSRF', token: 'csrf-value' }
                }), { status: denyContext ? 403 : 200 });
            }

            return new Response('{}');
        }
    });
    const module = new vm.SourceTextModule(source, {
        context,
        initializeImportMeta(meta) { meta.url = `https://app.example${prefix}/ui/notes/assets/zapq.js`; }
    });
    await module.link(() => { throw new Error('Unexpected dependency'); });
    await module.evaluate();
    return { api: module.namespace, calls, advance: milliseconds => { now += milliseconds; } };
}

for (const prefix of ['', '/firma']) {
    test(`session, CSRF, bearer and PathBase '${prefix}'`, async () => {
        const { api, calls } = await client(prefix);
        await api.applicationFetch('state.php', { method: 'POST', body: 'value=example' });
        assert.equal(calls[0].url, `https://app.example${prefix}/ui/notes/_zapq/context`);
        assert.equal(calls[1].url, `https://app.example${prefix}/ui/notes/state.php`);
        assert.equal(calls[1].options.headers.get('X-CSRF'), 'csrf-value');
        assert.equal(calls[1].options.credentials, 'same-origin');
        assert.equal(calls[1].options.headers.get('Authorization'), null);
        await api.apiFetch('runs/123', { headers: { Authorization: 'forged' } });
        assert.equal(calls[2].url, `https://app.example${prefix}/api/runs/123`);
        assert.equal(calls[2].options.headers.get('Authorization'), 'Bearer token-1');
        assert.equal(calls[2].options.credentials, 'omit');
        assert.equal(calls[2].options.redirect, 'error');
        assert.equal(calls[2].options.cache, 'no-store');
    });
}

test('context refreshes before expiry without browser storage or refresh tokens', async () => {
    const { api, calls, advance } = await client();
    const first = await api.context();
    assert.equal(await api.context(), first);
    assert.equal(calls.length, 1);
    advance(850_000);
    assert.notEqual((await api.context()).token, first.token);
    assert.equal(calls.length, 2);
});

test('application and API paths cannot send credentials outside their prefixes', async () => {
    const { api, calls } = await client();
    for (const path of ['https://evil.example/', '//evil.example/', '../other/', '/api/', '%2e%2e/other/']) {
        await assert.rejects(api.applicationFetch(path));
    }

    assert.equal(calls.length, 0);
    for (const path of ['https://evil.example/', '//evil.example/', '../ui/', '/firma/api-other/']) {
        await assert.rejects(api.apiFetch(path));
    }

    assert.equal(calls.length, 1); // Context only; no outgoing authenticated request.
});

test('denied session stops the operation', async () => {
    const { api, calls } = await client('/firma', true);
    await assert.rejects(api.applicationFetch('state.php'), /HTTP 403/);
    await assert.rejects(api.apiFetch('runs/123'), /HTTP 403/);
    assert.equal(calls.length, 2);
    assert.ok(calls.every(call => call.url.endsWith('_zapq/context')));
});
