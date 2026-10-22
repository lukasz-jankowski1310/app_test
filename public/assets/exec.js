// PHP starts the pipeline and receives its result through the signed webhook.
import { apiFetch } from './zapq.js';
import { startPipeline, receivedResult } from './runs.js';

const RUN_ID = /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i;
const UNCERTAIN = 'Brak odpowiedzi API: nie wiadomo, czy potok wystartował. Kliknij „Uruchom” jeszcze raz ' +
    'bez zmian, a ten sam klucz idempotencji zwróci istniejące uruchomienie zamiast startować drugie.';

const form = document.querySelector('#exec');
const key = form.elements.idempotencyKey;
const submit = form.querySelector('button[type="submit"]');
const message = document.querySelector('#message');
const panel = document.querySelector('#run');
const refreshButton = document.querySelector('#refresh');
const runError = document.querySelector('#run-error');
const stepList = document.querySelector('#steps');
const steps = new Map();
let runId;

function newKey() {
    key.value = crypto.randomUUID();
}

function time(value) {
    return value ? new Date(value).toLocaleString('pl-PL') : '';
}

function pretty(text) {
    try {
        return JSON.stringify(JSON.parse(text), null, 2);
    } catch {
        return text;
    }
}

function setBadge(element, status) {
    element.textContent = status ?? '?';
    element.dataset.status = status ?? '';
}

async function reason(response) {
    const text = await response.text();
    try {
        const body = JSON.parse(text);
        return body?.error ?? body?.detail ?? body?.title ?? text;
    } catch {
        return text.slice(0, 300);
    }
}

async function startError(response) {
    switch (response.status) {
        case 401: return 'Sesja wygasła (HTTP 401). Odśwież stronę.';
        case 403: return 'Brak prawa Pipelines.Run do potoku tej metody (HTTP 403).';
        case 404: return `Nie ma metody API „${form.elements.method.value}” (HTTP 404).`;
        case 409:
            newKey();
            return 'Ten klucz idempotencji użyto już z innym wejściem. Wygenerowano nowy, uruchom ponownie.';
        case 502: case 503: case 504: return UNCERTAIN;
        default: return `Uruchomienie odrzucone, HTTP ${response.status}: ${await reason(response)}`;
    }
}

async function readError(response) {
    switch (response.status) {
        case 401: return 'Sesja wygasła (HTTP 401). Odśwież stronę.';
        case 403: return 'Brak dostępu do aplikacji (HTTP 403).';
        case 404: return 'Uruchomienie nie zostało wykonane przez Ciebie w tej aplikacji (HTTP 404).';
        default: return `Odczyt wyniku: HTTP ${response.status}: ${await reason(response)}`;
    }
}

async function loadLogs(stepId) {
    const item = steps.get(stepId);
    try {
        const response = await apiFetch(`runs/${encodeURIComponent(runId)}/steps/${encodeURIComponent(stepId)}/logs`);
        if (!response.ok) {
            throw new Error(`Odczyt logów: HTTP ${response.status}. Wymagane prawo Logs.View do potoku.`);
        }

        const result = await response.json();
        item.logs.textContent = result.logs?.length
            ? result.logs.map(line => `${time(line.date)}  ${line.level}  ${line.message}`).join('\n')
            : 'Brak logów.';
    } catch (error) {
        item.logs.textContent = error.message;
    }
}

function renderStep(step) {
    let item = steps.get(step.id);
    if (!item) {
        const details = document.createElement('details');
        const summary = document.createElement('summary');
        const title = document.createElement('span');
        const badge = document.createElement('span');
        const output = document.createElement('pre');
        const logs = document.createElement('pre');
        badge.className = 'badge';
        logs.textContent = 'Wczytywanie logów…';
        summary.append(badge, ' ', title);
        const heading = text => Object.assign(document.createElement('h4'), { textContent: text });
        details.append(summary, heading('Wyjście'), output, heading('Logi'), logs);
        details.addEventListener('toggle', () => details.open && loadLogs(step.id));
        stepList.append(details);
        item = { details, title, badge, output, logs };
        steps.set(step.id, item);
    } else if (item.details.open) {
        loadLogs(step.id);
    }

    const attempts = step.attemptCount > 1 ? `, próby: ${step.attemptCount}` : '';
    item.title.textContent = `${step.order + 1}. ${step.methodName ?? 'Krok potoku'}` +
        `${step.runnerName ? ` na ${step.runnerName}` : ''}${attempts}`;
    setBadge(item.badge, step.status);
    item.output.textContent = step.output == null ? 'Webhook przekazuje wynik całego potoku poniżej statusu.' : pretty(step.output);
}

