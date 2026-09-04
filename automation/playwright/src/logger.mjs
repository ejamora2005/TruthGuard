const LEVELS = ['debug', 'info', 'warn', 'error'];

function normalizeLevel(level) {
    return LEVELS.includes(level) ? level : 'info';
}

function formatContext(context = {}) {
    const entries = Object.entries(context).filter(([, value]) => value !== undefined && value !== null && value !== '');

    if (entries.length === 0) {
        return '';
    }

    const serialized = entries
        .map(([key, value]) => `${key}=${JSON.stringify(value)}`)
        .join(' ');

    return ` ${serialized}`;
}

export class Logger {
    constructor(level = 'info') {
        this.level = normalizeLevel(level);
    }

    shouldLog(level) {
        return LEVELS.indexOf(normalizeLevel(level)) >= LEVELS.indexOf(this.level);
    }

    write(level, message, context = {}) {
        if (! this.shouldLog(level)) {
            return;
        }

        const line = `[${new Date().toISOString()}] [${normalizeLevel(level).toUpperCase()}] ${message}${formatContext(context)}\n`;
        process.stderr.write(line);
    }

    debug(message, context = {}) {
        this.write('debug', message, context);
    }

    info(message, context = {}) {
        this.write('info', message, context);
    }

    warn(message, context = {}) {
        this.write('warn', message, context);
    }

    error(message, context = {}) {
        this.write('error', message, context);
    }
}

export function createLogger(level) {
    return new Logger(level);
}
