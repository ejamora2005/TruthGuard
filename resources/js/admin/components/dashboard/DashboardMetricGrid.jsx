import { StatCard } from '../shared/StatCard';

const badgeClasses = ['bg-success-50 text-success-600', 'bg-brand-50 text-brand-500', 'bg-warning-50 text-warning-700', 'bg-error-50 text-error-600'];
const tones = ['blue', 'emerald', 'amber', 'rose'];

function AccountsIcon() {
    return (
        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M16 19c0-2.2-1.8-4-4-4H8c-2.2 0-4 1.8-4 4M20 19c0-1.8-1.2-3.3-2.9-3.8M15 5.3a4 4 0 0 1 0 7.4M10 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
        </svg>
    );
}

function SubscriberIcon() {
    return (
        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 3 5 6v5c0 4.6 3 8.1 7 10 4-1.9 7-5.4 7-10V6l-7-3Z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
            <path d="m8.5 12 2.2 2.2 4.8-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function DetectionIcon() {
    return (
        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 12h4l2-6 4 12 2-6h4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function VerifiedIcon() {
    return (
        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M20 7 10 17l-5-5" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function iconForMetric(label) {
    const normalized = label.toLowerCase();

    if (normalized.includes('subscriber')) {
        return <SubscriberIcon />;
    }

    if (normalized.includes('detection')) {
        return <DetectionIcon />;
    }

    if (normalized.includes('verified')) {
        return <VerifiedIcon />;
    }

    return <AccountsIcon />;
}

export function DashboardMetricGrid({ metrics }) {
    return (
        <div className="truthguard-admin-metric-grid grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            {metrics.map((metric, index) => (
                <StatCard
                    key={metric.label}
                    label={metric.label}
                    value={metric.value}
                    note={metric.note}
                    badge="Live"
                    badgeClass={badgeClasses[index % badgeClasses.length]}
                    icon={iconForMetric(metric.label)}
                    tone={tones[index % tones.length]}
                />
            ))}
        </div>
    );
}
