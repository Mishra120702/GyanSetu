import re

with open('c:/xampp/htdocs/version3/dash_t/courses/course_attendance.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Replace the flex container
content = content.replace('<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300">', '<div class="page-shell ml-0 lg:ml-64 transition-all duration-300 min-h-screen" id="main-content">')

# Remove the old header
content = re.sub(r'<!-- Header -->.*?</header>', r'''
        <!-- Mobile Header -->
        <div class="lg:hidden sticky top-0 z-40 bg-white/90 backdrop-blur-xl border-b border-slate-200 px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <button id="mobileSidebarToggle" class="p-2 text-slate-600 hover:bg-slate-100 rounded-xl transition-colors">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <div>
                    <h1 class="text-lg font-black gradient-text">Course Attendance</h1>
                </div>
            </div>
        </div>

        <!-- Desktop Header -->
        <header class="hidden lg:block bg-white/80 backdrop-blur-xl border-b border-slate-200 sticky top-0 z-40">
            <div class="px-8 py-4 flex justify-between items-center">
                <div class="flex items-center gap-3">
                    <div class="h-11 w-11 rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-500 flex items-center justify-center text-white shadow-card">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-black gradient-text">Course Attendance</h1>
                        <p class="text-slate-500 text-sm">Track presence for <?= htmlspecialchars() ?> - <?= htmlspecialchars() ?></p>
                    </div>
                </div>
                <div>
                    <a href="my_courses.php?batch_id=<?= urlencode() ?>&course_id=<?= urlencode() ?>" class="btn-soft text-slate-700 bg-white shadow-sm hover:bg-slate-50 border border-slate-200 px-4 py-2 rounded-xl text-sm font-semibold flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Course
                    </a>
                </div>
            </div>
        </header>
''', content, flags=re.DOTALL)

# Update main container padding
content = content.replace('<div class="p-4 md:p-6">', '<main class="p-4 md:p-6 lg:p-8">')
content = content.replace('</div>\n    </div>\n</body>', '</main>\n    </div>\n</body>')

# Let's replace the Context Banner with the new Hero Section
content = re.sub(r'<!-- Context Banner -->.*?</div>\s*<!-- Manual Attendance Section -->', r'''
            <!-- Hero Section -->
            <section class="hero-card p-6 md:p-8 mb-5 md:mb-6">
                <div class="relative z-10 grid grid-cols-1 xl:grid-cols-3 gap-6 items-center">
                    <div class="xl:col-span-2">
                        <div class="flex flex-wrap gap-2 mb-4">
                            <span class="hero-pill"><i class="fas fa-book"></i> <?= htmlspecialchars() ?></span>
                            <span class="hero-pill"><i class="fas fa-users"></i> <?= htmlspecialchars() ?></span>
                        </div>
                        <h2 class="text-3xl md:text-4xl font-black tracking-tight mb-3">Course Attendance Workspace</h2>
                        <p class="text-white/85 max-w-3xl text-sm md:text-base leading-relaxed">
                            Mark manual attendance or import Excel sheets. Toggle between the tabs below.
                        </p>
                    </div>
                </div>
            </section>

            <!-- Manual Attendance Section -->
''', content, flags=re.DOTALL)

# Update the toggle buttons to action-btn styles
content = re.sub(r'<div class="toggle-buttons">.*?</div>', r'''
            <div class="flex flex-wrap gap-3 mb-6 justify-center">
                <button class="action-btn btn-purple tab-button" id="btnManual" onclick="toggleView('manual')">
                    <i class="fas fa-hand-pointer"></i> Manual Attendance
                </button>
                <button class="action-btn btn-soft text-slate-600 bg-white shadow-sm tab-button border border-slate-200" id="btnImport" onclick="toggleView('import')">
                    <i class="fas fa-file-excel"></i> Import Excel
                </button>
            </div>
''', content, flags=re.DOTALL)

# Replace cards
content = content.replace('class="card', 'class="glass-card')

# Update DataTables HTML styles
content = content.replace('class="data-table display w-full"', 'class="data-table display w-full" style="width: 100%;"')
content = content.replace('class="data-table display mt-4"', 'class="data-table display w-full mt-4"')

# Write back
with open('c:/xampp/htdocs/version3/dash_t/courses/course_attendance.php', 'w', encoding='utf-8') as f:
    f.write(content)
