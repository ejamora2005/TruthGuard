import { useAdminQuery } from '../hooks/useAdminQuery';
import { LoadingState } from '../components/shared/LoadingState';
import { PageHeader } from '../components/shared/PageHeader';
import { Panel } from '../components/shared/Panel';

const initialData = {
    overview: {
        completionPercent: 0,
        totalCapabilities: 0,
        addedCount: 0,
        partialCount: 0,
        missingCount: 0,
        currentFocus: '',
        summary: '',
    },
    summaryCards: [],
    latestUpdates: [],
    capabilitySections: [],
    focusItems: [],
    nextActions: [],
    sprints: [],
};

const capabilityStatusMap = {
    added: {
        label: 'Added',
        badge: 'truthguard-admin-status-pill is-added',
        dot: 'bg-emerald-500',
    },
    partial: {
        label: 'Partial',
        badge: 'truthguard-admin-status-pill is-partial',
        dot: 'bg-amber-500',
    },
    missing: {
        label: 'Missing',
        badge: 'truthguard-admin-status-pill is-missing',
        dot: 'bg-rose-500',
    },
};

const sprintStatusMap = {
    done: {
        label: 'Complete',
        badge: 'truthguard-admin-status-pill is-added',
    },
    in_progress: {
        label: 'In Progress',
        badge: 'truthguard-admin-status-pill is-active',
    },
    next: {
        label: 'Next',
        badge: 'truthguard-admin-status-pill is-neutral',
    },
    missing: {
        label: 'Pending',
        badge: 'truthguard-admin-status-pill is-missing',
    },
};

const updateToneMap = {
    success: 'is-success',
    brand: 'is-brand',
    warning: 'is-warning',
};

const taskMarkerMap = {
    done: 'OK',
    in_progress: 'IN',
    next: 'NX',
    missing: '--',
};

function clampPercent(value) {
    return Math.max(0, Math.min(Number(value || 0), 100));
}

function StatusBadge({ status, type = 'capability' }) {
    const map = type === 'sprint' ? sprintStatusMap : capabilityStatusMap;
    const config = map[status] ?? {
        label: status,
        badge: 'truthguard-admin-status-pill is-neutral',
    };

    return <span className={config.badge}>{config.label}</span>;
}

function SummaryCard({ card }) {
    return (
        <article className="truthguard-admin-tracker-card rounded-[20px] p-5">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-400">{card.label}</p>
                    <p className="mt-3 text-3xl font-black tracking-tight text-slate-950">{card.value}</p>
                </div>
                <span className="h-10 w-10 rounded-2xl bg-gradient-to-br from-blue-500 to-cyan-400 shadow-[0_14px_28px_rgba(37,99,235,0.18)]" />
            </div>
            <p className="mt-3 text-sm font-medium leading-6 text-slate-500">{card.note}</p>
        </article>
    );
}

