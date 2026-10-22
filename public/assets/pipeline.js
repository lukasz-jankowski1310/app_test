import { startPipeline, receivedResult } from './runs.js';

const form = document.querySelector('#pipeline');
const message = document.querySelector('#pipeline-message');
const panel = document.querySelector('#pipeline-status');
const output = document.querySelector('#run-result');
const steps = document.querySelector('#step-logs');
const refresh = document.querySelector('#refresh-run');
let runId;
const requestKey = form.elements.idempotencyKey;
form.addEventListener('input', () => { requestKey.value = crypto.randomUUID(); });

async function readResult() {
    refresh.disabled = true;
    form.querySelector('button').disabled = true;
    try {
        const response = await receivedResult(runId);
        if (!response.ok) {
            throw new Error(`Odczyt wyniku: HTTP ${response.status}. Wynik jest dostępny dla autora uruchomienia.`);
        }

        steps.replaceChildren();
        const result = await response.json();
        output.textContent = JSON.stringify(result.webhook ?? { message: 'Oczekiwanie na webhook. Odczytaj wynik ponownie za chwilę.' }, null, 2);
        for (const step of result.steps ?? []) {
            const item = document.createElement('li');
            item.textContent = `Krok ${step.order + 1}: ${step.status}`;
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
        const response = await startPipeline(method, JSON.stringify({ Name: 'PHP webhook demo' }), requestKey.value);
        if (!response.ok) {
            throw new Error(`Uruchomienie: HTTP ${response.status}. Sprawdź nazwę metody i prawo Pipelines.Run.`);
        }

        runId = (await response.json()).runId;
        requestKey.value = crypto.randomUUID();
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
