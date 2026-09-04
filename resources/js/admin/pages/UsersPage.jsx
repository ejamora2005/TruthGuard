import { useAdminQuery } from '../hooks/useAdminQuery';
import { formatNumber } from '../lib/formatters';
import { Panel } from '../components/shared/Panel';
import { StatCard } from '../components/shared/StatCard';
import { LoadingState } from '../components/shared/LoadingState';
import { EmptyState } from '../components/shared/EmptyState';
import { PageHeader } from '../components/shared/PageHeader';

const initialData = { summaryCards: [], users: [] };

export default function UsersPage() {
    const { data, loading, error, load } = useAdminQuery('/users', initialData);

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="TruthGuard Users"
                title="User Accounts"
                description="Monitor growth, verification status, plan assignment, and user-level activity across the platform."
                actions={
                    <button onClick={load} className="truthguard-admin-action">
                        Refresh
                    </button>
                }
            />

            {error ? <div className="rounded-2xl border border-error-200 bg-error-50 px-5 py-4 text-sm text-error-600">{error}</div> : null}
            {loading && !data.summaryCards.length ? <LoadingState label="Loading users..." /> : null}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                {data.summaryCards.map((card) => (
                    <StatCard key={card.label} label={card.label} value={card.value} note={card.note} />
                ))}
            </div>

            <Panel title="User Directory" description="Latest user accounts, login activity, plan status, and detection counts.">
                {data.users.length ? (
                    <div className="overflow-x-auto">
                        <table className="truthguard-admin-table min-w-full">
                            <thead>
                                <tr className="border-b border-gray-100">
                                    <th className="px-2 py-3 text-left text-xs font-medium uppercase tracking-[0.18em] text-gray-500">User</th>
                                    <th className="px-2 py-3 text-left text-xs font-medium uppercase tracking-[0.18em] text-gray-500">Role</th>
                                    <th className="px-2 py-3 text-left text-xs font-medium uppercase tracking-[0.18em] text-gray-500">Subscription</th>
                                    <th className="px-2 py-3 text-left text-xs font-medium uppercase tracking-[0.18em] text-gray-500">Last Login</th>
                                    <th className="px-2 py-3 text-left text-xs font-medium uppercase tracking-[0.18em] text-gray-500">Detections</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.users.map((user) => (
                                    <tr key={user.id} className="border-b border-gray-100 align-top">
                                        <td className="px-2 py-4">
                                            <p className="font-medium text-gray-900">{user.name}</p>
                                            <p className="text-xs text-gray-500">{user.email}</p>
                                        </td>
                                        <td className="px-2 py-4 text-sm text-gray-700">{user.role}</td>
                                        <td className="px-2 py-4">
                                            <div className="space-y-1">
                                                <p className="text-sm font-medium text-gray-900">{user.subscriptionTier}</p>
                                                <p className="text-xs text-gray-500">{user.subscriptionStatus}</p>
                                            </div>
                                        </td>
                                        <td className="px-2 py-4 text-sm text-gray-500">{user.lastLoginLabel}</td>
                                        <td className="px-2 py-4 text-sm text-gray-900">{formatNumber(user.detectionsCount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState label="No users found yet." />
                )}
            </Panel>
        </div>
    );
}
