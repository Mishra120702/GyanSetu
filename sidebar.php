<?php
// session_start(); // Ensure session is started
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
?>

<?php
/* ── Active-state helpers ─────────────────────────────────────────── */
$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['PHP_SELF'];

/**
 * Returns the CSS class string for a sidebar <a> element.
 * $match  – boolean: is this link currently active?
 */
function sb_link(bool $match): string {
    return $match
        ? 'sidebar-link sidebar-link--active'
        : 'sidebar-link';
}

/**
 * Returns the CSS class string for the icon wrapper <div>.
 */
function sb_icon(bool $match): string {
    return $match
        ? 'sb-icon sb-icon--active'
        : 'sb-icon';
}
?>

<!-- ═══════════════════════════════════════════════════════════════════
     SIDEBAR – ASD Admin Portal
     Theme:  Primary #1B3C53 · Secondary #234C6A · Accent #456882 · Light #D2C1B6
     ═══════════════════════════════════════════════════════════════════ -->
<div id="sidebar"
     class="w-64 h-screen fixed left-0 top-0 flex flex-col z-40
            transform -translate-x-full md:translate-x-0
            transition-transform duration-300 ease-in-out
            sb-root">

    <!-- ── HEADER ──────────────────────────────────────────────────── -->
    <div class="sb-header flex-shrink-0 flex items-center justify-between px-5 py-4">

        <a href="../dashboard/dashboard.php" class="flex items-center gap-3 group min-w-0">
            <!-- Logo mark -->
            <div class="sb-logo-mark flex-shrink-0 w-9 h-9 rounded-xl flex items-center justify-center">
                <i class="fas fa-graduation-cap text-[#D2C1B6] text-base"></i>
            </div>
            <!-- Brand text -->
            <div class="min-w-0">
                <div class="sb-brand-name leading-none truncate">ASD Admin</div>
                <div class="sb-brand-sub leading-none mt-0.5">Portal</div>
            </div>
        </a>

        <!-- Mobile close -->
        <button id="sidebarToggle"
                onclick="toggleSidebar()"
                class="md:hidden sb-close-btn flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center">
            <i class="fas fa-times text-sm"></i>
        </button>
    </div>

    <!-- ── NAV ─────────────────────────────────────────────────────── -->
    <nav id="sidebarNav" class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-2 scrollbar-custom">

        <!-- ┌── Dashboard ─────────────────────────────────────────── -->
        <?php $m = ($current_page === 'dashboard.php'); ?>
        <a href="../dashboard/dashboard.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-tachometer-alt"></i></div>
            <span>Dashboard</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = ($current_page === 'analytics.php'); ?>
        <a href="../dashboard/analytics.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-chart-pie"></i></div>
            <span>Analytics</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <!-- ┌── Section: User Management ──────────────────────────── -->
        <div class="sb-section-label">
            <span class="sb-section-line"></span>
            <span class="sb-section-text">User Management</span>
            <span class="sb-section-line"></span>
        </div>

        <?php $m = ($current_page === 'batch_list.php'); ?>
        <a href="../batch/batch_list.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-users"></i></div>
            <span>Batch Management</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = (strpos($current_path, 'trainers') !== false); ?>
        <a href="../trainers/index.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-chalkboard-teacher"></i></div>
            <span>Trainers</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = ($current_page === 'students_list.php'); ?>
        <a href="../student/students_list.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-user-graduate"></i></div>
            <span>Student Management</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <!-- ┌── Section: Academic ─────────────────────────────────── -->
        <div class="sb-section-label">
            <span class="sb-section-line"></span>
            <span class="sb-section-text">Academic</span>
            <span class="sb-section-line"></span>
        </div>

        <?php $m = (strpos($current_path, 'courses') !== false); ?>
        <a href="../courses/index.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-book-open"></i></div>
            <span>Courses</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = ($current_page === 'attendance.php'); ?>
        <a href="../attendance/attendance.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-clipboard-check"></i></div>
            <span>Attendance</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = ($current_page === 'exams.php'); ?>
        <a href="../exam/exams.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-file-alt"></i></div>
            <span>Exams</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = (strpos($current_path, 'leave_management.php') !== false); ?>
        <a href="../leaves/leave_management.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-calendar-alt"></i></div>
            <span>Leaves</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = (strpos($current_path, 'workshops') !== false); ?>
        <a href="../workshops/workshop_list.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-tools"></i></div>
            <span>Workshops</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = ($current_page === 'admin_dashboard.php'); ?>
        <a href="../admin_test/admin_dashboard.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-vial"></i></div>
            <span>Tests</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <!-- ┌── Section: Communication ────────────────────────────── -->
        <div class="sb-section-label">
            <span class="sb-section-line"></span>
            <span class="sb-section-text">Communication</span>
            <span class="sb-section-line"></span>
        </div>

        <a href="#" onclick="openAdminNotificationModal(event)" class="sidebar-link">
            <div class="sb-icon"><i class="fas fa-bullhorn"></i></div>
            <span>Send Notification</span>
        </a>

        <?php $m = (strpos($current_path, 'chat') !== false); ?>
        <a href="../chat/index.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-comments"></i></div>
            <span>Chat</span>
            <span class="sb-badge">New</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = ($current_page === 'feedback.php'); ?>
        <a href="../feedback/feedback.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-comment-dots"></i></div>
            <span>Feedback</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = ($current_page === 'tickets.php'); ?>
        <a href="../dashboard/tickets.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-ticket-alt"></i></div>
            <span>Student Tickets</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = ($current_page === 'trainer_tickets.php'); ?>
        <a href="../dashboard/trainer_tickets.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-chalkboard-teacher"></i></div>
            <span>Trainer Tickets</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <!-- ┌── Section: Financial ────────────────────────────────── -->
        <div class="sb-section-label">
            <span class="sb-section-line"></span>
            <span class="sb-section-text">Financial</span>
            <span class="sb-section-line"></span>
        </div>

        <?php $m = ($current_page === 'payment_dash.php'); ?>
        <a href="../payment/payment_dash.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-credit-card"></i></div>
            <span>Payments</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = ($current_page === 'sales.php'); ?>
        <a href="../sales/sales.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-chart-line"></i></div>
            <span>Sales</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <!-- ┌── Section: Support ──────────────────────────────────── -->
        <div class="sb-section-label">
            <span class="sb-section-line"></span>
            <span class="sb-section-text">Support &amp; Help</span>
            <span class="sb-section-line"></span>
        </div>

        <?php $m = ($current_page === 'manage_faqs.php'); ?>
        <a href="../content/manage_faqs.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-question-circle"></i></div>
            <span>Manage FAQs</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <!-- ┌── Section: System ───────────────────────────────────── -->
        <div class="sb-section-label">
            <span class="sb-section-line"></span>
            <span class="sb-section-text">System</span>
            <span class="sb-section-line"></span>
        </div>

        <?php $m = (strpos($current_path, 'reports') !== false); ?>
        <a href="../reports/index.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-chart-bar"></i></div>
            <span>Reporting</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = (strpos($current_path, 'activity_logs') !== false); ?>
        <a href="../activity_logs/student_activity.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-satellite-dish"></i></div>
            <span>Live Logs</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <?php $m = ($current_page === 'admin_settings.php'); ?>
        <a href="../settings/admin_settings.php" class="<?= sb_link($m) ?>">
            <div class="<?= sb_icon($m) ?>"><i class="fas fa-cog"></i></div>
            <span>Settings</span>
            <?php if ($m): ?><div class="sb-active-bar"></div><?php endif; ?>
        </a>

        <!-- ┌── Logout ────────────────────────────────────────────── -->
        <div class="sb-divider-rule"></div>

        <a href="../logout_a.php" class="sidebar-link sidebar-link--logout">
            <div class="sb-icon sb-icon--logout"><i class="fas fa-sign-out-alt"></i></div>
            <span>Logout</span>
        </a>

        <!-- bottom breathing room -->
        <div class="h-4"></div>
    </nav>

    <!-- ── FOOTER ──────────────────────────────────────────────────── -->
    <div class="sb-footer flex-shrink-0 px-4 py-3 flex items-center gap-3">
        <!-- Avatar -->
        <div class="sb-footer-avatar flex-shrink-0 w-9 h-9 rounded-xl flex items-center justify-center">
            <i class="fas fa-user text-[#D2C1B6] text-sm"></i>
        </div>
        <!-- Meta -->
        <div class="flex-1 min-w-0">
            <div class="sb-footer-name truncate"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div>
            <div class="sb-footer-role">Administrator</div>
        </div>
        <!-- Online dot -->
        <div class="sb-online-wrap flex-shrink-0 flex items-center gap-1.5">
            <span class="sb-online-dot"></span>
            <span class="sb-online-label">Online</span>
        </div>
    </div>
