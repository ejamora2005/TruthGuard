import { verdictBadgeClass } from '../../lib/formatters';
import { Panel } from '../shared/Panel';
import { EmptyState } from '../shared/EmptyState';

export function DashboardPeopleSection({ latestUsers, topContributors, highRiskDetections }) {
    return (
        <div className="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <Panel title="Newest Accounts" description="Recently created accounts and their current plan state.">
                {latestUsers.length ? (
                    <div className="space-y-3">
                        {latestUsers.map((user) => (
                            <div key={user.id} className="truthguard-admin-person-row rounded-[18px] px-4 py-4">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-11 w-11 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-cyan-500 text-sm font-black text-white shadow-sm">{user.initial}</span>
                                    <div className="min-w-0">
                                        <p className="font-bold text-slate-950">{user.name}</p>
                                        <p className="truncate text-xs font-medium text-slate-500">{user.email}</p>
                                    </div>
                                </div>
                                <div className="mt-4 flex flex-wrap items-center gap-2">
                                    <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{user.subscriptionTier}</span>
                                    <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${user.emailVerified ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-700'}`}>
                                        {user.emailVerified ? 'Verified' : 'Unverified'}
                                    </span>
                                </div>
                                <p className="mt-3 text-xs font-medium text-slate-500">Joined {user.joinedLabel}</p>
                            </div>
                        ))}
                    </div>
                ) : (
                    <EmptyState label="No users found yet." />
                )}
            </Panel>

            <Panel title="Top Contributors" description="Users generating the most verification activity.">
                {topContributors.length ? (
                    <div className="space-y-3">
                        {topContributors.map((user) => (
                            <div key={user.id} className="truthguard-admin-person-row flex items-center justify-between gap-3 rounded-[18px] px-4 py-3">
                                <div className="flex min-w-0 items-center gap-3">
                                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-teal-500 text-sm font-black text-white">{user.initial}</span>
                                    <div className="min-w-0">
                                        <p className="text-sm font-bold text-slate-950">{user.name}</p>
                                        <p className="truncate text-xs font-medium text-slate-500">{user.email}</p>
                                    </div>
                                </div>
                                <span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-black text-blue-700">{user.detectionsCount}</span>
                            </div>
                        ))}
                    </div>
                ) : (
                    <EmptyState label="No contributor data yet." />
                )}
            </Panel>

            <Panel title="High Risk Scans" description="Scans with the highest fake probability scores.">
                {highRiskDetections.length ? (
                    <div className="space-y-3">
                        {highRiskDetections.map((item) => (
                            <div key={item.id} className="truthguard-admin-risk-row rounded-[18px] px-4 py-4">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="font-bold text-slate-950">{item.caseCode}</p>
                                        <p className="truncate text-xs font-medium text-slate-500">{item.sourceKind} - {item.mediaType}</p>
                                        <p className="mt-1 text-xs font-medium text-slate-400">{item.user?.name ?? 'Unknown user'}</p>
                                    </div>
                                    <div className="text-right">
                                        <span className="rounded-full bg-rose-50 px-2.5 py-1 text-xs font-black text-rose-600">{item.fakeScore}%</span>
                                        <span className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${verdictBadgeClass(item.verdictKey)}`}>
                                            {item.verdict}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <EmptyState label="No high-risk cases to show yet." />
                )}
            </Panel>
        </div>
    );
}
