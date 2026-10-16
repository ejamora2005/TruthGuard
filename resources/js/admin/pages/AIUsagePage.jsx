import { useAdminQuery } from '../hooks/useAdminQuery';
import { ApexChart } from '../components/shared/ApexChart';
import { Panel } from '../components/shared/Panel';
import { StatCard } from '../components/shared/StatCard';
import { LoadingState } from '../components/shared/LoadingState';
import { EmptyState } from '../components/shared/EmptyState';
import { PageHeader } from '../components/shared/PageHeader';
import { formatNumber } from '../lib/formatters';

const initialData = {
    summaryCards: [],
    monthlyUsage: { labels: [], values: [] },
    tokenUsage: {
        monthlyTokenBudget: 0,
        monthlyTokenBudgetLabel: 'Not set',
        tokensUsed: 0,
        tokensUsedLabel: '0',
        tokensRemaining: null,
        tokensRemainingLabel: 'Set budget',
        usagePercent: 0,
        estimatedTokensPerRequest: 0,
        estimatedInputTokensPerRequest: 0,
        estimatedOutputTokensPerRequest: 0,
        usageSourceLabel: 'Estimated from detection runs',
        hasTokenCapacity: null,
    },
    capacity: { status: 'unknown', label: 'Budget not set', description: '', warningThreshold: 80 },
    modelConfig: { provider: 'OpenAI', providerEnabled: false, model: 'gpt-5.4', timeout: 40, maxCompletionTokens: 1400 },
    forecast: { dailyAverageRequests: 0, projectedMonthlyRequests: 0, projectedMonthlyTokens: 0, projectedPercent: 0, projectedRemainingLabel: 'Set budget' },
    recommendations: [],
    overview: { estimatedRequests: 0, currentMonth: 0, averagePerSubscriber: 0, averageFakeScore: 0 },
    topContributors: [],
    sourceMix: [],
    mediaMix: [],
    usageNote: '',
    billing: { budgetLabel: '$6.00', usedLabel: 'Not synced', remainingLabel: 'Unknown', usagePercent: null, warningThreshold: 80, warningAmountLabel: '$4.80', status: 'unavailable', scopeLabel: '', setupRequired: false },
    requestUsage: { inputTokens: 0, outputTokens: 0, totalTokens: 0, webSearchCalls: 0, requests: 0, unmeasuredRequests: 0, trackedSince: null },
};

const capacityTone = {
    healthy: 'border-success-200 bg-success-50 text-success-600',
    warning: 'border-warning-200 bg-warning-50 text-warning-700',
    critical: 'border-error-200 bg-error-50 text-error-600',
    offline: 'border-error-200 bg-error-50 text-error-600',
    unknown: 'border-gray-200 bg-gray-50 text-gray-600',
};

const recommendationTone = {
    success: 'border-success-200 bg-success-50 text-success-700',
    warning: 'border-warning-200 bg-warning-50 text-warning-700',
    error: 'border-error-200 bg-error-50 text-error-600',
    brand: 'border-brand-200 bg-brand-50 text-brand-500',
};

