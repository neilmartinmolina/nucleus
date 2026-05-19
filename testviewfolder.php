<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CS101 Project Dashboard</title>
    <link rel="stylesheet" href="assets/css/nucleus.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'bg-dark': '#1e1e1e', // Dark background
                        'bg-card': '#2d2d2d', // Slightly lighter card bg
                        'border-dark': '#3a3a3a',
                        'accent-green': '#4ade80',
                        'accent-yellow': '#fbbf24',
                        'accent-red': '#f87171',
                        'tag-yellow': '#fee2c5',
                        'tag-red': '#fecaca',
                        'tag-green': '#d1fae5',
                    }
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        /* custom forms styling for select carets */
        select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%23ffffff' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
    </style>
</head>
<body class="bg-bg-dark text-neutral-300 min-h-screen p-6 md:p-10 font-sans">

    <header class="flex items-center gap-2 mb-2 text-neutral-500">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
            <path stroke-linecap="round" stroke-linejoin='round' d='M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18' />
        </svg>
        <span>Folders / CS101</span>
    </header>

    <div class="mb-10">
        <h1 class="text-5xl font-bold text-white mb-2">CS101</h1>
        <p class="text-xl text-neutral-400 mb-8">Subject folder · 24 projects</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-bg-card p-6 rounded-xl border border-border-dark">
                <p class="text-neutral-400 text-sm mb-1">Total projects</p>
                <p class="text-4xl font-semibold text-white">24</p>
            </div>
            <div class="bg-bg-card p-6 rounded-xl border border-border-dark">
                <p class="text-neutral-400 text-sm mb-1">Updated this week</p>
                <p class="text-4xl font-semibold text-accent-green">11</p>
            </div>
            <div class="bg-bg-card p-6 rounded-xl border border-border-dark">
                <p class="text-neutral-400 text-sm mb-1">Needs update</p>
                <p class="text-4xl font-semibold text-accent-yellow">9</p>
            </div>
        </div>
    </div>

    <div class="flex flex-col md:flex-row gap-4 mb-8">
        <input type="text" placeholder="Search projects..." class="flex-grow p-4 rounded-lg bg-bg-card border border-border-dark focus:outline-none focus:border-neutral-600">
        
        <select class="p-4 pr-10 rounded-lg bg-bg-card border border-border-dark focus:outline-none focus:border-neutral-600 text-white">
            <option value="all">All statuses</option>
            <option value="needs-update">Needs update</option>
            <option value="30d-old">30d+ old</option>
            <option value="up-to-date">Up to date</option>
        </select>
        
        <select class="p-4 pr-10 rounded-lg bg-bg-card border border-border-dark focus:outline-none focus:border-neutral-600 text-white">
            <option value="name">Sort: name</option>
            <option value="updated">Sort: last updated</option>
        </select>

        <button class="flex items-center gap-2 p-4 px-6 rounded-lg bg-black text-white font-medium border border-border-dark hover:bg-neutral-800 transition">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add project
        </button>
    </div>

    <div id="project-grid" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
        </div>

    <script>
        // Project data from image_1.png
        const projects = [
            { name: "Blog template", url: "blog-tmpl.netlify.app", lastUpdated: "12d ago", status: "Needs update", statusColor: "tag-yellow" },
            { name: "Calculator", url: "calc.vercel.app", lastUpdated: "8d ago", status: "Needs update", statusColor: "tag-yellow" },
            { name: "Chat UI", url: "chatui.pages.dev", lastUpdated: "20d ago", status: "Needs update", statusColor: "tag-yellow" },
            { name: "Expense tracker", url: "expenses.vercel.app", lastUpdated: "32d ago", status: "30d+ old", statusColor: "tag-red" },
            { name: "Flashcards", url: "flashcards.pages.dev", lastUpdated: "40d ago", status: "30d+ old", statusColor: "tag-red" },
            { name: "Image gallery", url: "gallery.vercel.app", lastUpdated: "45d ago", status: "30d+ old", statusColor: "tag-red" },
            { name: "Landing page", url: "landing.netlify.app", lastUpdated: "25d ago", status: "Needs update", statusColor: "tag-yellow" },
            { name: "Portfolio site", url: "portfolio.vercel.app", lastUpdated: "Yesterday", status: "Up to date", statusColor: "tag-green" },
            { name: "Quiz app", url: "quiz.vercel.app", lastUpdated: "15d ago", status: "Needs update", statusColor: "tag-yellow" },
            { name: "Recipe app", url: "recipes.netlify.app", lastUpdated: "50d ago", status: "30d+ old", statusColor: "tag-red" },
            { name: "Todo list", url: "todo.pages.dev", lastUpdated: "5d ago", status: "Up to date", statusColor: "tag-green" },
            { name: "Weather app", url: "weatherapp.netlify.app", lastUpdated: "3d ago", status: "Up to date", statusColor: "tag-green" }
        ];

        // Component to generate a project card
        const createProjectCard = (project) => {
            // map statuses to select values for filtering
            const statusClass = project.status === "Needs update" ? 'status-needs-update' : 
                                project.status === "30d+ old" ? 'status-30d-old' : 
                                'status-up-to-date';

            return `
                <div class="bg-bg-card p-6 rounded-xl border border-border-dark flex flex-col gap-4 project-card ${statusClass}" data-name="${project.name.toLowerCase()}">
                    <div class="flex justify-between items-start gap-4">
                        <div>
                            <h2 class="text-2xl font-bold text-white">${project.name}</h2>
                            <p class="text-sm text-neutral-500">${project.url}</p>
                        </div>
                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-${project.statusColor} text-black whitespace-nowrap">${project.status}</span>
                    </div>
                    
                    <div class="mt-2 text-sm text-neutral-400">
                        Last updated <span class="text-white font-medium">${project.lastUpdated}</span>
                    </div>

                    <button class="mt-2 w-full flex items-center justify-center p-4 px-6 rounded-xl bg-black text-white font-medium border border-border-dark hover:bg-neutral-800 transition">
                        Mark updated now
                    </button>
                </div>
            `;
        };

        // Render function
        const grid = document.getElementById('project-grid');
        const renderProjects = (projectsToRender) => {
            grid.innerHTML = projectsToRender.map(createProjectCard).join('');
        };

        // Initialize grid
        renderProjects(projects);

        // --- JavaScript Interactive Logic ---

        // Search and Filter Elements
        const searchInput = document.querySelector('input[type="text"]');
        const statusFilter = document.querySelector('select:first-of-type');

        const filterProjects = () => {
            const searchTerm = searchInput.value.toLowerCase();
            const statusValue = statusFilter.value;

            const filteredProjects = projects.filter(project => {
                // Status Mapping: option value -> status text from data
                const statusMap = {
                    "all": null,
                    "needs-update": "Needs update",
                    "30d-old": "30d+ old",
                    "up-to-date": "Up to date"
                };
                
                const matchesSearch = project.name.toLowerCase().includes(searchTerm);
                const matchesStatus = !statusMap[statusValue] || project.status === statusMap[statusValue];

                return matchesSearch && matchesStatus;
            });

            renderProjects(filteredProjects);
        };

        // Attach listeners
        searchInput.addEventListener('input', filterProjects);
        statusFilter.addEventListener('change', filterProjects);

    </script>
</body>
</html>
