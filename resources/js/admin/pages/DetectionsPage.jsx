import { useAdminQuery } from '../hooks/useAdminQuery';
import { formatNumber, verdictBadgeClass } from '../lib/formatters';
import { ApexChart } from '../components/shared/ApexChart';
import { Panel } from '../components/shared/Panel';
import { StatCard } from '../components/shared/StatCard';
import { LoadingState } from '../components/shared/LoadingState';
import { EmptyState } from '../components/shared/EmptyState';
import { PageHeader } from '../components/shared/PageHeader';

const initialData = {
    summaryCards: [],
    monthlyVerdicts: { labels: [], fake: [], review: [], real: [] },
    mediaMix: [],
    recentDetections: [],
    highRiskDetections: [],
};

export default function DetectionsPage() {
    const { data, loading, error, load } = useAdminQuery('/detections', initialData);

    const chartSeries = [
        { name: 'Likely Fake', data: data.monthlyVerdicts.fake },
        { name: 'Needs Review', data: data.monthlyVerdicts.review },
        { name: 'Likely Real', data: data.monthlyVerdicts.real },
    ];

    const chartOptions = {
        chart: { type: 'area' },
        colors: ['#F04438', '#F79009', '#12B76A'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: [3, 3, 3] },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.28, opacityTo: 0.03 } },
        legend: { position: 'top', horizontalAlign: 'left' },
        grid: { borderColor: '#F2F4F7' },
        xaxis: { categories: data.monthlyVerdicts.labels, axisBorder: { show: false }, axisTicks: { show: false } },
    };

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="TruthGuard Moderation"
                title="Detection Queue"
                description="Review verdict trends, media mix, and the highest-risk detections moving through the system."
                actions={
                    <button onClick={load} className="truthguard-admin-action">
                        Refresh
                    </button>
                }
            />

            {error ? <div className="rounded-2xl border border-error-200 bg-error-50 px-5 py-4 text-sm text-error-600">{error}</div> : null}
            {loading && !data.summaryCards.length ? <LoadingState label="Loading detections..." /> : null}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                {data.summaryCards.map((card) => (
                    <StatCard key={card.label} label={card.label} value={card.value} note={card.note} />
                ))}
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.2fr)_340px]">
                <Panel title="Verdict Trend" description="Monthly breakdown of fake, review, and likely real outputs.">
                    <ApexChart type="area" height={320} options={chartOptions} series={chartSeries} />
                </Panel>

                <Panel title="Media Mix" description="Formats and submission sources currently flowing through the queue.">
                    <div className="space-y-3">
                        {data.mediaMix.map((item) => (
                            <div key={item.label} className="truthguard-admin-soft-card flex items-center justify-between rounded-[18px] px-4 py-3">
                                <span className="text-sm font-bold text-slate-700">{item.label}</span>
                                <span className="text-sm font-black text-slate-950">{formatNumber(item.value)}</span>
                            </div>
                        ))}
                    </div>
                </Panel>
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Panel title="Recent Detections" description="Latest detection records entering the platform.">
                    {data.recentDetections.length ? (
                        <div className="space-y-3">
                            {data.recentDetections.map((item) => (
                                <div key={item.id} className="truthguard-admin-soft-card rounded-[18px] px-4 py-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="font-bold text-slate-950">{item.caseCode}</p>
                                            <p className="text-xs font-medium text-slate-500">{item.user?.name ?? 'Unknown user'} | {item.sourceKind}</p>
                                            <p className="mt-1 text-xs font-medium text-slate-400">{item.analyzedLabel}</p>
                                        </div>
                                        <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${verdictBadgeClass(item.verdictKey)}`}>{item.verdict}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : <EmptyState label="No detections yet." />}
                </Panel>

                <Panel title="High Risk Scans" description="Scans with the strongest fake-confidence signals.">
                    {data.highRiskDetections.length ? (
                        <div className="space-y-3">
                            {data.highRiskDetections.map((item) => (
                                <div key={item.id} className="truthguard-admin-soft-card rounded-[18px] px-4 py-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="font-bold text-slate-950">{item.caseCode}</p>
                                            <p className="text-xs font-medium text-slate-500">{item.mediaType} | {item.sourceKind}</p>
                                            <p className="mt-1 text-xs font-medium text-slate-400">{item.user?.name ?? 'Unknown user'}</p>
                                        </div>
                                        <div className="text-right">
                                            <span className="rounded-full bg-rose-50 px-2.5 py-1 text-xs font-black text-rose-600">{item.fakeScore}%</span>
                                            <span className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${verdictBadgeClass(item.verdictKey)}`}>{item.verdict}</span>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : <EmptyState label="No high-risk detections found." />}
                </Panel>
            </div>
        </div>
    );
}
