import { alertToneClass, verdictBadgeClass } from '../../lib/formatters';
import { Panel } from '../shared/Panel';
import { EmptyState } from '../shared/EmptyState';

export function DashboardModerationSection({ moderation, alerts }) {
    return (
        <div className="grid h-full grid-cols-1 gap-4">
            <Panel title="Moderation Board" description="Verdict mix, source load, and queue signals." className="h-full">
                <div className="space-y-5">
                    {moderation.items.map((item) => (
                        <div key={item.label}>
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-sm font-black text-slate-900">{item.label}</p>
                                    <p className="text-xs font-medium text-slate-500">{item.value} cases</p>
                                </div>
                                <span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700">{item.percent}%</span>
                            </div>
                            <div className="mt-3 h-2 rounded-full bg-slate-100">
                                <div className="h-2 rounded-full bg-gradient-to-r from-blue-600 to-cyan-500" style={{ width: `${Math.max(item.percent, item.value > 0 ? 8 : 0)}%` }} />
                            </div>
                        </div>
                    ))}
                </div>
                <div className="mt-6 grid grid-cols-2 gap-3">
                    {moderation.sources.map((item) => (
                        <div key={item.label} className="truthguard-admin-source-pill rounded-[18px] px-4 py-4">
                            <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">{item.label}</p>
                            <p className="mt-2 text-xl font-black text-slate-950">{item.value}</p>
                        </div>
                    ))}
                </div>

                <div className="mt-6">
                    <p className="mb-3 text-xs font-black uppercase tracking-[0.16em] text-slate-400">Alerts</p>
                    {alerts.length ? (
                        <div className="grid gap-3">
                            {alerts.map((alert) => (
                                <div key={alert.title} className={`truthguard-admin-alert-row rounded-[18px] border px-4 py-3 ${alertToneClass(alert.tone)}`}>
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-black">{alert.title}</p>
                                            <p className="mt-1 text-xs font-semibold opacity-80">{alert.description}</p>
                                        </div>
                                        <span className="text-2xl font-black">{alert.value}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <EmptyState label="No alert conditions right now." />
                    )}
                </div>
            </Panel>
        </div>
    );
}

export function DashboardQueueSection({ detections, loading }) {
    return (
        <Panel title="Recent Detection Queue" description="Latest submissions moving through the moderation pipeline.">
            {loading ? (
                <LoadingQueueRows />
            ) : detections.length ? (
                <div className="truthguard-admin-queue-stack grid gap-3">
                    {detections.map((item) => (
                        <article key={item.id} className="truthguard-admin-queue-item rounded-[20px] px-4 py-4">
                            <div className="grid gap-4 lg:grid-cols-[minmax(180px,0.85fr)_minmax(220px,1fr)_minmax(150px,0.55fr)_minmax(170px,0.6fr)_auto] lg:items-center">
                                <div>
                                    <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">Scan</p>
                                    <p className="mt-1 font-black text-slate-950">{item.caseCode}</p>
                                    <p className="mt-1 text-xs font-semibold text-slate-500">{item.mediaType} - {item.analyzedLabel}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">User</p>
                                    <p className="mt-1 text-sm font-black text-slate-900">{item.user?.name ?? 'Unknown user'}</p>
                                    <p className="mt-1 truncate text-xs font-semibold text-slate-500">{item.user?.email ?? 'No email'}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">Source</p>
                                    <span className="mt-2 inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-black text-slate-700">{item.sourceKind}</span>
                                </div>
                                <div>
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">Score</p>
                                        <p className="text-sm font-black text-slate-950">{item.fakeScore}%</p>
                                    </div>
                                    <div className="truthguard-admin-score-meter mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <span style={{ width: `${Math.min(Number(item.fakeScore ?? 0), 100)}%` }} />
                                    </div>
                                </div>
                                <div className="lg:text-right">
                                    <span className={`inline-flex rounded-full px-3 py-1.5 text-xs font-black ${verdictBadgeClass(item.verdictKey)}`}>
                                        {item.verdict}
                                    </span>
                                </div>
                            </div>
                        </article>
                    ))}
                </div>
            ) : (
                <EmptyState label="No detections yet." />
            )}
        </Panel>
    );
}

function LoadingQueueRows() {
    return (
        <div className="grid gap-3">
            {[1, 2, 3].map((item) => (
                <div key={item} className="truthguard-admin-queue-item rounded-[20px] px-4 py-4">
                    <div className="h-4 w-32 rounded-full bg-slate-200/80" />
                    <div className="mt-3 h-3 w-full max-w-xl rounded-full bg-slate-100" />
                </div>
            ))}
        </div>
    );
}