</div>

<!-- ── Mobile Overlay ───────────────────────────────────────────────── -->
<div id="sidebarOverlay"
     class="fixed inset-0 bg-black/50 backdrop-blur-sm z-30 hidden md:hidden transition-opacity duration-300"
     onclick="toggleSidebar()"></div>

<!-- ══════════════════════════════════════════════════════════════════
     STYLES – fully self-contained, zero dependency on page theme
     All colours derived exclusively from the four brand tokens.
     ══════════════════════════════════════════════════════════════════ -->
<style>
/* ── Tokens ─────────────────────────────────────────────────────── */
:root {
    --sb-primary:   #1B3C53;
    --sb-secondary: #234C6A;
    --sb-accent:    #456882;
    --sb-light:     #D2C1B6;

    /* derived */
    --sb-border:          rgba(69, 104, 130, 0.40);
    --sb-text-muted:      rgba(210, 193, 182, 0.55);
    --sb-text-default:    rgba(210, 193, 182, 0.85);
    --sb-icon-bg:         rgba(35, 76, 106, 0.70);
    --sb-hover-bg:        rgba(35, 76, 106, 0.65);
    --sb-active-bg:       rgba(69, 104, 130, 0.55);
    --sb-active-glow:     rgba(69, 104, 130, 0.25);
    --sb-active-bar-col:  #D2C1B6;
    --sb-logout-col:      #e07070;
    --sb-logout-hover-bg: rgba(200, 80, 80, 0.12);
    --sb-online-col:      #5cbf82;
    --sb-badge-bg:        rgba(69, 104, 130, 0.70);
}

