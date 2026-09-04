import { formatNumber } from '../../lib/formatters';

function RefreshIcon() {
    return (
        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M20 12a8 8 0 0 1-13.4 5.9M4 12A8 8 0 0 1 17.4 6.1M17 3v4h-4M7 21v-4h4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function ScanIcon() {
    return (
        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M7 4H5a1 1 0 0 0-1 1v2M17 4h2a1 1 0 0 1 1 1v2M7 20H5a1 1 0 0 1-1-1v-2M17 20h2a1 1 0 0 0 1-1v-2M7.5 12h9M12 7.5v9" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
        </svg>
    );
}

function ExternalIcon() {
    return (
        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M14 5h5v5M13 11l6-6M19 14v4.2A1.8 1.8 0 0 1 17.2 20H5.8A1.8 1.8 0 0 1 4 18.2V6.8A1.8 1.8 0 0 1 5.8 5H10" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function TileIcon({ tone }) {
    const common = 'currentColor';

    if (tone === 'amber') {
        return (
            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 8v5l3 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z" stroke={common} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        );
    }

    if (tone === 'emerald') {
        return (
            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="m5 13 4 4L19 7" stroke={common} strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        );
    }

    if (tone === 'violet') {
        return (
            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 3 5 6v5c0 4.6 3 8.1 7 10 4-1.9 7-5.4 7-10V6l-7-3Z" stroke={common} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        );
    }

    return (
        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 18V8M9 18V5M14 18v-7M19 18V9" stroke={common} strokeWidth="2" strokeLinecap="round" />
        </svg>
    );
}

function getModerationItem(items, label) {
    return items?.find((item) => item.label.toLowerCase().includes(label)) ?? { value: 0, percent: 0 };
}

export function DashboardHero({ data, onRefresh, websiteUrl }) {
    const reviewQueue = getModerationItem(data.moderation.items, 'review');
    const likelyFake = getModerationItem(data.moderation.items, 'fake');
    const usagePercent = Math.min(Number(data.aiUsage.usagePercent ?? 0), 100);
    const snapshotTiles = [
        {
            label: 'Runs this month',
            value: formatNumber(data.aiUsage.currentMonth),
            note: `${formatNumber(data.aiUsage.estimatedRequests)} total`,
            tone: 'blue',
        },
        {
            label: 'Review queue',
            value: formatNumber(reviewQueue.value),
            note: `${reviewQueue.percent ?? 0}% of cases`,
            tone: 'amber',
        },
        {
            label: 'Subscriber coverage',
            value: `${data.subscription.coverage ?? 0}%`,
            note: `${formatNumber(data.subscription.renewingSoon)} renewals soon`,
            tone: 'emerald',
        },
        {
            label: 'Risk signal',
            value: `${likelyFake.percent ?? 0}%`,
            note: `${formatNumber(likelyFake.value)} likely fake`,
            tone: 'violet',
        },
    ];

    return (
        <section className="truthguard-admin-dashboard-hero rounded-[26px] p-4 sm:p-5">
            <div className="relative z-10 grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.42fr)]">
                <div className="flex min-w-0 flex-col justify-between gap-5">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div className="min-w-0">
                            <span className="truthguard-admin-live-badge">
                                <span className="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_14px_rgba(52,211,153,0.7)]" />
                                Live workspace
                            </span>
                            <h2 className="mt-4 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Admin dashboard</h2>
                            <p className="mt-2 max-w-3xl text-sm font-semibold leading-6 text-slate-500">
                                Verification activity, user growth, AI capacity, and moderation risk.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button onClick={onRefresh} className="truthguard-admin-action is-primary">
                                <RefreshIcon />
                                Refresh
                            </button>
                            <a href="/detections/create" className="truthguard-admin-action">
                                <ScanIcon />
                                Run Check
                            </a>
                            <a href={websiteUrl} className="truthguard-admin-action">
                                <ExternalIcon />
                                Website
                            </a>
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                        {snapshotTiles.map((tile) => (
                            <div key={tile.label} className={`truthguard-admin-dashboard-kpi is-${tile.tone} rounded-[20px] px-4 py-4`}>
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-[11px] font-black uppercase tracking-[0.14em] text-slate-400">{tile.label}</p>
                                        <p className="mt-2 text-2xl font-black text-slate-950">{tile.value}</p>
                                    </div>
                                    <span className="truthguard-admin-dashboard-kpi-icon">
                                        <TileIcon tone={tile.tone} />
                                    </span>
                                </div>
                                <p className="mt-3 text-xs font-bold text-slate-500">{tile.note}</p>
                            </div>
                        ))}
                    </div>
                </div>

                <aside className="truthguard-admin-dashboard-readiness rounded-[22px] p-4">
                    <div className="relative z-10">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">AI capacity</p>
                                <h3 className="mt-2 text-2xl font-black text-slate-950">{data.aiUsage.statusLabel ?? 'Unknown'}</h3>
                            </div>
                            <span className="rounded-full bg-slate-950 px-3 py-1 text-xs font-black text-white">{usagePercent}%</span>
                        </div>
                        <div className="mt-5 h-3 overflow-hidden rounded-full bg-slate-100">
                            <div className="h-full rounded-full bg-gradient-to-r from-blue-600 via-cyan-500 to-emerald-400" style={{ width: `${usagePercent}%` }} />
                        </div>
                        <div className="mt-5 grid gap-3">
                            <div className="flex items-center justify-between gap-3 text-sm">
                                <span className="font-bold text-slate-500">Budget</span>
                                <span className="font-black text-slate-900">{data.aiUsage.monthlyTokenBudgetLabel ?? 'Not set'}</span>
                            </div>
                            <div className="flex items-center justify-between gap-3 text-sm">
                                <span className="font-bold text-slate-500">Remaining</span>
                                <span className="font-black text-slate-900">{data.aiUsage.tokensRemainingLabel ?? 'Set budget'}</span>
                            </div>
                            <div className="flex items-center justify-between gap-3 text-sm">
                                <span className="font-bold text-slate-500">Model</span>
                                <span className="max-w-[11rem] truncate font-black text-slate-900">{data.aiUsage.model ?? 'Not set'}</span>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </section>
    );
}
