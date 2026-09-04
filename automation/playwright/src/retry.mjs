function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

export async function withRetries(operation, options = {}) {
    const retries = Number.isInteger(options.retries) ? options.retries : 0;
    const delayMs = Number.isInteger(options.delayMs) ? options.delayMs : 1000;
    const logger = options.logger;
    const label = options.label ?? 'operation';

    let lastError;

    for (let attempt = 1; attempt <= retries + 1; attempt += 1) {
        try {
            return await operation({ attempt });
        } catch (error) {
            lastError = error;

            if (attempt > retries) {
                break;
            }

            logger?.warn(`Retrying ${label}`, {
                attempt,
                retries,
                error: error instanceof Error ? error.message : String(error),
            });

            await delay(delayMs);
        }
    }

    throw lastError;
}