function TrackerHero({ overview }) {
    const progress = clampPercent(overview.completionPercent);
    const openItems = Number(overview.partialCount || 0) + Number(overview.missingCount || 0);

    return (
        <section className="truthguard-admin-tracker-hero rounded-[28px] p-5 sm:p-7">
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_360px] xl:items-center">
                <div>
                    <span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-black uppercase tracking-[0.18em] text-cyan-50">
                        <span className="h-2 w-2 rounded-full bg-emerald-300 shadow-[0_0_16px_rgba(110,231,183,0.72)]" />
                        Delivery command board
                    </span>
                    <h2 className="mt-4 max-w-4xl text-3xl font-black tracking-tight text-white sm:text-4xl lg:text-[2.55rem] lg:leading-tight">
                        Track the build from pipeline foundation to production readiness.
                    </h2>
                    <p className="mt-3 max-w-3xl text-sm font-medium leading-7 text-blue-50/78 sm:text-base">
                        {overview.summary}
                    </p>

                    <div className="mt-6 grid gap-3 sm:grid-cols-3">
                        <div className="truthguard-admin-mini-card rounded-[18px] p-4">
                            <p className="text-xs font-black uppercase tracking-[0.18em] text-cyan-100">Added</p>
                            <p className="mt-2 text-2xl font-black text-white">{overview.addedCount}</p>
                        </div>
                        <div className="truthguard-admin-mini-card rounded-[18px] p-4">
                            <p className="text-xs font-black uppercase tracking-[0.18em] text-cyan-100">Partial</p>
                            <p className="mt-2 text-2xl font-black text-white">{overview.partialCount}</p>
                        </div>
                        <div className="truthguard-admin-mini-card rounded-[18px] p-4">
                            <p className="text-xs font-black uppercase tracking-[0.18em] text-cyan-100">Missing</p>
                            <p className="mt-2 text-2xl font-black text-white">{overview.missingCount}</p>
                        </div>
                    </div>
                </div>

                <div className="truthguard-admin-mini-card rounded-[24px] p-5">
                    <div className="flex items-center justify-between gap-4">
                        <div
                            className="truthguard-admin-progress-ring"
                            style={{ '--progress': `${progress}%` }}
                            aria-label={`${progress}% complete`}
                        >
                            <span>{progress}%</span>
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="text-xs font-black uppercase tracking-[0.18em] text-cyan-100">Current Focus</p>
                            <p className="mt-2 text-sm font-semibold leading-6 text-white">{overview.currentFocus}</p>
                        </div>
                    </div>

                    <div className="mt-5 grid grid-cols-2 gap-3">
                        <div className="rounded-2xl border border-white/12 bg-white/10 px-4 py-3">
                            <p className="text-xs font-bold text-cyan-100">Tracked</p>
                            <p className="mt-1 text-xl font-black text-white">{overview.totalCapabilities}</p>
                        </div>
                        <div className="rounded-2xl border border-white/12 bg-white/10 px-4 py-3">
                            <p className="text-xs font-bold text-cyan-100">Open Items</p>
                            <p className="mt-1 text-xl font-black text-white">{openItems}</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

export default function ProjectTrackerPage() {
    const { data, loading, error, load } = useAdminQuery('/project-tracker', initialData);

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="TruthGuard Delivery"
                title="Project Tracker"
                description="Monitor implemented work, incomplete capabilities, and sprint priorities in clear English for admin review."
                actions={
                    <button onClick={load} className="truthguard-admin-action">
                        Refresh
                    </button>
                }
            />

            {error ? (
                <div className="truthguard-admin-alert rounded-[18px] px-5 py-4 text-sm font-semibold">{error}</div>
            ) : null}

            {loading && !data.summaryCards.length ? <LoadingState label="Loading project tracker..." /> : null}

            <TrackerHero overview={data.overview} />

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                {data.summaryCards.map((card) => (
                    <SummaryCard key={card.label} card={card} />
                ))}
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.05fr_1fr_1fr]">
                <Panel title="Latest Updates" description="Recent implementation changes already reflected in the codebase.">
                    <div className="space-y-3">
                        {data.latestUpdates.map((update) => (
                            <article key={update.title} className={`truthguard-admin-update-card rounded-[18px] p-4 ${updateToneMap[update.tone] ?? 'is-brand'}`}>
                                <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">Update</p>
                                <h3 className="mt-2 text-sm font-black text-slate-950">{update.title}</h3>
                                <p className="mt-2 text-sm font-medium leading-6 text-slate-500">{update.detail}</p>
                            </article>
                        ))}
                    </div>
                </Panel>

                <Panel title="Missing / Needs Attention" description="Incomplete pieces that should stay visible until they are finished.">
                    <div className="space-y-3">
                        {data.focusItems.map((item) => (
                            <article key={item.title} className="truthguard-admin-focus-card rounded-[18px] p-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge status={item.status} />
                                    <h3 className="text-sm font-black text-slate-950">{item.title}</h3>
                                </div>
                                <p className="mt-2 text-sm font-medium leading-6 text-slate-500">{item.summary}</p>
                                <div className="mt-3 rounded-2xl bg-white/80 px-3 py-3">
                                    <p className="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">Proof</p>
                                    <p className="mt-1 text-sm font-medium leading-6 text-slate-500">{item.proof}</p>
                                </div>
                            </article>
                        ))}
                    </div>
                </Panel>

                <Panel title="Immediate Next Actions" description="A no-skip checklist for the next working sessions.">
                    <ol className="space-y-3">
                        {data.nextActions.map((action, index) => (
                            <li key={action} className="truthguard-admin-action-row rounded-[18px] p-4">
                                <span className="inline-flex h-8 w-8 flex-none items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 to-cyan-500 text-xs font-black text-white shadow-[0_12px_24px_rgba(37,99,235,0.18)]">
                                    {index + 1}
                                </span>
                                <p className="text-sm font-semibold leading-6 text-slate-700">{action}</p>
                            </li>
                        ))}
                    </ol>
                </Panel>
            </div>

            <div className="space-y-6">
                {data.capabilitySections.map((section) => (
                    <Panel key={section.title} title={section.title} description={section.description}>
                        <div className="grid gap-4 lg:grid-cols-2">
                            {section.items.map((item) => {
                                const statusConfig = capabilityStatusMap[item.status] ?? capabilityStatusMap.partial;

                                return (
                                    <article key={item.title} className="truthguard-admin-capability-card rounded-[18px] p-4">
                                        <div className="flex flex-wrap items-center gap-3">
                                            <span className={`h-2.5 w-2.5 rounded-full ${statusConfig.dot}`} />
                                            <h3 className="min-w-0 flex-1 text-sm font-black text-slate-950">{item.title}</h3>
                                            <StatusBadge status={item.status} />
                                        </div>
                                        <p className="mt-3 text-sm font-medium leading-6 text-slate-500">{item.summary}</p>
                                        <div className="mt-3 rounded-2xl bg-white/82 px-3 py-3">
                                            <p className="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">Current Proof</p>
                                            <p className="mt-1 text-sm font-medium leading-6 text-slate-500">{item.proof}</p>
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                    </Panel>
                ))}
            </div>

            <Panel title="Sprint Board" description="Follow these sprints in order so implementation stays organized and no major task is skipped.">
                <div className="grid gap-4 xl:grid-cols-2">
                    {data.sprints.map((sprint) => (
                        <article key={sprint.name} className="truthguard-admin-sprint-card rounded-[20px] p-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="text-xs font-black uppercase tracking-[0.18em] text-blue-600">{sprint.name}</p>
                                    <h3 className="mt-2 text-lg font-black text-slate-950">{sprint.title}</h3>
                                </div>
                                <StatusBadge status={sprint.status} type="sprint" />
                            </div>

                            <p className="mt-3 text-sm font-medium leading-6 text-slate-500">{sprint.goal}</p>

                            <div className="mt-4 space-y-3">
                                {sprint.tasks.map((task) => (
                                    <div key={`${sprint.name}-${task.label}`} className="truthguard-admin-task-row rounded-[16px] px-4 py-3">
                                        <span
                                            className={`truthguard-admin-task-marker ${
                                                task.status === 'done'
                                                    ? 'is-done'
                                                    : task.status === 'in_progress'
                                                      ? 'is-active'
                                                      : 'is-pending'
                                            }`}
                                        >
                                            {taskMarkerMap[task.status] ?? '--'}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-bold leading-6 text-slate-800">{task.label}</p>
                                            <div className="mt-2">
                                                <StatusBadge status={task.status} type="sprint" />
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </article>
                    ))}
                </div>
            </Panel>
        </div>
    );
}
