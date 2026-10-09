import { applicationFetch } from './zapq.js';

const FINISHED = new Set(['Ok', 'Error', 'NeedsAttention', 'Cancelled']);
const POLL_INTERVAL = 2000;
const POLL_LIMIT = 90; // About three minutes; afterwards the user refreshes manually.
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
let timer;
let polls = 0;

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
        case 403: return 'Brak prawa Logs.View do potoku (HTTP 403). Samo uruchomienie mogło się udać.';
        case 404: return 'Nie znaleziono uruchomienia (HTTP 404).';
        default: return `Odczyt wyniku: HTTP ${response.status}: ${await reason(response)}`;
    }
}

async function loadLogs(stepId) {
    const item = steps.get(stepId);
    try {
        const response = await applicationFetch(
            `status.php?runId=${encodeURIComponent(runId)}&stepRunId=${encodeURIComponent(stepId)}`);
        if (!response.ok) {
            throw new Error(await readError(response));
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
        const link = document.createElement('a');
        badge.className = 'badge';
        logs.textContent = 'Wczytywanie logów…';
        link.href = `status.php?runId=${encodeURIComponent(runId)}&stepRunId=${encodeURIComponent(step.id)}`;
        link.textContent = 'Logi JSON';
        summary.append(badge, ' ', title);
        const heading = text => Object.assign(document.createElement('h4'), { textContent: text });
        details.append(summary, heading('Wyjście'), output, heading('Logi'), logs, link);
        details.addEventListener('toggle', () => details.open && loadLogs(step.id));
        stepList.append(details);
        item = { details, title, badge, output, logs };
        steps.set(step.id, item);
    } else if (item.details.open) {
        loadLogs(step.id);
    }

    const attempts = step.attemptCount > 1 ? `, próby: ${step.attemptCount}` : '';
    item.title.textContent = `${step.order}. ${step.methodName ?? '(bez nazwy)'}` +
        `${step.runnerName ? ` na ${step.runnerName}` : ''}${attempts}`;
    setBadge(item.badge, step.status);
    item.output.textContent = step.output == null ? 'Brak wyjścia.' : pretty(step.output);
}

function render(run) {
    setBadge(document.querySelector('#run-status'), run.status);
    document.querySelector('#run-meta').textContent = [
        run.pipelineName && `Potok: ${run.pipelineName}`,
        run.requester?.userName && `Autor: ${run.requester.userName}`,
        run.createdAt && `Start: ${time(run.createdAt)}`,
        run.finishedAt && `Koniec: ${time(run.finishedAt)}`
    ].filter(Boolean).join(' · ');
    document.querySelector('#run-output').textContent = run.lastOutput == null ? 'Brak.' : pretty(run.lastOutput);
    document.querySelector('#run-raw').textContent = JSON.stringify(run, null, 2);
    for (const step of run.steps ?? []) {
        renderStep(step);
    }
}

async function refresh() {
    clearTimeout(timer);
    const id = runId;
    refreshButton.disabled = true;
    runError.textContent = '';
    try {
        const response = await applicationFetch(`status.php?runId=${encodeURIComponent(id)}`);
        if (id !== runId) {
            return; // A newer run replaced this one while the request was in flight.
        }

        if (!response.ok) {
            throw new Error(await readError(response));
        }

        // 204: the run has no steps (yet).
        const run = response.status === 204 ? { runId: id, status: 'Waiting', steps: [] } : await response.json();
        render(run);
        if (!FINISHED.has(run.status)) {
            if (++polls < POLL_LIMIT) {
                timer = setTimeout(refresh, POLL_INTERVAL);
            } else {
                runError.textContent = 'Automatyczne odświeżanie zatrzymane. Kliknij „Odśwież”.';
            }
        }
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
    clearTimeout(timer);
    runId = id;
    polls = 0;
    steps.clear();
    stepList.replaceChildren();
    document.querySelector('#run-id').textContent = id;
    document.querySelector('#run-json').href = `status.php?runId=${encodeURIComponent(id)}`;
    for (const selector of ['#run-meta', '#run-output', '#run-raw']) {
        document.querySelector(selector).textContent = '';
    }

    setBadge(document.querySelector('#run-status'), '…');
    panel.hidden = false;
    // Only IDs go into the address, so a reload shows the same run. Never put tokens in URLs.
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
        const response = await applicationFetch('run.php', {
            method: 'POST', body: new URLSearchParams(new FormData(form))
        });
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
    polls = 0;
    refresh();
});

const initial = new URLSearchParams(location.search).get('runId');
if (initial && RUN_ID.test(initial)) {
    show(initial);
}
