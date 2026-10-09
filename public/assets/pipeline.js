// The browser calls the public Zapq API with the user's token, like exec.js. Its Host is the instance
// domain, so it also works where AllowedHosts rejects the internal origin used by run.php.
import { apiFetch } from './zapq.js';

const form = document.querySelector('#pipeline');
const message = document.querySelector('#pipeline-message');
const panel = document.querySelector('#pipeline-status');
const output = document.querySelector('#run-result');
const steps = document.querySelector('#step-logs');
const refresh = document.querySelector('#refresh-run');
let runId;

async function readResult() {
    refresh.disabled = true;
    form.querySelector('button').disabled = true;
    try {
        const response = await apiFetch(`runs/${encodeURIComponent(runId)}`);
        if (!response.ok) {
            throw new Error(`Odczyt wyniku: HTTP ${response.status}. Wymagane prawo Logs.View do potoku.`);
        }

        steps.replaceChildren();
        if (response.status === 204) {
            output.textContent = 'Uruchomienie nie ma kroków.';
            return;
        }

        const result = await response.json();
        output.textContent = JSON.stringify(result, null, 2);
        for (const step of result.steps ?? []) {
            const item = document.createElement('li');
            item.textContent = `${step.methodName}: ${step.status}`;
            steps.append(item);
        }
    } catch (error) {
        output.textContent = error.message;
    } finally {
        refresh.disabled = false;
        form.querySelector('button').disabled = false;
    }
}

form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button');
    const method = form.elements.method.value;
    button.disabled = true;
    panel.hidden = true;
    message.textContent = '';
    try {
        // No automatic retry: a timed-out POST may already have started the pipeline.
        const response = await apiFetch(`exec-pipeline/${encodeURIComponent(method)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source: 'web-app' })
        });
        if (!response.ok) {
            throw new Error(`Uruchomienie: HTTP ${response.status}. Sprawdź nazwę metody i prawo Pipelines.Run.`);
        }

        runId = (await response.json()).runId;
        message.textContent = `Uruchomiono: ${runId}`;
        document.querySelector('#run-link').href =
            `exec.php?method=${encodeURIComponent(method)}&runId=${encodeURIComponent(runId)}`;
        panel.hidden = false;
        await readResult();
    } catch (error) {
        message.textContent = error.message;
    } finally {
        button.disabled = false;
    }
});

refresh.addEventListener('click', readResult);
