import ApexCharts from 'apexcharts';

const chartRegistry = new Map();

const parseJson = (value, fallback = []) => {
    if (!value) {
        return fallback;
    }

    try {
        return JSON.parse(value);
    } catch {
        return fallback;
    }
};

const destroyChart = (key) => {
    const chart = chartRegistry.get(key);

    if (chart) {
        chart.destroy();
        chartRegistry.delete(key);
    }
};

const renderUsageBarChart = () => {
    const el = document.querySelector('[data-admin-usage-chart]');

    if (!el) {
        destroyChart('usage');
        return;
    }

    destroyChart('usage');

    const categories = parseJson(el.dataset.categories);
    const values = parseJson(el.dataset.series).map((value) => Number(value));

    const chart = new ApexCharts(el, {
        chart: {
            type: 'bar',
            height: 280,
            toolbar: {
                show: false,
            },
            fontFamily: 'Outfit, sans-serif',
        },
        series: [
            {
                name: 'Detections',
                data: values,
            },
        ],
        colors: ['#465FFF'],
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '42%',
                borderRadius: 6,
                borderRadiusApplication: 'end',
            },
        },
        dataLabels: {
            enabled: false,
        },
        stroke: {
            show: true,
            width: 4,
            colors: ['transparent'],
        },
        legend: {
            show: false,
        },
        grid: {
            borderColor: '#F2F4F7',
            yaxis: {
                lines: {
                    show: true,
                },
            },
        },
        xaxis: {
            categories,
            axisBorder: {
                show: false,
            },
            axisTicks: {
                show: false,
            },
            labels: {
                style: {
                    colors: '#667085',
                    fontSize: '12px',
                },
            },
        },
        yaxis: {
            labels: {
                style: {
                    colors: '#98A2B3',
                    fontSize: '12px',
                },
            },
        },
        tooltip: {
            y: {
                formatter: (val) => `${val}`,
            },
        },
    });

    chart.render();
    chartRegistry.set('usage', chart);
};

const renderGrowthAreaChart = () => {
    const el = document.querySelector('[data-admin-growth-chart]');

    if (!el) {
        destroyChart('growth');
        return;
    }

    destroyChart('growth');

    const categories = parseJson(el.dataset.categories);
    const detections = parseJson(el.dataset.detections).map((value) => Number(value));
    const signups = parseJson(el.dataset.signups).map((value) => Number(value));

    const chart = new ApexCharts(el, {
        chart: {
            type: 'area',
            height: 320,
            toolbar: {
                show: false,
            },
            fontFamily: 'Outfit, sans-serif',
        },
        series: [
            {
                name: 'Detections',
                data: detections,
            },
            {
                name: 'Signups',
                data: signups,
            },
        ],
        colors: ['#465FFF', '#9CB9FF'],
        fill: {
            gradient: {
                enabled: true,
                opacityFrom: 0.5,
                opacityTo: 0,
            },
        },
        stroke: {
            curve: 'straight',
            width: [2, 2],
        },
        markers: {
            size: 0,
        },
        dataLabels: {
            enabled: false,
        },
        grid: {
            borderColor: '#F2F4F7',
            xaxis: {
                lines: {
                    show: false,
                },
            },
            yaxis: {
                lines: {
                    show: true,
                },
            },
        },
        legend: {
            show: true,
            position: 'top',
            horizontalAlign: 'left',
            fontFamily: 'Outfit, sans-serif',
            labels: {
                colors: '#667085',
            },
        },
        xaxis: {
            categories,
            axisBorder: {
                show: false,
            },
            axisTicks: {
                show: false,
            },
            labels: {
                style: {
                    colors: '#667085',
                    fontSize: '12px',
                },
            },
        },
        yaxis: {
            labels: {
                style: {
                    colors: '#98A2B3',
                    fontSize: '12px',
                },
            },
        },
        tooltip: {
            shared: true,
        },
    });

    chart.render();
    chartRegistry.set('growth', chart);
};

const renderSubscriptionRadialChart = () => {
    const el = document.querySelector('[data-admin-radial-chart]');

    if (!el) {
        destroyChart('subscription');
        return;
    }

    destroyChart('subscription');

    const value = Number(el.dataset.value || 0);

    const chart = new ApexCharts(el, {
        chart: {
            type: 'radialBar',
            height: 330,
            sparkline: {
                enabled: true,
            },
            fontFamily: 'Outfit, sans-serif',
        },
        series: [value],
        colors: ['#465FFF'],
        plotOptions: {
            radialBar: {
                startAngle: -90,
                endAngle: 90,
                hollow: {
                    size: '80%',
                },
                track: {
                    background: '#E4E7EC',
                    strokeWidth: '100%',
                    margin: 5,
                },
                dataLabels: {
                    name: {
                        show: false,
                    },
                    value: {
                        fontSize: '36px',
                        fontWeight: '600',
                        offsetY: 58,
                        color: '#1D2939',
                        formatter: (val) => `${Math.round(val)}%`,
                    },
                },
            },
        },
        fill: {
            type: 'solid',
        },
        stroke: {
            lineCap: 'round',
        },
        labels: ['Coverage'],
    });

    chart.render();
    chartRegistry.set('subscription', chart);
};

const initAdminDashboardCharts = () => {
    renderUsageBarChart();
    renderGrowthAreaChart();
    renderSubscriptionRadialChart();
};

document.addEventListener('DOMContentLoaded', initAdminDashboardCharts);
document.addEventListener('livewire:navigated', initAdminDashboardCharts);

export { initAdminDashboardCharts };