/* ── Root shell ─────────────────────────────────────────────────── */
.sb-root {
    background-color: var(--sb-primary);
    border-right: 1px solid var(--sb-border);
    box-shadow: 4px 0 24px rgba(0, 0, 0, 0.30);
}

/* ── Header ─────────────────────────────────────────────────────── */
.sb-header {
    background-color: var(--sb-primary);
    border-bottom: 1px solid var(--sb-border);
    min-height: 68px;
}

.sb-logo-mark {
    background: var(--sb-accent);
    box-shadow: 0 2px 8px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.08);
}

.sb-brand-name {
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: #ffffff;
    line-height: 1.1;
}

.sb-brand-sub {
    font-size: 0.65rem;
    font-weight: 500;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--sb-text-muted);
}

.sb-close-btn {
    color: var(--sb-text-muted);
    background: var(--sb-icon-bg);
    border: 1px solid var(--sb-border);
    transition: background 0.2s, color 0.2s;
}
.sb-close-btn:hover {
    background: var(--sb-accent);
    color: #fff;
}

/* ── Scrollbar ───────────────────────────────────────────────────── */
.scrollbar-custom {
    scrollbar-width: thin;
    scrollbar-color: var(--sb-accent) transparent;
}
.scrollbar-custom::-webkit-scrollbar          { width: 4px; }
.scrollbar-custom::-webkit-scrollbar-track    { background: transparent; }
.scrollbar-custom::-webkit-scrollbar-thumb    { background: var(--sb-accent); border-radius: 99px; }
.scrollbar-custom::-webkit-scrollbar-thumb:hover { background: var(--sb-light); }

/* ── Section labels ──────────────────────────────────────────────── */
.sb-section-label {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 20px 4px 6px;
}

.sb-section-line {
    flex: 1;
    height: 1px;
    background: var(--sb-border);
}

.sb-section-text {
    font-size: 0.60rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--sb-text-muted);
    white-space: nowrap;
    flex-shrink: 0;
}

