export async function stopAnalysis(url, { onConfirmed } = {}, environment = window) {
    if (!environment.TruthGuardDialog?.confirm) throw new Error('Confirmation is not ready. Please try again.');
    const accepted = await environment.TruthGuardDialog.confirm('Stop this fact check? You can start a new check later.', {
        title: 'Stop fact check?', confirmLabel: 'Stop fact check', cancelLabel: 'Keep checking', tone: 'danger',
    });
    if (!accepted) return null;
    const target = new URL(url, environment.location.href);
    if (target.origin !== environment.location.origin) throw new Error('The stop request could not be sent.');
    onConfirmed?.();
    const controller = new AbortController();
    const timeout = environment.setTimeout(() => controller.abort(), 15000);
    try {
        const response = await environment.fetch(target.href, {
            method: 'POST', credentials: 'same-origin', signal: controller.signal,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': environment.document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ confirmed: true }),
        });
        if (!response.ok || response.redirected) throw new Error('The stop request could not be confirmed. Check the current status and try again.');
        return await response.json();
    } finally {
        environment.clearTimeout(timeout);
    }
}

if (typeof window !== 'undefined') window.TruthGuardAnalysis = { stop: stopAnalysis };
