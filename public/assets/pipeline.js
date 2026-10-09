import { applicationFetch } from './zapq.js';

const form = document.querySelector('#pipeline');
const message = document.querySelector('#pipeline-message');
const panel = document.querySelector('#pipeline-status');
const output = document.querySelector('#run-result');
const logs = document.querySelector('#step-logs');
const refresh = document.querySelector('#refresh-run');
let runId;

async function readResult() {
    refresh.disabled = true;
    form.querySelector('button').disabled = true;
    try {
        const response = await applicationFetch(`status.php?runId=${encodeURIComponent(runId)}`);
        if (!response.ok) {
            throw new Error(`Odczyt wyniku: HTTP ${response.status}. Wymagane prawo Logs.View do potoku.`);
        }

        logs.replaceChildren();
        if (response.status === 204) {
            output.textContent = 'Uruchomienie nie ma kroków.';
            return;
        }

        const result = await response.json();
        output.textContent = JSON.stringify(result, null, 2);
        for (const step of result.steps ?? []) {
            const item = document.createElement('li');
            const link = document.createElement('a');
            link.href = `status.php?runId=${encodeURIComponent(runId)}&stepRunId=${encodeURIComponent(step.id)}`;
            link.textContent = `Logi kroku: ${step.methodName}`;
            item.append(link);
            logs.append(item);
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
    button.disabled = true;
    panel.hidden = true;
    message.textContent = '';
    try {
        // No automatic retry: a timed-out POST may already have started the pipeline.
        const response = await applicationFetch('run.php', {
            method: 'POST', body: new URLSearchParams(new FormData(form))
        });
        if (!response.ok) {
            throw new Error(`Uruchomienie: HTTP ${response.status}. Sprawdź nazwę metody i prawo Pipelines.Run.`);
        }

        runId = (await response.json()).runId;
        message.textContent = `Uruchomiono: ${runId}`;
        document.querySelector('#run-link').href = `status.php?runId=${encodeURIComponent(runId)}`;
        panel.hidden = false;
        await readResult();
    } catch (error) {
        message.textContent = error.message;
    } finally {
        button.disabled = false;
    }
});

refresh.addEventListener('click', readResult);
