export function formatNumber(value) {
    return new Intl.NumberFormat().format(Number(value ?? 0));
}

export function verdictBadgeClass(verdictKey) {
    if (verdictKey === 'fake') {
        return 'bg-error-50 text-error-600';
    }

    if (verdictKey === 'review') {
        return 'bg-warning-50 text-warning-700';
    }

    return 'bg-success-50 text-success-600';
}

export function alertToneClass(tone) {
    if (tone === 'warning') {
        return 'border-warning-200 bg-warning-50 text-warning-700';
    }

    if (tone === 'error') {
        return 'border-error-200 bg-error-50 text-error-600';
    }

    return 'border-brand-200 bg-brand-50 text-brand-500';
}