function render(run) {
    setBadge(document.querySelector('#run-status'), run.webhookReceived ? run.status : 'Oczekiwanie na webhook');
    document.querySelector('#run-meta').textContent = [
        run.apiMethod && `Metoda: ${run.apiMethod}`,
        run.requester?.userName && `Autor: ${run.requester.userName}`,
        run.createdAt && `Start: ${time(run.createdAt)}`,
        run.receivedAt && `Webhook odebrany: ${time(run.receivedAt)}`
    ].filter(Boolean).join(' · ');
    document.querySelector('#run-output').textContent = run.lastOutput == null ? 'Brak.' : pretty(run.lastOutput);
    document.querySelector('#run-raw').textContent = JSON.stringify(run.webhook ?? { message: 'PHP czeka na webhook z wynikiem potoku.' }, null, 2);
    for (const step of run.steps ?? []) {
        renderStep(step);
    }
}

async function refresh() {
    const id = runId;
    refreshButton.disabled = true;
    runError.textContent = '';
    try {
        const response = await receivedResult(id);
        if (id !== runId) {
            return; // A newer run replaced this one while the request was in flight.
        }

        if (!response.ok) {
            throw new Error(await readError(response));
        }

        render(await response.json());
    } catch (error) {
        if (id === runId) {
            runError.textContent = error.message;
        }
    } finally {
        if (id === runId) {
            refreshButton.disabled = false;
        }
    }
}

function show(id) {
    runId = id;
    steps.clear();
    stepList.replaceChildren();
    document.querySelector('#run-id').textContent = id;
    for (const selector of ['#run-meta', '#run-output', '#run-raw']) {
        document.querySelector(selector).textContent = '';
    }

    setBadge(document.querySelector('#run-status'), '…');
    panel.hidden = false;
    // Only IDs go into the address. The result is read from PHP's local webhook storage.
    const url = new URL(location.href);
    url.searchParams.set('method', form.elements.method.value);
    url.searchParams.set('runId', id);
    history.replaceState(null, '', url);
    refresh();
}

form.addEventListener('submit', async event => {
    event.preventDefault();
    try {
        JSON.parse(form.elements.input.value);
    } catch (error) {
        message.textContent = `Wejście nie jest poprawnym JSON: ${error.message}`;
        form.elements.input.focus();
        return;
    }

    submit.disabled = true;
    message.textContent = 'Uruchamianie…';
    try {
        // No automatic retry: a lost response may hide a started run. The user repeats it with the same key.
        const response = await startPipeline(form.elements.method.value, form.elements.input.value, key.value);
        if (!response.ok) {
            throw new Error(await startError(response));
        }

        const { runId: id } = await response.json();
        newKey();
        message.textContent = `Uruchomiono ${time(new Date())}.`;
        show(id);
    } catch (error) {
        message.textContent = error instanceof TypeError ? UNCERTAIN : error.message;
    } finally {
        submit.disabled = false;
    }
});

// A changed request is a different request, so it gets a new idempotency key.
form.addEventListener('input', newKey);
form.elements.input.addEventListener('keydown', event => {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        form.requestSubmit();
    }
});

document.querySelector('#lookup').addEventListener('submit', event => {
    event.preventDefault();
    message.textContent = '';
    show(event.target.elements.runId.value.trim());
});

refreshButton.addEventListener('click', () => {
    refresh();
});

const initial = new URLSearchParams(location.search).get('runId');
if (initial && RUN_ID.test(initial)) {
    show(initial);
}
