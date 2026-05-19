<?php
// Mock data for the dashboard
$stats = [
    ['label' => 'Frontend duration', 'value' => '2.14s', 'color' => '#6366f1', 'bg' => 'bg-indigo-50'],
    ['label' => 'Backend duration', 'value' => '1.05s', 'color' => '#10b981', 'bg' => 'bg-emerald-50'],
    ['label' => 'Local time', 'value' => '0.25s', 'color' => '#f43f5e', 'bg' => 'bg-rose-50'],
    ['label' => 'Processing time', 'value' => '3.07s', 'color' => '#4f46e5', 'bg' => 'bg-blue-50'],
];

$quickStats = [
    ['label' => 'Total Projects', 'value' => '127', 'icon' => 'folder', 'color' => 'indigo'],
    ['label' => 'Subjects', 'value' => '42', 'icon' => 'book', 'color' => 'emerald'],
    ['label' => 'Users', 'value' => '84', 'icon' => 'users', 'color' => 'rose'],
    ['label' => 'Updated Today', 'value' => '23', 'icon' => 'refresh', 'color' => 'orange'],
];

$errors = [
    ['name' => 'Error name', 'value' => '3009', 'color' => 'bg-orange-500'],
    ['name' => 'Error name', 'value' => '7301', 'color' => 'bg-yellow-400'],
    ['name' => 'Error name', 'value' => '7534', 'color' => 'bg-teal-400'],
    ['name' => 'Error name', 'value' => '5002', 'color' => 'bg-indigo-600'],
];

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Dashboard</title>
    <link rel="stylesheet" href="assets/css/nucleus.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            color-scheme: light;
            --page: #f6f8fb;
            --card: rgba(255, 255, 255, 0.94);
            --line: #e5e7eb;
            --ink: #111827;
            --muted: #64748b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(79, 156, 249, 0.12), transparent 34rem),
                linear-gradient(135deg, #f8fafc 0%, var(--page) 46%, #eef2f7 100%);
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .dashboard-shell {
            width: min(100%, 1440px);
            margin: 0 auto;
            padding: clamp(0.5rem, 1.2vw, 0.9rem);
        }

        .bento-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            grid-auto-flow: dense;
            gap: clamp(0.5rem, 0.9vw, 0.7rem);
        }

        .bento-card {
            min-width: 0;
            overflow: hidden;
            background: var(--card);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 0.5rem;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.07);
            backdrop-filter: blur(12px);
        }

        .tile-header { grid-column: span 12; }
        .tile-stat { grid-column: span 3; min-height: 5rem; }
        .tile-main-chart { grid-column: span 8; min-height: 15rem; }
        .tile-donut { grid-column: span 4; min-height: 15rem; }
        .tile-performance { grid-column: span 3; min-height: 7.25rem; }

        .chart-frame {
            position: relative;
            width: 100%;
            min-height: 9.5rem;
        }

        .donut-frame {
            position: relative;
            width: min(100%, 9rem);
            min-height: 7.5rem;
            margin: 0 auto;
        }

        .spark-frame {
            position: relative;
            width: 100%;
            min-height: 2.35rem;
            margin-top: 0.35rem;
        }

        .chart-frame canvas,
        .donut-frame canvas,
        .spark-frame canvas {
            display: block;
            width: 100% !important;
            height: 100% !important;
        }

        @media (max-width: 1180px) {
            .tile-stat,
            .tile-performance {
                grid-column: span 6;
            }

            .tile-main-chart,
            .tile-donut {
                grid-column: span 12;
            }
        }

        @media (max-width: 720px) {
            .dashboard-shell {
                padding: 1rem;
            }

            .bento-grid {
                grid-template-columns: 1fr;
            }

            .tile-header,
            .tile-stat,
            .tile-main-chart,
            .tile-donut,
            .tile-performance {
                grid-column: 1 / -1;
            }

            .chart-frame {
                min-height: 16rem;
            }
        }
    </style>
