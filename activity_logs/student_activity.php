<?php
session_start();
require_once '../db_connection.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Set timezone to IST (UTC+5:30)
date_default_timezone_set('Asia/Kolkata');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Activity Logs - ASD Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f4f6f8; color: #1B3C53; overflow-x: hidden; }
        
        /* Premium Background System */
        .koral-bg-wrap { position: fixed; inset: 0; z-index: -1; background: linear-gradient(145deg, #f4f6f8 0%, #eef3f7 50%, #f4f6f8 100%); overflow: hidden; }
        .rpt-orb1, .rpt-orb2 { position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.4; animation: rpt-float 20s infinite ease-in-out alternate; }
        .rpt-orb1 { width: 400px; height: 400px; background: rgba(35, 76, 106, 0.15); top: -100px; left: -100px; }
        .rpt-orb2 { width: 500px; height: 500px; background: rgba(69, 104, 130, 0.12); bottom: -150px; right: -100px; animation-delay: -10s; }
        @keyframes rpt-float { 0% { transform: translate(0,0) rotate(0deg); } 100% { transform: translate(50px, 50px) rotate(10deg); } }

        /* Glass Panel System */
        .glass-panel { 
            background: rgba(255, 255, 255, 0.85); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px); 
            border: 1px solid rgba(27, 60, 83, 0.08) !important; 
            border-radius: 20px !important; 
            box-shadow: 0 10px 30px rgba(27, 60, 83, 0.04) !important; 
            overflow: hidden; 
        }

        /* Gradient Stat Cards */
        .stat-card-gradient { padding: 1.5rem; border-radius: 20px; color: #fff; position: relative; overflow: hidden; transition: all 0.3s ease; box-shadow: 0 12px 28px rgba(27, 60, 83, 0.15) !important; }
        .stat-card-gradient::after { content: ''; position: absolute; top: 0; left: -100%; width: 50%; height: 100%; background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.2) 50%, rgba(255,255,255,0) 100%); transform: skewX(-25deg); animation: shimmer 3s infinite; }
        @keyframes shimmer { 0% { left: -100%; } 100% { left: 200%; } }
        .stat-card-gradient:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(27, 60, 83, 0.25) !important; }
        
        .scg-blue { background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important; }
        .scg-teal { background: linear-gradient(135deg, #2d7a8a 0%, #1B3C53 100%) !important; }
        .scg-violet { background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important; }
        .scg-orange { background: linear-gradient(135deg, #b6876a 0%, #9c6f55 100%) !important; }
        .scg-green { background: linear-gradient(135deg, #2d7a8a 0%, #234C6A 100%) !important; }
        .scg-pink { background: linear-gradient(135deg, #b6876a 0%, #1B3C53 100%) !important; }

        /* Brand Styled Buttons */
        .btn-brand-primary {
            background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
            color: white !important;
            box-shadow: 0 4px 12px rgba(27, 60, 83, 0.2) !important;
            border: none !important;
            transition: all 0.3s ease !important;
        }
        .btn-brand-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
            box-shadow: 0 6px 16px rgba(27, 60, 83, 0.3) !important;
            transform: translateY(-1px) !important;
        }
        
        .btn-brand-action {
            background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
            color: white !important;
            box-shadow: 0 4px 12px rgba(27, 60, 83, 0.15) !important;
            transition: all 0.3s ease !important;
        }
        .btn-brand-action:hover:not(:disabled) {
            background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
            box-shadow: 0 6px 16px rgba(27, 60, 83, 0.2) !important;
            transform: translateY(-1px) !important;
        }
        .btn-brand-action:disabled {
            opacity: 0.5 !important;
            cursor: not-allowed !important;
            box-shadow: none !important;
        }

        .btn-brand-secondary {
            background: white !important;
            color: #234C6A !important;
            border: 1px solid rgba(35, 76, 106, 0.2) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02) !important;
            transition: all 0.3s ease !important;
        }
        .btn-brand-secondary:hover:not(:disabled) {
            background: #fdfdfd !important;
            border-color: rgba(35, 76, 106, 0.4) !important;
            color: #1B3C53 !important;
            transform: translateY(-1px) !important;
        }
        .btn-brand-secondary:disabled {
            opacity: 0.5 !important;
            cursor: not-allowed !important;
            box-shadow: none !important;
        }

        .section-title { display: flex; align-items: center; font-size: 1.25rem; font-weight: 700; color: #1B3C53; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid rgba(27, 60, 83, 0.08); }
        .section-title i { margin-right: 0.75rem; color: #234C6A; }
        .spinner { border: 3px solid rgba(35, 76, 106, 0.1); width: 24px; height: 24px; border-radius: 50%; border-left-color: #234C6A; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Inputs focus */
        input#searchInput:focus {
            border-color: #234C6A !important;
            box-shadow: 0 0 0 2px rgba(35, 76, 106, 0.2) !important;
        }
        
        /* Table enhancements */
        .glass-table { width: 100%; text-align: left; border-collapse: separate; border-spacing: 0; }
        .glass-table th { padding: 1rem; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; color: #456882; border-bottom: 1px solid rgba(27, 60, 83, 0.08); background: rgba(244, 246, 248, 0.5); }
        .glass-table td { padding: 1rem; border-bottom: 1px solid rgba(27, 60, 83, 0.06); font-size: 0.875rem; }
        .glass-table tr:hover td { background: rgba(255,255,255,0.4); }
        
        /* IST Time display indicator */
        .ist-badge {
            background: rgba(35, 76, 106, 0.1);
            color: #234C6A;
            font-size: 0.6rem;
            font-weight: 700;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            border: 1px solid rgba(35, 76, 106, 0.15);
            letter-spacing: 0.05em;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <div class="koral-bg-wrap"><div class="rpt-orb1"></div><div class="rpt-orb2"></div></div>
    
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <main class="flex-1 ml-0 md:ml-64 min-h-screen pt-20 p-6 transition-all duration-300">
        
        <div class="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 tracking-tight">
                    Live Activity Logs <span id="refreshIndicator" class="ml-2 inline-block"><i class="fas fa-circle text-green-500 text-sm animate-pulse"></i></span>
                </h1>
                <p class="text-gray-500 mt-1 flex items-center gap-2">
                    Real-time student activity and system audit tracking.
                    <span class="ist-badge"><i class="far fa-clock mr-1"></i> IST (UTC+5:30)</span>
                </p>
            </div>
            <div class="flex gap-2">
                <button onclick="fetchData()" class="btn-brand-primary px-6 py-2.5 rounded-xl flex items-center text-sm font-semibold tracking-wider uppercase">
                    <i class="fas fa-sync-alt mr-2" id="syncIcon"></i> Force Refresh
                </button>
            </div>
        </div>

        <!-- QUICK STATS ROW -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
            <div class="stat-card-gradient scg-blue text-center">
                <div class="w-12 h-12 mx-auto rounded-full bg-white/20 text-white flex items-center justify-center text-xl mb-3 shadow-inner"><i class="fas fa-list-ul"></i></div>
                <p class="text-xs font-bold text-white/80 uppercase tracking-wider">Total Logs</p>
                <p class="text-3xl font-black text-white mt-1 drop-shadow-md" id="valTotalLogs">-</p>
            </div>
            <div class="stat-card-gradient scg-orange text-center" style="animation-delay: 0.1s;">
                <div class="w-12 h-12 mx-auto rounded-full bg-white/20 text-white flex items-center justify-center text-xl mb-3 shadow-inner"><i class="fas fa-file-alt"></i></div>
                <p class="text-xs font-bold text-white/80 uppercase tracking-wider">Unique Pages Visited</p>
                <p class="text-3xl font-black text-white mt-1 drop-shadow-md" id="valUniquePages">-</p>
            </div>
            <div class="stat-card-gradient scg-teal text-center" style="animation-delay: 0.2s;">
                <div class="w-12 h-12 mx-auto rounded-full bg-white/20 text-white flex items-center justify-center text-xl mb-3 shadow-inner"><i class="fas fa-clock"></i></div>
                <p class="text-xs font-bold text-white/80 uppercase tracking-wider">Total Time Spent</p>
                <p class="text-3xl font-black text-white mt-1 drop-shadow-md" id="valTimeSpent">-</p>
            </div>
            <div class="stat-card-gradient scg-violet text-center" style="animation-delay: 0.3s;">
                <div class="w-12 h-12 mx-auto rounded-full bg-white/20 text-white flex items-center justify-center text-xl mb-3 shadow-inner"><i class="fas fa-users"></i></div>
                <p class="text-xs font-bold text-white/80 uppercase tracking-wider">Active Students Today</p>
                <p class="text-3xl font-black text-white mt-1 drop-shadow-md" id="valActiveToday">-</p>
            </div>
        </div>

        <!-- CHARTS & LEADERBOARD -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-10">
            <!-- Top 5 Most Visited Pages -->
            <div class="glass-panel p-0 lg:col-span-1 bg-white/60 flex flex-col">
                <div class="p-4 bg-gradient-to-r from-gray-50 to-gray-100/50 border-b border-gray-200/50">
                    <h3 class="text-sm font-semibold text-gray-800 uppercase tracking-wider"><i class="fas fa-chart-bar text-[#234C6A] mr-2"></i>Top 5 Most Visited Pages</h3>
                </div>
                <div class="relative h-64 p-5"><canvas id="topPagesChart"></canvas></div>
            </div>

            <!-- Activity Trend -->
            <div class="glass-panel p-0 lg:col-span-1 bg-white/60 flex flex-col">
                <div class="p-4 bg-gradient-to-r from-gray-50 to-gray-100/50 border-b border-gray-200/50">
                    <h3 class="text-sm font-semibold text-gray-800 uppercase tracking-wider"><i class="fas fa-chart-line text-[#234C6A] mr-2"></i>Activity Trend (Last 7 Days)</h3>
                </div>
                <div class="relative h-64 p-5"><canvas id="trendChart"></canvas></div>
            </div>

            <!-- Leaderboard -->
            <div class="glass-panel lg:col-span-1 overflow-hidden flex flex-col p-0 bg-white/60">
                <div class="p-4 bg-gradient-to-r from-gray-50 to-gray-100/50 border-b border-gray-200/50">
                    <h3 class="text-sm font-semibold text-gray-800 uppercase tracking-wider"><i class="fas fa-trophy text-[#234C6A] mr-2"></i> Top 10 Active Students</h3>
                </div>
                <div class="overflow-y-auto flex-1 p-0">
                    <table class="w-full text-left border-collapse">
                        <tbody id="leaderboardBody" class="text-sm text-gray-700 divide-y divide-gray-100">
                            <tr><td class="p-4 text-center"><div class="spinner mx-auto"></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- COMPLETE ACTIVITY LOGS TABLE -->
        <h2 class="section-title"><i class="fas fa-history text-[#234C6A]"></i> Student Activity Logs</h2>
        <div class="glass-panel p-0 mb-10 bg-white/60">
            <div class="p-4 bg-gradient-to-r from-gray-50 to-gray-100/50 border-b border-gray-200/50 flex flex-col sm:flex-row justify-between items-center gap-4">
                <div class="w-full sm:w-1/3 relative">
                    <input type="text" id="searchInput" placeholder="Search names or URLs..." class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#234C6A]/20 focus:border-[#234C6A] bg-white shadow-sm font-medium" oninput="debounceSearch()">
                    <i class="fas fa-search absolute left-3 top-3 text-[#234C6A]/50"></i>
                </div>
                <span class="text-xs text-gray-400"><i class="far fa-clock mr-1"></i> All times in IST (UTC+5:30)</span>
            </div>
            <div class="overflow-x-auto">
                <table class="glass-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Current Page</th>
                            <th>Time Spent</th>
                            <th>Last Ping (IST)</th>
                        </tr>
                    </thead>
                    <tbody id="activityLogsBody">
                        <tr><td colspan="4" class="p-4 text-center"><div class="spinner mx-auto"></div></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-200 bg-white/40 flex justify-between items-center">
                <button onclick="prevPage()" class="btn-brand-secondary px-5 py-2 rounded-xl font-bold text-sm tracking-wide" id="btnPrev">Previous</button>
                <span class="text-sm font-semibold text-[#234C6A] bg-white/80 px-4 py-1.5 rounded-lg border border-gray-200 shadow-inner" id="pageInfo">Page 1</span>
                <button onclick="nextPage()" class="btn-brand-action px-5 py-2 rounded-xl font-bold text-sm tracking-wide" id="btnNext">Next</button>
            </div>
        </div>

        <!-- SYSTEM AUDIT LOGS -->
        <h2 class="section-title"><i class="fas fa-shield-alt text-[#234C6A]"></i> System Audit Logs</h2>
        <div class="glass-panel p-0 mb-10 bg-white/60 flex flex-col">
            <div class="p-4 bg-gradient-to-r from-gray-50 to-gray-100/50 border-b border-gray-200/50 flex justify-between items-center">
                <h3 class="text-sm font-semibold text-gray-800 uppercase tracking-wider"><i class="fas fa-shield-alt text-[#234C6A] mr-2"></i> Security Events</h3>
                <span class="text-xs text-gray-400"><i class="far fa-clock mr-1"></i> IST (UTC+5:30)</span>
            </div>
            <div class="overflow-x-auto">
                <table class="glass-table">
                    <thead>
                        <tr>
                            <th>Timestamp (IST)</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody id="auditLogsBody">
                        <tr><td colspan="4" class="p-4 text-center"><div class="spinner mx-auto"></div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <script>
        let currentPage = 1;
        let currentSearch = '';
        let topPagesChart, trendChart;

        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#6b7280';
        
        function initCharts() {
            const ctxTop = document.getElementById('topPagesChart').getContext('2d');
            topPagesChart = new Chart(ctxTop, {
                type: 'bar',
                data: { labels: [], datasets: [{ label: 'Visits', data: [], backgroundColor: '#234C6A', borderRadius: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });

            const ctxTrend = document.getElementById('trendChart').getContext('2d');
            trendChart = new Chart(ctxTrend, {
                type: 'line',
                data: { labels: [], datasets: [{ label: 'Activity', data: [], borderColor: '#456882', backgroundColor: 'rgba(69, 104, 130, 0.1)', fill: true, tension: 0.4 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
        }

        /**
         * Convert UTC datetime string to IST (UTC+5:30) formatted string
         * @param {string} utcDate - UTC datetime string from database
         * @param {string} format - 'time' for time only, 'full' for full datetime, 'date' for date only
         * @returns {string} Formatted IST time string
         */
        function convertToIST(utcDate, format = 'full') {
            if (!utcDate) return '-';
            
            try {
                // Parse the UTC date string
                const date = new Date(utcDate);
                
                // Check if date is valid
                if (isNaN(date.getTime())) {
                    return utcDate;
                }
                
                // Add 5 hours and 30 minutes (IST offset)
                const istOffset = 5 * 60 + 30; // minutes
                const istDate = new Date(date.getTime() + (istOffset * 60 * 1000));
                
                // Format based on requested output
                const options = { timeZone: 'Asia/Kolkata' };
                
                if (format === 'time') {
                    return istDate.toLocaleTimeString('en-IN', { 
                        hour: '2-digit', 
                        minute: '2-digit', 
                        hour12: true,
                        timeZone: 'Asia/Kolkata'
                    });
                } else if (format === 'date') {
                    return istDate.toLocaleDateString('en-IN', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                        timeZone: 'Asia/Kolkata'
                    });
                } else {
                    // Full format
                    return istDate.toLocaleString('en-IN', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true,
                        timeZone: 'Asia/Kolkata'
                    });
                }
            } catch (e) {
                return utcDate;
            }
        }

        /**
         * Calculate time difference in IST from session start to last ping
         * @param {string} startTime - UTC session start time
         * @param {string} lastPing - UTC last ping time
         * @returns {string} Formatted time duration (e.g., "2h 30m" or "45m" or "30s")
         */
        function calculateTimeSpentIST(startTime, lastPing) {
            if (!startTime || !lastPing) return '0s';
            
            try {
                const start = new Date(startTime);
                const end = new Date(lastPing);
                
                if (isNaN(start.getTime()) || isNaN(end.getTime())) {
                    return '0s';
                }
                
                // Calculate difference in milliseconds
                const diffMs = end.getTime() - start.getTime();
                
                if (diffMs < 0) return '0s';
                
                const diffSeconds = Math.floor(diffMs / 1000);
                const diffMinutes = Math.floor(diffSeconds / 60);
                const diffHours = Math.floor(diffMinutes / 60);
                
                if (diffHours > 0) {
                    const remainingMinutes = diffMinutes % 60;
                    return `${diffHours}h ${remainingMinutes}m`;
                } else if (diffMinutes > 0) {
                    return `${diffMinutes}m`;
                } else {
                    return `${diffSeconds}s`;
                }
            } catch (e) {
                return '0s';
            }
        }

        async function fetchAnalytics() {
            try {
                const res = await fetch('get_activity_analytics.php');
                const json = await res.json();
                if(json.error) return;
                
                document.getElementById('valTotalLogs').innerText = json.cards.total_logs;
                document.getElementById('valUniquePages').innerText = json.cards.unique_pages;
                document.getElementById('valTimeSpent').innerText = json.cards.time_spent;
                document.getElementById('valActiveToday').innerText = json.cards.active_today;

                // Update charts
                topPagesChart.data.labels = json.charts.top_pages.labels;
                topPagesChart.data.datasets[0].data = json.charts.top_pages.data;
                topPagesChart.update();

                trendChart.data.labels = json.charts.trend.labels;
                trendChart.data.datasets[0].data = json.charts.trend.data;
                trendChart.update();

                // Update leaderboard with IST times
                let lbHtml = '';
                if(json.leaderboard.length === 0) lbHtml = '<tr><td class="p-4 text-center text-gray-500">No active students</td></tr>';
                json.leaderboard.forEach((item, idx) => {
                    let medal = '';
                    if(idx === 0) medal = '<i class="fas fa-medal text-yellow-500 mr-2"></i>';
                    else if(idx === 1) medal = '<i class="fas fa-medal text-gray-400 mr-2"></i>';
                    else if(idx === 2) medal = '<i class="fas fa-medal text-yellow-700 mr-2"></i>';
                    else medal = `<span class="text-gray-400 font-bold mr-2 w-4 inline-block text-center">${idx+1}</span>`;
                    
                    lbHtml += `
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 border-b flex justify-between items-center">
                            <div>${medal} <span class="font-medium">${item.name}</span></div>
                            <span class="text-xs bg-[#234C6A]/10 text-[#234C6A] px-2 py-1 rounded font-semibold">${item.time_spent}</span>
                        </td>
                    </tr>`;
                });
                document.getElementById('leaderboardBody').innerHTML = lbHtml;

            } catch(e) { console.error('Error fetching analytics:', e); }
        }

        async function fetchActiveStudents() {
            try {
                const res = await fetch(`get_active_students.php?page=${currentPage}&search=${encodeURIComponent(currentSearch)}`);
                const json = await res.json();
                if(json.error) return;

                let html = '';
                if(json.data.length === 0) {
                    html = '<tr><td colspan="4" class="p-4 text-center text-gray-500">No records found.</td></tr>';
                } else {
                    json.data.forEach(item => {
                        // Convert UTC times to IST for display
                        const lastPingIST = convertToIST(item.last_ping, 'full');
                        const timeSpent = calculateTimeSpentIST(item.session_start_time, item.last_ping);
                        
                        html += `
                        <tr class="transition-colors">
                            <td class="p-4 font-bold text-gray-800"><i class="fas fa-user-circle text-[#456882] mr-2"></i> ${item.student_name}</td>
                            <td class="p-4 text-gray-600"><span class="bg-white/50 text-[#234C6A] font-semibold px-2 py-1 rounded text-xs border border-gray-200/50 shadow-sm" title="${item.page_url}">${item.clean_url}</span></td>
                            <td class="p-4"><span class="font-bold text-[#234C6A]">${timeSpent}</span></td>
                            <td class="p-4 text-gray-500 font-medium text-xs">${lastPingIST}</td>
                        </tr>`;
                    });
                }
                document.getElementById('activityLogsBody').innerHTML = html;
                document.getElementById('pageInfo').innerText = `Page ${json.pagination.current_page} of ${json.pagination.total_pages || 1}`;
                
                document.getElementById('btnPrev').disabled = json.pagination.current_page <= 1;
                document.getElementById('btnNext').disabled = json.pagination.current_page >= json.pagination.total_pages;

            } catch(e) { console.error('Error fetching active students:', e); }
        }

        async function fetchSystemAudits() {
            try {
                const res = await fetch('get_system_audits.php');
                const json = await res.json();
                if(json.error) return;

                let html = '';
                if(json.data.length === 0) {
                    html = '<tr><td colspan="4" class="p-4 text-center text-gray-500">No audit logs found.</td></tr>';
                } else {
                    json.data.forEach(item => {
                        let badgeColor = 'bg-gray-100 text-gray-700';
                        if(item.action_type.includes('LOGIN')) badgeColor = 'bg-[#234C6A]/10 text-[#234C6A]';
                        if(item.action_type.includes('CREATE') || item.action_type.includes('BATCH')) badgeColor = 'bg-emerald-100 text-emerald-700';
                        if(item.action_type.includes('DELETE') || item.action_type.includes('DROP')) badgeColor = 'bg-rose-100 text-rose-800';
                        if(item.action_type.includes('UPDATE')) badgeColor = 'bg-[#D2C1B6]/30 text-[#8c6239]';

                        // Convert UTC timestamp to IST
                        const timestampIST = convertToIST(item.timestamp, 'full');

                        html += `
                        <tr class="transition-colors">
                            <td class="p-4 text-gray-500 text-xs font-medium"><i class="far fa-clock mr-1 text-[#456882]"></i> ${timestampIST}</td>
                            <td class="p-4 font-bold text-gray-800">${item.user}</td>
                            <td class="p-4"><span class="px-2 py-1 rounded-md text-[10px] font-black uppercase shadow-sm ${badgeColor}">${item.action_type}</span></td>
                            <td class="p-4 text-gray-600 text-sm font-medium">${item.description}</td>
                        </tr>`;
                    });
                }
                document.getElementById('auditLogsBody').innerHTML = html;
            } catch(e) { console.error('Error fetching system audits:', e); }
        }

        // Add a function to display current IST time in the header
        function updateISTClock() {
            const now = new Date();
            const istTime = now.toLocaleString('en-IN', {
                timeZone: 'Asia/Kolkata',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });
            
            // Update the refresh indicator title with current IST time
            const indicator = document.getElementById('refreshIndicator');
            if (indicator) {
                indicator.title = `Last refreshed at ${istTime} IST`;
            }
        }

        function fetchData() {
            document.getElementById('syncIcon').classList.add('fa-spin');
            Promise.all([fetchAnalytics(), fetchActiveStudents(), fetchSystemAudits()]).then(() => {
                setTimeout(() => {
                    document.getElementById('syncIcon').classList.remove('fa-spin');
                    updateISTClock();
                }, 500);
            });
        }

        let searchTimeout;
        function debounceSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentSearch = document.getElementById('searchInput').value;
                currentPage = 1;
                fetchActiveStudents();
            }, 500);
        }

        function prevPage() { if(currentPage > 1) { currentPage--; fetchActiveStudents(); } }
        function nextPage() { currentPage++; fetchActiveStudents(); }

        // Initialization
        document.addEventListener('DOMContentLoaded', () => {
            initCharts();
            fetchData();
            
            // Update IST clock immediately and then every 30 seconds
            updateISTClock();
            setInterval(updateISTClock, 30000);
            
            // Auto refresh every 10 seconds
            setInterval(fetchData, 10000);
        });

    </script>
</body>
</html>