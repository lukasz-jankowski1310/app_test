import { applicationFetch } from './zapq.js';

export function startPipeline(method, input, idempotencyKey) {
    return applicationFetch('run.php', {
        method: 'POST',
        body: new URLSearchParams({ method, input, idempotencyKey })
    });
}

export function receivedResult(runId) {
    return applicationFetch(`status.php?runId=${encodeURIComponent(runId)}`);
}
