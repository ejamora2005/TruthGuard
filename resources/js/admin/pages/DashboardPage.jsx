import { getAdminConfig } from '../lib/admin-config';
import { useAdminQuery } from '../hooks/useAdminQuery';
import { DashboardGrowthSection } from '../components/dashboard/DashboardGrowthSection';
import { DashboardHero } from '../components/dashboard/DashboardHero';
import { DashboardMetricGrid } from '../components/dashboard/DashboardMetricGrid';
import { DashboardModerationSection, DashboardQueueSection } from '../components/dashboard/DashboardModerationSection';
import { DashboardPeopleSection } from '../components/dashboard/DashboardPeopleSection';
import { DashboardUsageAndSubscription } from '../components/dashboard/DashboardUsageAndSubscription';
import { LoadingState } from '../components/shared/LoadingState';

const initialData = {
    overviewMetrics: [],
    monthlyTrend: { labels: [], detections: [], signups: [] },
    moderation: { items: [], sources: [] },
    subscription: { coverage: 0, renewingSoon: 0, tiers: [] },
    aiUsage: {
        estimatedRequests: 0,
        currentMonth: 0,
        averagePerSubscriber: 0,
        averageFakeScore: 0,
        note: '',
        monthlyTokenBudget: 0,
        monthlyTokenBudgetLabel: 'Not set',
        tokensUsed: 0,
        tokensUsedLabel: '0',
        tokensRemaining: null,
        tokensRemainingLabel: 'Set budget',
        usagePercent: 0,
        estimatedTokensPerRequest: 0,
        usageSourceLabel: 'Estimated from detection runs',
        hasTokenCapacity: null,
        model: 'gpt-5.4',
        providerEnabled: false,
        status: 'unknown',
        statusLabel: 'Budget not set',
    },
    alerts: [],
    recentDetections: [],
    highRiskDetections: [],
    latestUsers: [],
    topContributors: [],
};

export default function DashboardPage() {
    const { websiteUrl } = getAdminConfig();
    const { data, loading, error, load } = useAdminQuery('/dashboard', initialData);

    return (
        <div className="truthguard-admin-dashboard space-y-5">
            <DashboardHero data={data} onRefresh={load} websiteUrl={websiteUrl} />

            {error ? (
                <div className="rounded-2xl border border-error-200 bg-error-50 px-5 py-4 text-sm text-error-600">{error}</div>
            ) : null}

            {loading && !data.overviewMetrics.length ? <LoadingState label="Loading admin dashboard..." /> : null}

            <DashboardMetricGrid metrics={data.overviewMetrics} />
            <div className="grid gap-5 2xl:grid-cols-[minmax(0,1.05fr)_minmax(360px,0.95fr)]">
                <DashboardGrowthSection monthlyTrend={data.monthlyTrend} />
                <DashboardModerationSection moderation={data.moderation} alerts={data.alerts} />
            </div>
            <DashboardUsageAndSubscription aiUsage={data.aiUsage} subscription={data.subscription} />
            <DashboardQueueSection detections={data.recentDetections} loading={loading} />
            <DashboardPeopleSection
                latestUsers={data.latestUsers}
                topContributors={data.topContributors}
                highRiskDetections={data.highRiskDetections}
            />
        </div>
    );
}
