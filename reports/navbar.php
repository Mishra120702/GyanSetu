<style>
/* === BRAND THEME ACCENTS FOR REPORTS === */
:root {
    --brand-darkest:  #1B3C53;
    --brand-dark:     #234C6A;
    --brand-mid:      #456882;
    --brand-light:    #A4C4D4;
    --brand-sand:     #D2C1B6;
    --brand-gradient: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%);
    --brand-accent-grad: linear-gradient(135deg, #456882 0%, #234C6A 100%);
}

/* Page content background overrides */
div.ml-64.p-8.min-h-screen,
div.ml-64.p-8.transition-all.duration-300 {
    background: linear-gradient(145deg, #e8eef3 0%, #d6e4ed 30%, #e4edf4 60%, #dce8f0 100%) !important;
}

/* Drifting background blobs brand tones */
.rpt-orb1 {
    background: radial-gradient(circle, rgba(27,60,83,0.1) 0%, transparent 70%) !important;
}
.rpt-orb2 {
    background: radial-gradient(circle, rgba(69,104,130,0.09) 0%, transparent 70%) !important;
}

/* Stat card gradients themed */
.stat-card-gradient {
    position: relative !important;
    padding: 1.5rem !important;
    color: white !important;
    overflow: hidden !important;
    border-radius: 24px !important;
    border: 1px solid rgba(255, 255, 255, 0.08) !important;
    transition: transform 0.4s ease, box-shadow 0.4s ease !important;
}
.stat-card-gradient:hover {
    transform: translateY(-5px) !important;
}

.stat-card-gradient.scg-blue {
    background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
    box-shadow: 0 12px 28px rgba(27, 60, 83, 0.22) !important;
}
.stat-card-gradient.scg-blue:hover {
    box-shadow: 0 20px 40px rgba(27, 60, 83, 0.4) !important;
}

.stat-card-gradient.scg-violet {
    background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
    box-shadow: 0 12px 28px rgba(35, 76, 106, 0.22) !important;
}
.stat-card-gradient.scg-violet:hover {
    box-shadow: 0 20px 40px rgba(35, 76, 106, 0.4) !important;
}

.stat-card-gradient.scg-pink {
    background: linear-gradient(135deg, #D2C1B6 0%, #b6876a 100%) !important;
    box-shadow: 0 12px 28px rgba(182, 135, 106, 0.22) !important;
}
.stat-card-gradient.scg-pink:hover {
    box-shadow: 0 20px 40px rgba(182, 135, 106, 0.4) !important;
}

.stat-card-gradient.scg-teal {
    background: linear-gradient(135deg, #2d7a8a 0%, #1B3C53 100%) !important;
    box-shadow: 0 12px 28px rgba(45, 122, 138, 0.22) !important;
}
.stat-card-gradient.scg-teal:hover {
    box-shadow: 0 20px 40px rgba(45, 122, 138, 0.4) !important;
}

.stat-card-gradient.scg-orange {
    background: linear-gradient(135deg, #b6876a 0%, #9c6f55 100%) !important;
    box-shadow: 0 12px 28px rgba(182, 135, 106, 0.22) !important;
}
.stat-card-gradient.scg-orange:hover {
    box-shadow: 0 20px 40px rgba(182, 135, 106, 0.4) !important;
}

/* Tab elements */
.koral-nav-active {
    background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
    color: white !important;
    box-shadow: 0 4px 12px rgba(27, 60, 83, 0.2) !important;
}
.koral-nav-inactive {
    background: rgba(255, 255, 255, 0.7) !important;
    color: #456882 !important;
    border: 1px solid rgba(27, 60, 83, 0.1) !important;
    border-bottom: none !important;
}
.koral-nav-inactive:hover {
    background: rgba(27, 60, 83, 0.05) !important;
    color: #1B3C53 !important;
}

/* Sub navigation tabs overrides */
.flex.flex-wrap.gap-2.mb-6 a.bg-blue-600,
.flex.flex-wrap.gap-2.mb-6 a.bg-green-600,
.flex.flex-wrap.gap-2.mb-6 a.bg-indigo-600,
.flex.flex-wrap.gap-2.mb-6 a.bg-purple-600,
.flex.flex-wrap.gap-2.mb-6 a.bg-orange-600 {
    background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
    color: white !important;
    box-shadow: 0 4px 12px rgba(27, 60, 83, 0.2) !important;
}
.flex.flex-wrap.gap-2.mb-6 a.bg-white {
    background: white !important;
    color: #456882 !important;
    box-shadow: 0 2px 4px rgba(27, 60, 83, 0.05) !important;
}
.flex.flex-wrap.gap-2.mb-6 a.bg-white:hover {
    background: rgba(27, 60, 83, 0.08) !important;
    color: #1B3C53 !important;
}

/* Card Header banner gradients */
div.bg-gradient-to-r.from-blue-600.to-indigo-600,
div.bg-gradient-to-br.from-blue-500.to-indigo-600 {
    background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
    box-shadow: 0 4px 12px rgba(27, 60, 83, 0.2) !important;
}

/* Form, badge, search accents */
.bg-blue-100 {
    background: rgba(35, 76, 106, 0.1) !important;
    color: #234C6A !important;
}
.text-blue-600 {
    color: #234C6A !important;
}
.bg-blue-50 {
    background: rgba(35, 76, 106, 0.05) !important;
    color: #234C6A !important;
}
.bg-gradient-to-r.from-blue-50.to-indigo-50 {
    background: linear-gradient(135deg, rgba(210, 193, 182, 0.1) 0%, rgba(27, 60, 83, 0.04) 100%) !important;
    border-color: rgba(27, 60, 83, 0.1) !important;
}

/* Inputs focus */
input#studentSearchInput:focus,
select:focus,
input[type="date"]:focus {
    border-color: #234C6A !important;
    box-shadow: 0 0 0 2px rgba(35, 76, 106, 0.2) !important;
}

/* Brand buttons styling */
.btn-brand-primary {
    background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
    color: white !important;
    box-shadow: 0 4px 12px rgba(27, 60, 83, 0.2) !important;
    border: none !important;
    transition: all 0.3s ease !important;
}
.btn-brand-primary:hover {
    background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
    color: white !important;
    box-shadow: 0 6px 16px rgba(27, 60, 83, 0.3) !important;
    transform: translateY(-1px) !important;
}

/* Apply filters button */
button[type="submit"].bg-gradient-to-r.from-blue-600.to-indigo-600 {
    background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
}
button[type="submit"].bg-gradient-to-r.from-blue-600.to-indigo-600:hover {
    background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
}

/* Glass panel */
.glass-panel {
    background: rgba(255, 255, 255, 0.85) !important;
    border: 1px solid rgba(27, 60, 83, 0.12) !important;
    box-shadow: 0 8px 24px rgba(27, 60, 83, 0.08) !important;
}

/* === BATCHES PAGE CARD OVERRIDES === */
:root {
    --primary: #234C6A !important;
    --primary-light: #456882 !important;
    --secondary: #1B3C53 !important;
    --gradient-primary: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
}

/* Batch card thumbnail and placeholders */
.batch-thumbnail-wrapper,
.thumbnail-placeholder {
    background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
}

/* Selected/Hover effect indicator */
.batch-card::before {
    background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
}

/* Stats section inside card */
.batch-stats {
    background: rgba(35, 76, 106, 0.06) !important;
}
.stat-value {
    color: #234C6A !important;
}

/* Status ongoing / badges */
.status-ongoing {
    background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
}
.status-upcoming {
    background: linear-gradient(135deg, #D2C1B6 0%, #b6876a 100%) !important;
    color: #1B3C53 !important;
}
.status-completed {
    background: linear-gradient(135deg, #9ca3af 0%, #4b5563 100%) !important;
}

/* Mode online badge */
.mode-online {
    background: rgba(35, 76, 106, 0.08) !important;
    color: #234C6A !important;
    border-color: rgba(35, 76, 106, 0.2) !important;
}
.mode-offline {
    background: rgba(210, 193, 182, 0.15) !important;
    color: #b6876a !important;
    border-color: rgba(210, 193, 182, 0.3) !important;
}

/* Enrollment bar background */
.enrollment-progress {
    background: rgba(35, 76, 106, 0.1) !important;
}
.enrollment-bar {
    background: linear-gradient(135deg, #456882 0%, #234C6A 100%) !important;
}

/* Mentor section border and avatar border */
.trainer-section {
    border-top-color: rgba(35, 76, 106, 0.1) !important;
}
.trainer-avatar {
    border-color: #234C6A !important;
}
.avatar-placeholder {
    background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
}

/* Batch ID color */
.batch-id {
    color: #456882 !important;
}

/* Icons */
.text-blue-500 {
    color: #456882 !important;
}

/* === CARD INNER TEXT & SUB-BOX GLASS CONTRAST CONTROLS === */
/* Card title header */
.stat-card-gradient.scg-blue h4.text-blue-900 {
    color: #ffffff !important;
    font-size: 1.15rem !important;
    font-weight: 700 !important;
    text-shadow: 0 1px 3px rgba(0,0,0,0.2) !important;
}

/* Card subtitle badge */
.stat-card-gradient.scg-blue span.text-blue-900 {
    background: rgba(255, 255, 255, 0.15) !important;
    color: #A4C4D4 !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
    font-weight: 600 !important;
}

/* User icon badge in card */
.stat-card-gradient.scg-blue div.bg-white\/40 {
    background: rgba(255, 255, 255, 0.15) !important;
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
}

/* Card inner stats grid blocks */
.stat-card-gradient.scg-blue .bg-white\/50 {
    background: rgba(255, 255, 255, 0.07) !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
    border-radius: 14px !important;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.06) !important;
    transition: all 0.3s ease !important;
}
.stat-card-gradient.scg-blue .bg-white\/50:hover {
    background: rgba(255, 255, 255, 0.12) !important;
    border-color: rgba(255, 255, 255, 0.2) !important;
}
/* Labels inside stats blocks */
.stat-card-gradient.scg-blue .text-blue-700 {
    color: #A4C4D4 !important;
    font-weight: 600 !important;
}
.stat-card-gradient.scg-blue .text-gray-800 {
    color: #ffffff !important;
    font-weight: 800 !important;
}

/* Passed stats block */
.stat-card-gradient.scg-blue .border-green-300 {
    border-color: rgba(34, 197, 94, 0.3) !important;
    background: rgba(34, 197, 94, 0.05) !important;
}
.stat-card-gradient.scg-blue .border-green-300:hover {
    background: rgba(34, 197, 94, 0.09) !important;
    border-color: rgba(34, 197, 94, 0.5) !important;
}
.stat-card-gradient.scg-blue .text-green-700 {
    color: #22c55e !important;
    font-weight: 800 !important;
}
.stat-card-gradient.scg-blue span.text-green-700 {
    color: #4ade80 !important;
    font-weight: 600 !important;
}

/* Failed stats block */
.stat-card-gradient.scg-blue .border-red-300 {
    border-color: rgba(239, 68, 68, 0.3) !important;
    background: rgba(239, 68, 68, 0.05) !important;
}
.stat-card-gradient.scg-blue .border-red-300:hover {
    background: rgba(239, 68, 68, 0.09) !important;
    border-color: rgba(239, 68, 68, 0.5) !important;
}
.stat-card-gradient.scg-blue .text-red-700 {
    color: #ef4444 !important;
    font-weight: 800 !important;
}
.stat-card-gradient.scg-blue span.text-red-700 {
    color: #fca5a5 !important;
    font-weight: 600 !important;
}

/* Bottom highest/lowest bar */
.stat-card-gradient.scg-blue .col-span-2.flex.justify-between.bg-white\/50 {
    background: rgba(0, 0, 0, 0.15) !important;
    border: none !important;
    border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
    border-radius: 14px !important;
}
.stat-card-gradient.scg-blue .text-gray-700 {
    color: #D2C1B6 !important;
    font-weight: 700 !important;
}
.stat-card-gradient.scg-blue .text-gray-500 {
    color: rgba(255, 255, 255, 0.5) !important;
    font-weight: 500 !important;
}

/* === SELECT REPORT TYPE NAVIGATION CARDS OVERRIDES === */
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a {
    border-radius: 12px !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

/* Inactive cards */
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-white {
    background: rgba(255, 255, 255, 0.7) !important;
    border: 1px solid rgba(27, 60, 83, 0.12) !important;
    box-shadow: 0 2px 8px rgba(27, 60, 83, 0.03) !important;
}
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-white:hover {
    background: white !important;
    border-color: #234C6A !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 8px 16px rgba(27, 60, 83, 0.08) !important;
}

/* Active cards */
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-blue-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-green-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-purple-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-orange-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-red-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-indigo-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-pink-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-teal-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-yellow-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-gray-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-rose-100,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-cyan-100 {
    background: linear-gradient(135deg, #234C6A 0%, #1B3C53 100%) !important;
    border-color: #1B3C53 !important;
    box-shadow: 0 8px 16px rgba(27, 60, 83, 0.2) !important;
}

/* Text and icons inside active cards */
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-blue-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-green-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-purple-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-orange-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-red-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-indigo-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-pink-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-teal-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-yellow-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-gray-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-rose-100 *,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-cyan-100 * {
    color: #ffffff !important;
}

/* Description texts inside active cards opacity */
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-blue-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-green-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-purple-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-orange-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-red-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-indigo-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-pink-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-teal-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-yellow-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-gray-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-rose-100 p,
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a.bg-cyan-100 p {
    color: rgba(255, 255, 255, 0.8) !important;
}

/* Icons inside inactive cards */
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a i {
    color: #456882 !important;
}
.grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.xl\:grid-cols-4.gap-4 a:hover i {
    color: #234C6A !important;
}

/* Border accents */
.border-indigo-500 {
    border-color: #234C6A !important;
}
.text-indigo-600 {
    color: #234C6A !important;
}
</style>

<div class="flex mb-6 border-b border-gray-200">
    <a href="index.php" class="px-4 py-2 font-medium text-sm rounded-t-lg mr-2 transition-all duration-300 <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'koral-nav-active' : 'koral-nav-inactive' ?>">
        <i class="fas fa-user mr-2"></i> Students
    </a>    
    <a href="trainers.php" class="px-4 py-2 font-medium text-sm rounded-t-lg mr-2 transition-all duration-300 <?= basename($_SERVER['PHP_SELF']) === 'trainers.php' ? 'koral-nav-active' : 'koral-nav-inactive' ?>">
        <i class="fas fa-chalkboard-teacher mr-2"></i> Teachers
    </a>
    <a href="batches.php" class="px-4 py-2 font-medium text-sm rounded-t-lg mr-2 transition-all duration-300 <?= basename($_SERVER['PHP_SELF']) === 'batches.php' ? 'koral-nav-active' : 'koral-nav-inactive' ?>">
        <i class="fas fa-users mr-2"></i> Batches
    </a>
    <a href="exam.php" class="px-4 py-2 font-medium text-sm rounded-t-lg mr-2 transition-all duration-300 <?= (basename($_SERVER['PHP_SELF']) === 'exam.php' || basename($_SERVER['PHP_SELF']) === 'exams.php') ? 'koral-nav-active' : 'koral-nav-inactive' ?>">
        <i class="fas fa-graduation-cap mr-2"></i> Exams
    </a>
    <a href="workshops.php" class="px-4 py-2 font-medium text-sm rounded-t-lg mr-2 transition-all duration-300 <?= basename($_SERVER['PHP_SELF']) === 'workshops.php' ? 'koral-nav-active' : 'koral-nav-inactive' ?>">
        <i class="fas fa-laptop-code mr-2"></i> Workshops
    </a>
    <a href="attendance.php" class="px-4 py-2 font-medium text-sm rounded-t-lg mr-2 transition-all duration-300 <?= basename($_SERVER['PHP_SELF']) === 'attendance.php' ? 'koral-nav-active' : 'koral-nav-inactive' ?>">
        <i class="fas fa-calendar-check mr-2"></i> Attendance
    </a>
    <a href="feedbacks.php" class="px-4 py-2 font-medium text-sm rounded-t-lg transition-all duration-300 <?= basename($_SERVER['PHP_SELF']) === 'feedbacks.php' ? 'koral-nav-active' : 'koral-nav-inactive' ?>">
        <i class="fas fa-comment-alt mr-2"></i> Feedbacks
    </a>
</div>