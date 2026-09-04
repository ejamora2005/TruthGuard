import { useEffect, useRef } from 'react';
import ApexCharts from 'apexcharts';

export function ApexChart({ type, height = 320, options, series }) {
    const elementRef = useRef(null);

    useEffect(() => {
        if (!elementRef.current) {
            return undefined;
        }

        const chart = new ApexCharts(elementRef.current, {
            chart: {
                fontFamily: 'Outfit, sans-serif',
                toolbar: { show: false },
            },
            ...options,
            type,
            series,
        });

        chart.render();

        return () => {
            chart.destroy();
        };
    }, [height, options, series, type]);

    return <div ref={elementRef} style={{ minHeight: `${height}px` }} />;
}
