import { ApexChart } from '../shared/ApexChart';
import { Panel } from '../shared/Panel';
import { formatNumber } from '../../lib/formatters';

const capacityTone = {
    healthy: 'border-success-200 bg-success-50 text-success-600',
    warning: 'border-warning-200 bg-warning-50 text-warning-700',
    critical: 'border-error-200 bg-error-50 text-error-600',
    offline: 'border-error-200 bg-error-50 text-error-600',
    unknown: 'border-slate-200 bg-slate-50 text-slate-600',
};

export function DashboardUsageAndSubscription({ aiUsage, subscription }) {
    const usageSeries = [{ name: 'AI Usage', data: [aiUsage.currentMonth, aiUsage.tokensUsed ?? 0, aiUsage.tokensRemaining ?? 0, aiUsage.usagePercent ?? 0] }];
    const usageOptions = {
        chart: { type: 'bar' },
        colors: ['#2563EB'],
        plotOptions: { bar: { borderRadius: 8, columnWidth: '52%' } },
        dataLabels: { enabled: false },
        legend: { show: false },
        xaxis: {
            categories: ['Runs', 'Used Tokens', 'Remaining', 'Budget %'],
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: { labels: { show: true } },
        grid: { borderColor: '#E2E8F0' },
    };

    const subscriptionSeries = subscription.tiers.map((tier) => tier.value);
    const subscriptionOptions = {
        chart: { type: 'donut' },
        labels: subscription.tiers.map((tier) => tier.label),
        colors: ['#94A3B8', '#06B6D4', '#2563EB', '#10B981'],
        dataLabels: { enabled: true },
        legend: { show: false },
        stroke: { width: 0 },
        plotOptions: { pie: { donut: { size: '70%' } } },
    };

    return (
        <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
            <Panel title="AI Capacity" description="Token budget status estimated from verification activity.">
                <div className="truthguard-admin-capacity-card mb-5 rounded-[22px] p-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-400">Monthly Token Budget</p>
                            <p className="mt-2 text-2xl font-black text-slate-950">{aiUsage.monthlyTokenBudgetLabel ?? 'Not set'}</p>
                        </div>
                        <span className={`rounded-full border px-3 py-1 text-xs font-semibold ${capacityTone[aiUsage.status] ?? capacityTone.unknown}`}>
                            {aiUsage.statusLabel ?? 'Unknown'}
                        </span>
                    </div>
                    <div className="mt-4 h-3 overflow-hidden rounded-full bg-white">
                        <div className="h-full rounded-full bg-gradient-to-r from-blue-600 to-cyan-500 transition-all" style={{ width: `${Math.min(Number(aiUsage.usagePercent ?? 0), 100)}%` }} />
                    </div>
                    <div className="mt-3 flex flex-wrap justify-between gap-2 text-xs font-bold text-slate-500">
                        <span>{formatNumber(aiUsage.tokensUsed ?? 0)} tokens used</span>
                        <span>{aiUsage.tokensRemainingLabel ?? 'Set budget'} remaining</span>
                        <span>{aiUsage.usagePercent ?? 0}% used</span>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div className="truthguard-admin-insight-card rounded-[18px] px-4 py-4">
                        <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-400">This Month</p>
                        <p className="mt-2 text-xl font-black text-slate-950">{formatNumber(aiUsage.currentMonth)}</p>
                    </div>
                    <div className="truthguard-admin-insight-card rounded-[18px] px-4 py-4">
                        <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-400">Token / Run</p>
                        <p className="mt-2 text-xl font-black text-slate-950">{formatNumber(aiUsage.estimatedTokensPerRequest)}</p>
                    </div>
                    <div className="truthguard-admin-insight-card rounded-[18px] px-4 py-4">
                        <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-400">Avg / Subscriber</p>
                        <p className="mt-2 text-xl font-black text-slate-950">{formatNumber(aiUsage.averagePerSubscriber)}</p>
                    </div>
                    <div className="truthguard-admin-insight-card rounded-[18px] px-4 py-4">
                        <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-400">Model</p>
                        <p className="mt-2 truncate text-xl font-black text-slate-950">{aiUsage.model ?? 'Not set'}</p>
                    </div>
                </div>
                <div className="truthguard-admin-chart-frame mt-6 rounded-[22px] p-4">
                    <ApexChart type="bar" height={280} options={usageOptions} series={usageSeries} />
                </div>
                <p className="mt-4 text-sm font-medium leading-6 text-slate-500">{aiUsage.note}</p>
            </Panel>

            <Panel title="Subscription Health" description="Coverage across the regular user base.">
                <div className="truthguard-admin-subscription-summary flex flex-wrap items-center justify-between gap-4 rounded-[22px] px-4 py-4">
                    <div>
                        <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-400">Coverage</p>
                        <p className="mt-2 text-2xl font-black text-slate-950">{subscription.coverage}%</p>
                    </div>
                    <div className="truthguard-admin-coverage-ring" style={{ '--progress': `${Math.min(Number(subscription.coverage ?? 0), 100)}%` }}>
                        <span>{subscription.coverage}%</span>
                    </div>
                    <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-600">{subscription.renewingSoon} renewing soon</span>
                </div>
                <div className="mt-4">
                    <ApexChart type="donut" height={300} options={subscriptionOptions} series={subscriptionSeries} />
                </div>
                <div className="mt-4 space-y-3">
                    {subscription.tiers.map((tier) => (
                        <div key={tier.label} className="truthguard-admin-soft-card flex items-center justify-between rounded-[18px] px-4 py-3">
                            <span className="text-sm font-bold text-slate-700">{tier.label}</span>
                            <span className="text-sm font-black text-slate-950">{formatNumber(tier.value)}</span>
                        </div>
                    ))}
                </div>
            </Panel>
        </div>
    );
}
