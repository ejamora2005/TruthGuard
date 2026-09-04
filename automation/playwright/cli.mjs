import { runScrape } from './src/runner.mjs';

function toCamelCase(value) {
    return value.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
}

function parseValue(value) {
    if (value === 'true') {
        return true;
    }

    if (value === 'false') {
        return false;
    }

    const numeric = Number(value);

    if (! Number.isNaN(numeric) && value.trim() !== '') {
        return numeric;
    }

    return value;
}

function parseArgs(argv) {
    const payload = {};

    for (const argument of argv) {
        if (! argument.startsWith('--')) {
            continue;
        }

        const trimmed = argument.slice(2);
        const [rawKey, rawValue] = trimmed.split('=', 2);
        const key = toCamelCase(rawKey);
        const value = rawValue === undefined ? true : parseValue(rawValue);

        if (key === 'readySelector') {
            payload.readySelectors = [...(payload.readySelectors ?? []), String(value)];
            continue;
        }

        payload[key] = value;
    }

    return payload;
}

async function readStdinPayload() {
    if (process.stdin.isTTY) {
        return {};
    }

    let buffer = '';

    for await (const chunk of process.stdin) {
        buffer += chunk;
    }

    const normalized = buffer.trim();

    if (normalized === '') {
        return {};
    }

    return JSON.parse(normalized);
}

try {
    const stdinPayload = await readStdinPayload();
    const argPayload = parseArgs(process.argv.slice(2));
    const payload = {
        ...stdinPayload,
        ...argPayload,
    };

    const result = await runScrape(payload);

    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
} catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    const stack = error instanceof Error && error.stack ? error.stack : message;

    process.stderr.write(`[${new Date().toISOString()}] [ERROR] ${stack}\n`);
    process.stdout.write(`${JSON.stringify({ ok: false, error: message }, null, 2)}\n`);
    process.exitCode = 1;
}