</head>
<body>
<main class="dashboard-shell">
    <div class="bento-grid">
        <header class="bento-card tile-header p-3">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <img src="https://ui-avatars.com/api/?name=Admin&background=ffedd5&color=fb923c" class="h-9 w-9 shrink-0 rounded-lg" alt="Admin profile">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-semibold text-gray-900">Admin</p>
                        <h1 class="text-lg font-bold text-gray-900">Dashboard</h1>
                        <p class="text-xs text-gray-500"><span class="font-medium text-orange-500">Hi there,</span> great to see you again</p>
                    </div>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <label class="relative block">
                        <span class="sr-only">Search dashboard</span>
                        <input type="text" placeholder="Search..." class="w-full min-w-0 rounded-lg border border-gray-200 bg-white py-2 pl-9 pr-4 text-sm shadow-sm outline-none transition focus:border-indigo-300 focus:ring-4 focus:ring-indigo-100 sm:w-64">
                        <svg class="absolute left-3 top-2.5 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </label>
                </div>
            </div>
        </header>

        <?php foreach ($quickStats as $stat): ?>
            <section class="bento-card tile-stat p-3">
                <div class="flex h-full items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo e($stat['label']); ?></p>
                        <p class="mt-1 text-xl font-bold text-gray-900"><?php echo e($stat['value']); ?></p>
                    </div>
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-<?php echo e($stat['color']); ?>-50">
                        <svg class="h-5 w-5 text-<?php echo e($stat['color']); ?>-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <?php if ($stat['icon'] === 'folder'): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h5l2 2h9a2 2 0 012 2v7a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"></path>
                            <?php elseif ($stat['icon'] === 'book'): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 19 7.5 19s3.332-.477 4.5-1.253v-13"></path>
                            <?php elseif ($stat['icon'] === 'users'): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            <?php else: ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.021 8.021 0 004 12v5h14.582l2.707-2.707A8 8 0 004 9.582V4h16z"></path>
                            <?php endif; ?>
                        </svg>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>

        <section class="bento-card tile-main-chart p-3">
            <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-bold text-gray-900">Load Time - Last 24 hours</h2>
                    <p class="text-xs text-gray-500">Frontend and backend response trend</p>
                </div>
                <div class="flex gap-4 text-xs font-medium text-gray-500">
                    <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-orange-500"></span>Low</span>
                    <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-indigo-600"></span>High</span>
                </div>
            </div>
            <div class="chart-frame">
                <canvas id="mainLoadChart"></canvas>
            </div>
        </section>

        <section class="bento-card tile-donut p-3">
            <div class="mb-2">
                <h2 class="text-base font-bold text-gray-900">Error Types</h2>
                <p class="text-xs text-gray-500">Current incident distribution</p>
            </div>
            <div class="donut-frame">
                <canvas id="errorDonutChart"></canvas>
            </div>
            <div class="mt-2 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                <?php foreach ($errors as $error): ?>
                    <div class="flex min-w-0 items-center gap-2 rounded-lg border border-gray-100 bg-gray-50/80 px-2 py-1.5">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full <?php echo e($error['color']); ?>"></span>
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold text-gray-700"><?php echo e($error['name']); ?></p>
                            <p class="text-xs text-gray-400"><?php echo e($error['value']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php foreach ($stats as $index => $stat): ?>
            <section class="bento-card tile-performance p-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo e($stat['label']); ?></p>
                        <h2 class="mt-1 text-xl font-bold text-gray-900"><?php echo e($stat['value']); ?></h2>
                    </div>
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg <?php echo e($stat['bg']); ?>">
                        <svg class="h-5 w-5" style="color: <?php echo e($stat['color']); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                    </div>
                </div>
                <div class="spark-frame">
                    <canvas id="miniChart<?php echo (int) $index; ?>"></canvas>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</main>

<script>
    Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, sans-serif';
    Chart.defaults.color = '#64748b';
    Chart.defaults.responsive = true;
    Chart.defaults.maintainAspectRatio = false;

    function createSparkline(id, color, values) {
        new Chart(document.getElementById(id), {
            type: 'line',
            data: {
                labels: values.map((_, index) => index + 1),
                datasets: [{
                    data: values,
                    borderColor: color,
                    backgroundColor: `${color}1f`,
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 0,
                    fill: true
                }]
            },
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                },
                scales: {
                    x: { display: false },
                    y: { display: false }
                },
                elements: {
                    line: { borderCapStyle: 'round' }
                }
            }
        });
    }

    [
        ['#6366f1', [10, 22, 16, 31, 24, 35, 32]],
        ['#10b981', [16, 14, 20, 18, 25, 24, 30]],
        ['#f43f5e', [8, 12, 10, 16, 13, 18, 15]],
        ['#4f46e5', [18, 26, 20, 30, 27, 34, 38]]
    ].forEach(([color, values], index) => createSparkline(`miniChart${index}`, color, values));

    new Chart(document.getElementById('mainLoadChart'), {
        type: 'line',
        data: {
            labels: ['00:00', '03:00', '06:00', '09:00', '12:00', '15:00', '18:00', '21:00'],
            datasets: [
                {
                    label: 'High',
                    data: [2, 4, 3, 7, 5, 9, 6, 8],
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.12)',
                    borderWidth: 3,
                    tension: 0.42,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    fill: true
                },
                {
                    label: 'Low',
                    data: [1, 2, 5, 2, 4, 3, 5, 2],
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.1)',
                    borderWidth: 3,
                    tension: 0.42,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    fill: true
                }
            ]
        },
        options: {
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#111827',
                    padding: 12,
                    titleColor: '#fff',
                    bodyColor: '#e5e7eb',
                    displayColors: true
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    border: { display: false },
                    grid: { color: 'rgba(148, 163, 184, 0.2)' },
                    ticks: { padding: 10 }
                },
                x: {
                    border: { display: false },
                    grid: { display: false },
                    ticks: { padding: 10 }
                }
            }
        }
    });

    new Chart(document.getElementById('errorDonutChart'), {
        type: 'doughnut',
        data: {
            labels: ['Orange', 'Yellow', 'Teal', 'Indigo'],
            datasets: [{
                data: [30, 20, 25, 25],
                backgroundColor: ['#f97316', '#facc15', '#2dd4bf', '#4f46e5'],
                borderColor: '#ffffff',
                borderWidth: 4,
                hoverOffset: 8,
                cutout: '68%'
            }]
        },
        options: {
            plugins: {
                legend: { display: false }
            }
        }
    });
</script>
</body>
</html>
