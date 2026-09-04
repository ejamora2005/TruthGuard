import { useAdminQuery } from '../hooks/useAdminQuery';
import { Panel } from '../components/shared/Panel';
import { StatCard } from '../components/shared/StatCard';
import { LoadingState } from '../components/shared/LoadingState';
import { EmptyState } from '../components/shared/EmptyState';
import { PageHeader } from '../components/shared/PageHeader';

const initialData = { summaryCards: [], tierDistribution: [], accounts: [] };

export default function SubscriptionsPage() {
    const { data, loading, error, load } = useAdminQuery('/subscriptions', initialData);
    const maxTierValue = Math.max(...data.tierDistribution.map((tier) => Number(tier.value || 0)), 1);

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="TruthGuard Revenue"
                title="Subscriptions"
                description="Track plan distribution, subscriber coverage, and accounts approaching renewal across the user base."
                actions={
                    <button onClick={load} className="truthguard-admin-action">
                        Refresh
                    </button>
                }
            />

            {error ? <div className="rounded-2xl border border-error-200 bg-error-50 px-5 py-4 text-sm text-error-600">{error}</div> : null}
            {loading && !data.summaryCards.length ? <LoadingState label="Loading subscriptions..." /> : null}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                {data.summaryCards.map((card) => (
                    <StatCard key={card.label} label={card.label} value={card.value} note={card.note} />
                ))}
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
                <Panel title="Tier Distribution" description="Breakdown of current regular-user subscription levels.">
                    <div className="space-y-4">
                        {data.tierDistribution.map((tier) => (
                            <div key={tier.label} className="truthguard-admin-soft-card rounded-[18px] px-4 py-3">
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-sm font-medium text-gray-800">{tier.label}</span>
                                    <span className="text-sm font-semibold text-gray-900">{tier.value}</span>
                                </div>
                                <div className="mt-3 h-2 rounded-full bg-gray-200">
                                    <div
                                        className="h-2 rounded-full bg-brand-500"
                                        style={{ width: `${Math.max(10, Math.round((Number(tier.value || 0) / maxTierValue) * 100))}%` }}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                </Panel>

                <Panel title="Subscription Accounts" description="Accounts ordered from highest-value tiers down to free plans.">
                    {data.accounts.length ? (
                        <div className="overflow-x-auto">
                        <table className="truthguard-admin-table min-w-full">
                                <thead>
                                    <tr className="border-b border-gray-100">
                                        <th className="px-2 py-3 text-left text-xs font-medium uppercase tracking-[0.18em] text-gray-500">Account</th>
                                        <th className="px-2 py-3 text-left text-xs font-medium uppercase tracking-[0.18em] text-gray-500">Tier</th>
                                        <th className="px-2 py-3 text-left text-xs font-medium uppercase tracking-[0.18em] text-gray-500">Status</th>
                                        <th className="px-2 py-3 text-left text-xs font-medium uppercase tracking-[0.18em] text-gray-500">Renewal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.accounts.map((user) => (
                                        <tr key={user.id} className="border-b border-gray-100">
                                            <td className="px-2 py-4">
                                                <p className="font-medium text-gray-900">{user.name}</p>
                                                <p className="text-xs text-gray-500">{user.email}</p>
                                            </td>
                                            <td className="px-2 py-4 text-sm text-gray-900">{user.subscriptionTier}</td>
                                            <td className="px-2 py-4 text-sm text-gray-500">{user.subscriptionStatus}</td>
                                            <td className="px-2 py-4 text-sm text-gray-500">{user.subscriptionRenewsLabel}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <EmptyState label="No subscription accounts yet." />
                    )}
                </Panel>
            </div>
        </div>
    );
}