export default function AIUsagePage() {
    const { data, loading, error, load } = useAdminQuery('/ai-usage', initialData);

    const monthlySeries = [{ name: 'Verification Runs', data: data.monthlyUsage.values }];
    const monthlyOptions = {
        chart: { type: 'bar' },
        colors: ['#465FFF'],
        plotOptions: { bar: { borderRadius: 8, columnWidth: '50%' } },
        dataLabels: { enabled: false },
        legend: { show: false },
        grid: { borderColor: '#F2F4F7' },
        xaxis: { categories: data.monthlyUsage.labels, axisBorder: { show: false }, axisTicks: { show: false } },
    };

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="TruthGuard AI"
                title="AI Usage"
                description="Monitor recorded API tokens, OpenAI-reported spending, and admin budget alerts."
                actions={
                    <button onClick={load} className="truthguard-admin-action">
                        Refresh
                    </button>
                }
            />

            {error ? <div className="rounded-2xl border border-error-200 bg-error-50 px-5 py-4 text-sm text-error-600">{error}</div> : null}
            {loading && !data.summaryCards.length ? <LoadingState label="Loading AI usage..." /> : null}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                {data.summaryCards.map((card) => (
                    <StatCard key={card.label} label={card.label} value={card.value} note={card.note} />
                ))}
            </div>

            <Panel title="Monthly API Budget" description={`${data.billing.budgetLabel} budget · ${data.billing.scopeLabel} · UTC calendar month`}>
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-3xl font-semibold text-gray-900">{data.billing.usedLabel} <span className="text-base font-normal text-gray-500">/ {data.billing.budgetLabel}</span></p>
                        <p className="mt-2 text-sm text-gray-600">Warning at {data.billing.warningAmountLabel} ({data.billing.warningThreshold}%). Limit alert at {data.billing.budgetLabel}.</p>
                    </div>
                    <span className={`rounded-full border px-3 py-1 text-sm font-semibold ${data.billing.status === 'synced' ? (Number(data.billing.usagePercent) >= 100 ? capacityTone.critical : Number(data.billing.usagePercent) >= data.billing.warningThreshold ? capacityTone.warning : capacityTone.healthy) : capacityTone.unknown}`}>
                        {data.billing.status === 'synced' ? (Number(data.billing.usagePercent) >= 100 ? 'Budget reached' : Number(data.billing.usagePercent) >= data.billing.warningThreshold ? 'Running low' : 'Billing synced') : data.billing.status === 'stale' ? 'Stale billing data' : 'Billing not connected'}
                    </span>
                </div>
                {data.billing.usagePercent !== null ? <div className="mt-5 h-3 overflow-hidden rounded-full bg-gray-100"><div className="h-full rounded-full bg-brand-500 transition-all" style={{ width: `${Math.min(data.billing.usagePercent, 100)}%` }} /></div> : null}
                {data.billing.setupRequired ? <p className="mt-4 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-700">Setup required: set OPENAI_ADMIN_API_KEY on the server. Optionally set OPENAI_USAGE_PROJECT_ID or OPENAI_USAGE_API_KEY_ID to monitor TruthGuard only. Run the Laravel scheduler for automatic billing checks every five minutes. Keep admin System notifications enabled; device push also requires push permission and a running queue worker.</p> : null}
                {data.billing.lastSyncError ? <p className="mt-3 text-sm text-error-600">The last billing check failed ({data.billing.lastSyncError}). Any amount shown is from the last successful sync.</p> : null}
                <p className="mt-4 text-sm leading-6 text-gray-500">{data.billing.note}</p>
                <p className="mt-2 text-xs text-gray-500">Last successful sync: {data.billing.lastSyncedAt ? `${data.billing.lastSyncedAt} UTC` : 'Never'}. Dashboard refresh reads saved usage without calling an AI model.</p>
            </Panel>

            <Panel title="Recorded Token Usage" description="Captured directly from each API response, independently of saved reports.">
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <InfoRow label="Input" value={formatNumber(data.requestUsage.inputTokens)} />
                    <InfoRow label="Output" value={formatNumber(data.requestUsage.outputTokens)} />
                    <InfoRow label="Total" value={formatNumber(data.requestUsage.totalTokens)} />
                    <InfoRow label="Web search calls" value={formatNumber(data.requestUsage.webSearchCalls)} />
                </div>
                <p className="mt-4 text-sm leading-6 text-gray-500">{data.usageNote}</p>
                <p className="mt-2 text-xs text-gray-500">First recorded response: {data.requestUsage.trackedSince ? `${data.requestUsage.trackedSince} UTC` : 'None yet'}. Reusing a saved result does not add an API request.</p>
            </Panel>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(320px,0.75fr)]">
                {data.tokenUsage.monthlyTokenBudget > 0 ? <Panel title="Token Capacity" description="A separate token budget; this does not represent dollar credit remaining.">
                    <div className="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <span className={`inline-flex rounded-full border px-3 py-1 text-xs font-semibold ${capacityTone[data.capacity.status] ?? capacityTone.unknown}`}>
                                {data.capacity.label}
                            </span>
                            <h3 className="mt-4 text-3xl font-semibold text-gray-900">{data.tokenUsage.tokensRemainingLabel}</h3>
                            <p className="mt-2 text-sm leading-6 text-gray-500">{data.capacity.description}</p>
                        </div>
                        <div className="grid min-w-[220px] gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <div className="flex items-center justify-between gap-3 text-sm">
                                <span className="text-gray-500">Budget</span>
                                <span className="font-semibold text-gray-900">{data.tokenUsage.monthlyTokenBudgetLabel}</span>
                            </div>
                            <div className="flex items-center justify-between gap-3 text-sm">
                                <span className="text-gray-500">Used</span>
                                <span className="font-semibold text-gray-900">{data.tokenUsage.tokensUsedLabel}</span>
                            </div>
                            <div className="flex items-center justify-between gap-3 text-sm">
                                <span className="text-gray-500">Source</span>
                                <span className="font-semibold text-gray-900">{data.tokenUsage.usageSourceLabel}</span>
                            </div>
                        </div>
                    </div>

                    <div className="mt-6">
                        <div className="h-4 overflow-hidden rounded-full bg-gray-100">
                            <div className="h-full rounded-full bg-brand-500 transition-all" style={{ width: `${Math.min(Number(data.tokenUsage.usagePercent ?? 0), 100)}%` }} />
                        </div>
                        <div className="mt-3 flex flex-wrap justify-between gap-2 text-xs font-medium text-gray-500">
                            <span>{data.tokenUsage.usagePercent}% of budget used</span>
                            <span>Warning at {data.capacity.warningThreshold}%</span>
                            <span>{data.tokenUsage.estimatedTokensPerRequest} estimated tokens/run</span>
                        </div>
                    </div>
                </Panel> : null}

                <Panel title="Model Settings" description="Current AI model configuration from environment settings.">
                    <div className="space-y-3">
                        <InfoRow label="Provider" value={data.modelConfig.provider} />
                        <InfoRow label="Status" value={data.modelConfig.providerEnabled ? 'Configured' : 'Missing API key'} />
                        <InfoRow label="Model" value={data.modelConfig.model} />
                        <InfoRow label="Max Output" value={`${data.modelConfig.maxCompletionTokens} tokens`} />
                        <InfoRow label="Timeout" value={`${data.modelConfig.timeout}s`} />
                    </div>
                </Panel>
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.1fr)_360px]">
                <Panel title="Monthly Usage" description="Estimated AI-assisted verification activity across the current year.">
                    <ApexChart type="bar" height={320} options={monthlyOptions} series={monthlySeries} />
                    <p className="mt-4 text-sm leading-6 text-gray-500">{data.usageNote}</p>
                </Panel>

                <div className="space-y-4">
                    <Panel title="Source Mix" description="Primary submission channels generating load.">
                        <div className="space-y-3">
                            {data.sourceMix.map((item) => (
                                <div key={item.label} className="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                                    <span className="text-sm font-medium text-gray-800">{item.label}</span>
                                    <span className="text-sm font-semibold text-gray-900">{item.value}</span>
                                </div>
                            ))}
                        </div>
                    </Panel>
                    <Panel title="Media Mix" description="Formats contributing to verification volume.">
                        <div className="space-y-3">
                            {data.mediaMix.map((item) => (
                                <div key={item.label} className="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                                    <span className="text-sm font-medium text-gray-800">{item.label}</span>
                                    <span className="text-sm font-semibold text-gray-900">{item.value}</span>
                                </div>
                            ))}
                        </div>
                    </Panel>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                <Panel title="Monthly Forecast" description="Projected load if the current request pace continues.">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <ForecastMetric label="Daily Avg" value={data.forecast.dailyAverageRequests} note="runs/day" />
                        <ForecastMetric label="Projected Runs" value={data.forecast.projectedMonthlyRequests} note="this month" />
                        <ForecastMetric label="Projected Tokens" value={data.forecast.projectedMonthlyTokens} note={`${data.forecast.projectedPercent}% of budget`} />
                    </div>
                    <div className="mt-4 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                        Projected remaining capacity: <span className="font-semibold text-gray-900">{data.forecast.projectedRemainingLabel}</span>
                    </div>
                </Panel>

                <Panel title="Admin Actions" description="What to check before defense or deployment.">
                    <div className="grid gap-3 md:grid-cols-2">
                        {data.recommendations.map((item) => (
                            <div key={item.title} className={`rounded-2xl border p-4 ${recommendationTone[item.tone] ?? recommendationTone.brand}`}>
                                <p className="font-semibold">{item.title}</p>
                                <p className="mt-2 text-sm leading-6 opacity-80">{item.detail}</p>
                            </div>
                        ))}
                    </div>
                </Panel>
            </div>

            <Panel title="Top Contributors" description="Accounts generating the highest verification activity right now.">
                {data.topContributors.length ? (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        {data.topContributors.map((user) => (
                            <div key={user.id} className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-500">{user.initial}</span>
                                    <div>
                                        <p className="font-medium text-gray-900">{user.name}</p>
                                        <p className="text-xs text-gray-500">{user.email}</p>
                                    </div>
                                </div>
                                <p className="mt-4 text-sm text-gray-500">{user.detectionsCount} detections recorded</p>
                            </div>
                        ))}
                    </div>
                ) : <EmptyState label="No usage contributors yet." />}
            </Panel>
        </div>
    );
}

function InfoRow({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
            <span className="text-sm text-gray-500">{label}</span>
            <span className="truncate text-right text-sm font-semibold text-gray-900">{value}</span>
        </div>
    );
}

function ForecastMetric({ label, value, note }) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-gray-50 p-4">
            <p className="text-xs uppercase tracking-[0.18em] text-gray-500">{label}</p>
            <p className="mt-2 text-2xl font-semibold text-gray-900">{formatNumber(value)}</p>
            <p className="mt-1 text-sm text-gray-500">{note}</p>
        </div>
    );
}