/* ── Sidebar link – base ─────────────────────────────────────────── */
.sidebar-link {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border-radius: 10px;
    margin-bottom: 2px;
    color: var(--sb-text-default);
    font-size: 0.8275rem;
    font-weight: 500;
    text-decoration: none;
    overflow: hidden;
    transition:
        background 0.2s ease,
        color 0.2s ease,
        box-shadow 0.2s ease,
        transform 0.15s ease;
    cursor: pointer;
}

/* hover */
.sidebar-link:hover {
    background: var(--sb-hover-bg);
    color: #ffffff;
    transform: translateX(2px);
}

.sidebar-link:hover .sb-icon {
    background: var(--sb-accent);
    color: #ffffff;
}

/* ── Active link ─────────────────────────────────────────────────── */
.sidebar-link--active {
    background: var(--sb-active-bg);
    color: #ffffff;
    box-shadow:
        0 0 0 1px rgba(210,193,182,0.10),
        0 4px 16px var(--sb-active-glow);
    transform: translateX(2px);
}

.sidebar-link--active .sb-icon {
    background: var(--sb-accent);
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.25);
}

/* active left-edge bar */
.sb-active-bar {
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 60%;
    border-radius: 0 3px 3px 0;
    background: var(--sb-active-bar-col);
    box-shadow: 0 0 8px rgba(210,193,182,0.45);
}

/* ── Logout variant ──────────────────────────────────────────────── */
.sidebar-link--logout {
    color: var(--sb-logout-col);
    margin-top: 4px;
}
.sidebar-link--logout:hover {
    background: var(--sb-logout-hover-bg);
    color: var(--sb-logout-col);
    transform: translateX(2px);
}
.sidebar-link--logout:hover .sb-icon--logout {
    background: rgba(200,80,80,0.20);
    color: var(--sb-logout-col);
}

/* ── Icon wrapper ────────────────────────────────────────────────── */
.sb-icon {
    flex-shrink: 0;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    background: var(--sb-icon-bg);
    color: var(--sb-text-default);
    transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
}

.sb-icon--active {
    background: var(--sb-accent);
    color: #ffffff;
}

.sb-icon--logout {
    background: rgba(200,80,80,0.15);
    color: var(--sb-logout-col);
}

/* subtle icon scale on link hover */
.sidebar-link:hover .sb-icon {
    transform: scale(1.06);
}

/* ── Badge (e.g. "New" on Chat) ──────────────────────────────────── */
.sb-badge {
    margin-left: auto;
    flex-shrink: 0;
    font-size: 0.60rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    padding: 2px 7px;
    border-radius: 99px;
    background: var(--sb-badge-bg);
    color: var(--sb-light);
    border: 1px solid var(--sb-border);
}

/* ── Divider rule before logout ──────────────────────────────────── */
.sb-divider-rule {
    height: 1px;
    background: var(--sb-border);
    margin: 16px 4px 12px;
}

/* ── Footer ──────────────────────────────────────────────────────── */
.sb-footer {
    border-top: 1px solid var(--sb-border);
    background: rgba(0,0,0,0.15);
}

.sb-footer-avatar {
    background: var(--sb-accent);
    box-shadow: 0 2px 8px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.08);
}

.sb-footer-name {
    font-size: 0.8rem;
    font-weight: 600;
    color: #ffffff;
    line-height: 1.2;
}

.sb-footer-role {
    font-size: 0.68rem;
    font-weight: 400;
    color: var(--sb-text-muted);
    letter-spacing: 0.02em;
}

.sb-online-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--sb-online-col);
    box-shadow: 0 0 0 2px rgba(92,191,130,0.25);
    animation: sb-pulse 2.4s ease-in-out infinite;
}

.sb-online-label {
    font-size: 0.63rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--sb-online-col);
}

@keyframes sb-pulse {
    0%, 100% { box-shadow: 0 0 0 2px rgba(92,191,130,0.25); }
    50%       { box-shadow: 0 0 0 4px rgba(92,191,130,0.10); }
}

/* ── Ripple ──────────────────────────────────────────────────────── */
.sb-ripple {
    position: absolute;
    border-radius: 50%;
    background: rgba(210,193,182,0.18);
    pointer-events: none;
    transform: scale(0);
    transition: transform 0.45s ease, opacity 0.45s ease;
}

