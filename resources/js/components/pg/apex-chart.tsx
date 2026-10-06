import ApexCharts from 'apexcharts';
import type { ApexOptions } from 'apexcharts';
import { useEffect, useRef } from 'react';

/**
 * One dashboard chart (ApexCharts, as in the client's existing system):
 * a smooth filled area per time slot, or bars per category, with value
 * labels, a zoom / pan / download toolbar and axis titles. Loaded lazily so
 * the chart library only downloads with the dashboard.
 */
export default function ApexChart({
    type,
    categories,
    values,
    name,
    color,
    xTitle,
    yTitle,
    format,
    height = 300,
}: {
    type: 'area' | 'bar';
    categories: string[];
    values: number[];
    name: string;
    color: string;
    xTitle?: string;
    yTitle?: string;
    format: (value: number) => string;
    height?: number;
}) {
    const dark = document.documentElement.classList.contains('dark');
    // Label every few slots on two lines ("25-Sep" / "13:00"), like the
    // client's old charts; the tooltip always shows the full slot.
    const every = Math.max(1, Math.ceil(categories.length / 8));
    const shown = categories.map((label, index) =>
        type === 'bar' || index % every === 0 || index === categories.length - 1
            ? label.split(' ')
            : [''],
    );
    const empty = values.every((value) => value === 0);
    const muted = dark ? '#8391a8' : '#64748b';
    const options: ApexOptions = {
        chart: {
            type,
            fontFamily: 'inherit',
            background: 'transparent',
            foreColor: muted,
            toolbar: {
                show: true,
                tools: {
                    download: true,
                    zoomin: true,
                    zoomout: true,
                    zoom: type === 'area',
                    pan: type === 'area',
                    reset: true,
                    selection: false,
                },
            },
            zoom: { enabled: type === 'area' },
            animations: { enabled: false },
        },
        theme: { mode: dark ? 'dark' : 'light' },
        colors: [color],
        stroke: type === 'area' ? { curve: 'smooth', width: 3 } : { width: 0 },
        fill:
            type === 'area'
                ? {
                      type: 'gradient',
                      gradient: {
                          shadeIntensity: 1,
                          opacityFrom: 0.45,
                          opacityTo: 0.05,
                          stops: [0, 100],
                      },
                  }
                : { opacity: 0.85 },
        plotOptions: {
            bar: {
                borderRadius: 6,
                columnWidth: '45%',
                dataLabels: { position: 'top' },
            },
        },
        markers:
            type === 'area'
                ? { size: 4, strokeWidth: 0, hover: { size: 6 } }
                : {},
        dataLabels: {
            enabled: values.length <= 31,
            // Zero slots stay unlabelled so the line is readable.
            formatter: (value) =>
                Number(value) === 0 ? '' : format(Number(value)),
            // Areas: a coloured tag on each point. Bars: the value above the bar.
            offsetY: type === 'bar' ? -22 : -4,
            style: {
                fontSize: type === 'bar' ? '12px' : '10px',
                fontWeight: 600,
                colors: type === 'bar' ? [color] : undefined,
            },
            background: {
                enabled: type === 'area',
                foreColor: '#fff',
                borderWidth: 0,
                borderRadius: 3,
                padding: 3,
                opacity: 1,
            },
        },
        grid: {
            borderColor: dark ? '#1f2a3f' : '#eef0f3',
            strokeDashArray: 0,
            row: {
                colors: dark ? undefined : ['#f8fafc', 'transparent'],
                opacity: 1,
            },
        },
        xaxis: {
            categories: shown,
            labels: { rotate: 0, trim: false },
            tooltip: { enabled: false },
            // Always an object: `undefined` here makes ApexCharts crash.
            title: { text: xTitle, style: { fontWeight: 600 } },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            min: 0,
            // Nothing in the period: still draw a scale instead of a flat strip.
            max: empty ? 10 : undefined,
            tickAmount: 5,
            forceNiceScale: true,
            labels: { formatter: (value) => format(Number(value)) },
            // Always an object: `undefined` here makes ApexCharts crash.
            title: { text: yTitle, style: { fontWeight: 600 } },
        },
        tooltip: {
            x: {
                formatter: (_, opts) =>
                    categories[opts?.dataPointIndex ?? 0] ?? '',
            },
            y: { formatter: (value) => format(Number(value)) },
        },
        legend: { show: false },
    };

    const element = useRef<HTMLDivElement>(null);
    const chart = useRef<ApexCharts | null>(null);
    const config: ApexOptions = {
        ...options,
        chart: { ...options.chart, height },
        series: [{ name, data: values }],
    };

    // Drive ApexCharts directly: draw once, then update in place.
    useEffect(() => {
        if (!element.current) return;

        if (chart.current) {
            void chart.current.updateOptions(config, true, false);

            return;
        }

        chart.current = new ApexCharts(element.current, config);
        void chart.current.render();
    });

    useEffect(
        () => () => {
            chart.current?.destroy();
            chart.current = null;
        },
        [],
    );

    return <div ref={element} style={{ minHeight: height }} />;
}
