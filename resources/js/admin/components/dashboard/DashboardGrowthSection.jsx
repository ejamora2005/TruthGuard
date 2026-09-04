import { ApexChart } from '../shared/ApexChart';
import { Panel } from '../shared/Panel';
import { formatNumber } from '../../lib/formatters';

function sum(values = []) {
    return values.reduce((total, value) => total + Number(value ?? 0), 0);
}

export function DashboardGrowthSection({ monthlyTrend }) {
    const totalDetections = sum(monthlyTrend.detections);
    const totalSignups = sum(monthlyTrend.signups);
    const lastDetectionCount = Number(monthlyTrend.detections?.[monthlyTrend.detections.length - 1] ?? 0);
    const lastSignupCount = Number(monthlyTrend.signups?.[monthlyTrend.signups.length - 1] ?? 0);
    const series = [
        { name: 'Detections', data: monthlyTrend.detections },
        { name: 'Signups', data: monthlyTrend.signups },
    ];

    const options = {
        chart: { type: 'area' },
        colors: ['#2563EB', '#14B8A6'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: [3, 3] },
        fill: {
            type: 'gradient',
            gradient: { opacityFrom: 0.35, opacityTo: 0.04 },
        },
        legend: { position: 'top', horizontalAlign: 'left' },
        grid: { borderColor: '#E2E8F0' },
        tooltip: { theme: 'light' },
        xaxis: {
            categories: monthlyTrend.labels,
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
    };

    return (
        <Panel title="Activity Intelligence" description="Detection and signup movement across the latest months." className="h-full">
            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(190px,0.32fr)]">
                <div className="truthguard-admin-chart-frame rounded-[22px] px-2 py-4 sm:px-4">
                    <ApexChart type="area" height={322} options={options} series={series} />
                </div>
                <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                    <div className="truthguard-admin-insight-card rounded-[18px] p-4">
                        <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">Detections</p>
                        <p className="mt-2 text-2xl font-black text-slate-950">{formatNumber(totalDetections)}</p>
                        <p className="mt-1 text-xs font-bold text-slate-500">trend total</p>
                    </div>
                    <div className="truthguard-admin-insight-card rounded-[18px] p-4">
                        <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">Signups</p>
                        <p className="mt-2 text-2xl font-black text-slate-950">{formatNumber(totalSignups)}</p>
                        <p className="mt-1 text-xs font-bold text-slate-500">trend total</p>
                    </div>
                    <div className="truthguard-admin-insight-card rounded-[18px] p-4">
                        <p className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">Latest Month</p>
                        <p className="mt-2 text-2xl font-black text-slate-950">{formatNumber(lastDetectionCount)}</p>
                        <p className="mt-1 text-xs font-bold text-slate-500">{formatNumber(lastSignupCount)} signups</p>
                    </div>
                </div>
            </div>
        </Panel>
    );
}