/* ── Sidebar transition ──────────────────────────────────────────── */
#sidebar {
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ── Responsive ──────────────────────────────────────────────────── */
@media (max-width: 767px) {
    #sidebar {
        box-shadow: 6px 0 32px rgba(0,0,0,0.35);
    }
}

@media (max-height: 600px) {
    #sidebarNav {
        max-height: calc(100vh - 120px);
    }
}
</style>

<script>
/* ── Toggle sidebar (mobile) ──────────────────────────────────────── */
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar && overlay) {
        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
    }
}

/* ── Close on resize to desktop ───────────────────────────────────── */
window.addEventListener('resize', function () {
    if (window.innerWidth >= 768) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.remove('-translate-x-full');
        if (overlay) overlay.classList.add('hidden');
    }
});

document.addEventListener('DOMContentLoaded', function () {

    /* ── User dropdown ───────────────────────────────────────────── */
    const userMenuButton = document.getElementById('userMenuButton');
    const userDropdown   = document.getElementById('userDropdown');
    if (userMenuButton && userDropdown) {
        userMenuButton.addEventListener('click', function (e) {
            e.stopPropagation();
            userDropdown.classList.toggle('hidden');
        });
        document.addEventListener('click', function () {
            userDropdown.classList.add('hidden');
        });
        userDropdown.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    }

    /* ── Dynamic nav height ──────────────────────────────────────── */
    const sidebarNav    = document.getElementById('sidebarNav');
    const sidebarHeader = document.querySelector('.sb-header');
    const sidebarFooter = document.querySelector('.sb-footer');

    function adjustSidebarHeight() {
        if (sidebarNav && sidebarHeader && sidebarFooter) {
            const used = sidebarHeader.offsetHeight + sidebarFooter.offsetHeight;
            sidebarNav.style.maxHeight = 'calc(100vh - ' + used + 'px)';
        }
    }
    adjustSidebarHeight();
    window.addEventListener('resize', adjustSidebarHeight);

    /* ── JS-side active class reinforcement ──────────────────────── */
    const currentPath = window.location.pathname;
    document.querySelectorAll('.sidebar-link').forEach(function (link) {
        const href = link.getAttribute('href');
        if (href && href !== '#' && currentPath.includes(href.replace('../', ''))) {
            link.classList.add('sidebar-link--active');
            const icon = link.querySelector('.sb-icon');
            if (icon) icon.classList.add('sb-icon--active');
        }
    });

    /* ── Keyboard shortcuts ──────────────────────────────────────── */
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.shiftKey && e.key === 'S') {
            e.preventDefault();
            const btn = document.getElementById('sidebarToggle');
            if (btn) btn.click();
        }
        if (e.key === 'Escape') {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && !sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.add('-translate-x-full');
                if (overlay) overlay.classList.add('hidden');
            }
        }
    });

    /* ── Ripple on click ─────────────────────────────────────────── */
    document.querySelectorAll('.sidebar-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            const rect   = this.getBoundingClientRect();
            const size   = Math.max(rect.width, rect.height);
            const ripple = document.createElement('span');
            ripple.className = 'sb-ripple';
            ripple.style.width  = size + 'px';
            ripple.style.height = size + 'px';
            ripple.style.left   = (e.clientX - rect.left - size / 2) + 'px';
            ripple.style.top    = (e.clientY - rect.top  - size / 2) + 'px';
            this.appendChild(ripple);
            requestAnimationFrame(function () {
                ripple.style.transform = 'scale(2.2)';
                ripple.style.opacity   = '0';
            });
            setTimeout(function () { ripple.remove(); }, 460);
        });
    });

    /* ── Smooth wheel on nav ─────────────────────────────────────── */
    if (sidebarNav) {
        sidebarNav.addEventListener('wheel', function (e) {
            if (e.deltaY !== 0) {
                this.scrollTop += e.deltaY;
                e.preventDefault();
            }
        }, { passive: false });
    }
});
</script>

<?php include __DIR__ . '/admin_notification_modal.php'; ?>