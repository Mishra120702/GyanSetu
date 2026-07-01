<?php
// Ensure db_connection is included
if (!isset($db)) {
    require_once __DIR__ . '/../db_connection.php';
}

if (isset($_SESSION['user_id'])) {
    $n_user_id = $_SESSION['user_id'];
    $n_count_stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $n_count_stmt->execute([$n_user_id]);
    $unread_count = $n_count_stmt->fetchColumn();

    $n_stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $n_stmt->execute([$n_user_id]);
    $notifications = $n_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $unread_count = 0;
    $notifications = [];
}
?>

<style>
/* ===== Trainer Notification Theme Patch ===== */
/* CSS-only. DB fetch, unread count, mark read, mark all read, and JS behavior untouched. */

#trainer-notif-container {
    position: relative !important;
    z-index: 10000 !important;
}

#trainer-notif-container > button {
    width: 42px !important;
    height: 42px !important;
    border-radius: 999px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.46), transparent 34%),
        linear-gradient(135deg, rgba(255,255,255,.98), rgba(238,243,246,.88)) !important;
    border: 1.25px solid rgba(210,193,182,.72) !important;
    box-shadow:
        0 12px 28px rgba(27,60,83,.13),
        inset 0 1px 0 rgba(255,255,255,.92) !important;
    color: #1B3C53 !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

#trainer-notif-container > button:hover {
    transform: translateY(-2px) scale(1.04) !important;
    box-shadow:
        0 18px 36px rgba(27,60,83,.18),
        inset 0 1px 0 rgba(255,255,255,.94) !important;
    filter: brightness(1.03) !important;
}

#trainer-notif-container > button i {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

#trainer-notif-badge {
    top: -4px !important;
    right: -4px !important;
    width: 21px !important;
    height: 21px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border: 2px solid #fff !important;
    box-shadow: 0 8px 18px rgba(220,38,38,.35) !important;
    font-weight: 900 !important;
}

/* Dropdown box exactly like approved theme */
#trainer-notif-dropdown {
    width: 365px !important;
    right: 0 !important;
    margin-top: 12px !important;
    border-radius: 24px !important;
    overflow: hidden !important;
    border: 1.55px solid rgba(210,193,182,.72) !important;
    background:
        radial-gradient(circle at 94% 8%, rgba(69,104,130,.10), transparent 32%),
        radial-gradient(circle at 6% 94%, rgba(210,193,182,.22), transparent 34%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.92)) !important;
    box-shadow:
        0 30px 70px rgba(15,23,42,.28),
        inset 0 1px 0 rgba(255,255,255,.88) !important;
    backdrop-filter: blur(18px) !important;
    transform-origin: top right !important;
    z-index: 100000 !important;
}

/* Header */
#trainer-notif-dropdown > div:first-child {
    min-height: 62px !important;
    padding: 16px 18px !important;
    background:
        radial-gradient(circle at 92% 8%, rgba(255,255,255,.16), transparent 34%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    border-bottom: 1px solid rgba(255,255,255,.16) !important;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.16) !important;
}

#trainer-notif-dropdown > div:first-child h3 {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    font-size: 1.05rem !important;
    font-weight: 950 !important;
    letter-spacing: -.01em !important;
}

#trainer-notif-dropdown > div:first-child button {
    background: rgba(255,255,255,.18) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: 1.2px solid rgba(255,255,255,.38) !important;
    border-radius: 999px !important;
    padding: 7px 12px !important;
    font-size: .72rem !important;
    font-weight: 900 !important;
    text-decoration: none !important;
    box-shadow: 0 10px 22px rgba(0,0,0,.14) !important;
    transition: transform .18s ease, background .18s ease !important;
}

#trainer-notif-dropdown > div:first-child button:hover {
    transform: translateY(-1px) !important;
    background: rgba(255,255,255,.26) !important;
}

/* List */
#trainer-notif-list {
    max-height: 360px !important;
    background:
        linear-gradient(135deg, rgba(255,255,255,.94), rgba(247,242,238,.88)) !important;
    scrollbar-width: thin !important;
    scrollbar-color: #456882 rgba(238,243,246,.8) !important;
}

#trainer-notif-list::-webkit-scrollbar {
    width: 8px !important;
}

#trainer-notif-list::-webkit-scrollbar-track {
    background: rgba(238,243,246,.82) !important;
    border-radius: 999px !important;
}

#trainer-notif-list::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
    border-radius: 999px !important;
}

/* Each notification */
#trainer-notif-list > div[onclick] {
    padding: 16px 18px !important;
    border-bottom: 1px solid rgba(210,193,182,.52) !important;
    background:
        radial-gradient(circle at 96% 8%, rgba(69,104,130,.05), transparent 30%),
        linear-gradient(135deg, rgba(255,255,255,.80), rgba(238,243,246,.52)) !important;
    transition: transform .18s ease, background .18s ease, box-shadow .18s ease !important;
}

#trainer-notif-list > div[onclick]:hover {
    background:
        radial-gradient(circle at 96% 8%, rgba(69,104,130,.10), transparent 30%),
        linear-gradient(135deg, rgba(238,243,246,.94), rgba(247,242,238,.74)) !important;
    transform: translateX(4px) !important;
    box-shadow: inset 4px 0 0 rgba(35,76,106,.46) !important;
}

/* Unread item */
#trainer-notif-list > div.bg-blue-50\/30,
#trainer-notif-list > div[class*="bg-blue-50"] {
    background:
        radial-gradient(circle at 96% 10%, rgba(37,99,235,.09), transparent 30%),
        linear-gradient(135deg, rgba(238,243,246,.96), rgba(255,255,255,.86)) !important;
}

/* Icon bubble */
#trainer-notif-list .mt-1.mr-3 {
    margin-top: 2px !important;
    margin-right: 14px !important;
}

#trainer-notif-list .mt-1.mr-3 i {
    width: 42px !important;
    height: 42px !important;
    min-width: 42px !important;
    min-height: 42px !important;
    border-radius: 999px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    border: 1.3px solid rgba(255,255,255,.45) !important;
    box-shadow:
        0 12px 24px rgba(27,60,83,.20),
        inset 0 1px 0 rgba(255,255,255,.24) !important;
}

/* Notification text */
#trainer-notif-list p.text-sm {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-size: .92rem !important;
    line-height: 1.25 !important;
    font-weight: 950 !important;
    letter-spacing: -.01em !important;
}

#trainer-notif-list p.text-xs {
    color: #456882 !important;
    -webkit-text-fill-color: #456882 !important;
    font-size: .78rem !important;
    line-height: 1.45 !important;
    font-weight: 650 !important;
}

#trainer-notif-list p.text-\[10px\] {
    color: #234C6A !important;
    -webkit-text-fill-color: #234C6A !important;
    font-size: .70rem !important;
    font-weight: 900 !important;
}

/* Unread dot */
#trainer-notif-list .w-2.h-2.bg-blue-500.rounded-full {
    width: 11px !important;
    height: 11px !important;
    min-width: 11px !important;
    border-radius: 999px !important;
    background: #1B3C53 !important;
    box-shadow:
        0 0 0 5px rgba(27,60,83,.13),
        0 8px 16px rgba(27,60,83,.20) !important;
}

/* Empty state */
#trainer-notif-list .text-center {
    padding: 34px 20px !important;
}

#trainer-notif-list .fa-bell-slash {
    width: 58px !important;
    height: 58px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.40), transparent 34%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    box-shadow: 0 14px 28px rgba(27,60,83,.17) !important;
}

#trainer-notif-list .text-center p {
    color: #456882 !important;
    font-weight: 850 !important;
}

/* Make faded read notifications still readable */
#trainer-notif-list .opacity-75 {
    opacity: .88 !important;
}

/* Small screen safety */
@media (max-width: 480px) {
    #trainer-notif-dropdown {
        width: min(92vw, 360px) !important;
        right: -8px !important;
        border-radius: 20px !important;
    }
}
</style>

<div class="relative" id="trainer-notif-container">
    <button onclick="toggleTrainerNotifs()" class="relative p-2 text-gray-600 hover:text-indigo-600 focus:outline-none transition-colors">
        <i class="fas fa-bell text-xl"></i>
        <?php if ($unread_count > 0): ?>
            <span class="absolute top-0 right-0 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center font-bold" id="trainer-notif-badge">
                <?= $unread_count > 99 ? '99+' : $unread_count ?>
            </span>
        <?php endif; ?>
    </button>

    <div id="trainer-notif-dropdown" class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-2xl overflow-hidden z-50 border border-gray-100 hidden transform origin-top-right transition-all duration-200 scale-95 opacity-0">
        <div class="bg-indigo-600 p-4 text-white flex justify-between items-center">
            <h3 class="font-bold">Notifications</h3>
            <?php if ($unread_count > 0): ?>
                <button onclick="markAllTrainerNotifsRead()" class="text-xs hover:underline text-indigo-100">Mark all read</button>
            <?php endif; ?>
        </div>

        <div class="max-h-80 overflow-y-auto" id="trainer-notif-list">
            <?php if (empty($notifications)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-bell-slash text-3xl mb-2 text-gray-300"></i>
                    <p>No notifications yet</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notif): ?>
                    <div class="p-4 border-b border-gray-50 hover:bg-gray-50 transition-colors <?= $notif['is_read'] ? 'opacity-75' : 'bg-blue-50/30' ?> cursor-pointer" onclick="markTrainerNotifRead(<?= $notif['id'] ?>, this)">
                        <div class="flex items-start">
                            <div class="mt-1 mr-3">
                                <?php if ($notif['type'] == 'course_assignment'): ?>
                                    <i class="fas fa-book text-indigo-500"></i>
                                <?php elseif ($notif['type'] == 'message'): ?>
                                    <i class="fas fa-envelope text-blue-500"></i>
                                <?php else: ?>
                                    <i class="fas fa-bell text-gray-500"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($notif['title']) ?></p>
                                <p class="text-xs text-gray-600 mt-1"><?= htmlspecialchars($notif['message']) ?></p>
                                <p class="text-[10px] text-gray-400 mt-2"><?= date('M d, h:i A', strtotime($notif['created_at'])) ?></p>
                            </div>
                            <?php if (!$notif['is_read']): ?>
                                <div class="w-2 h-2 bg-blue-500 rounded-full mt-2"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleTrainerNotifs() {
    const dropdown = document.getElementById('trainer-notif-dropdown');
    if (dropdown.classList.contains('hidden')) {
        dropdown.classList.remove('hidden');
        setTimeout(() => {
            dropdown.classList.remove('scale-95', 'opacity-0');
            dropdown.classList.add('scale-100', 'opacity-100');
        }, 10);
    } else {
        dropdown.classList.remove('scale-100', 'opacity-100');
        dropdown.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            dropdown.classList.add('hidden');
        }, 200);
    }
}

// Close when clicking outside
document.addEventListener('click', function(event) {
    const container = document.getElementById('trainer-notif-container');
    const dropdown = document.getElementById('trainer-notif-dropdown');
    if (container && !container.contains(event.target)) {
        if (!dropdown.classList.contains('hidden')) {
            toggleTrainerNotifs();
        }
    }
});

async function markTrainerNotifRead(id, element) {
    // Implement read functionality using an API if you want
    // For now, we'll just visually mark it
    element.classList.remove('bg-blue-50/30');
    element.classList.add('opacity-75');
    const dot = element.querySelector('.bg-blue-500.rounded-full');
    if (dot) dot.remove();

    // Decrement badge
    const badge = document.getElementById('trainer-notif-badge');
    if (badge) {
        let count = parseInt(badge.innerText);
        if (!isNaN(count) && count > 0) {
            count--;
            if (count === 0) {
                badge.remove();
            } else {
                badge.innerText = count;
            }
        }
    }

    // Call backend to update
    try {
        const fd = new FormData();
        fd.append('notif_id', id);
        await fetch('<?= strpos($_SERVER["PHP_SELF"], "dashboard") !== false ? "../" : "../../dash_t/" ?>mark_notif_read.php', {
            method: 'POST',
            body: fd
        });
    } catch(e) {}
}

async function markAllTrainerNotifsRead() {
    document.querySelectorAll('#trainer-notif-list .bg-blue-50\\/30').forEach(el => {
        el.classList.remove('bg-blue-50/30');
        el.classList.add('opacity-75');
        const dot = el.querySelector('.bg-blue-500.rounded-full');
        if (dot) dot.remove();
    });

    const badge = document.getElementById('trainer-notif-badge');
    if (badge) badge.remove();

    try {
        await fetch('<?= strpos($_SERVER["PHP_SELF"], "dashboard") !== false ? "../" : "../../dash_t/" ?>mark_notif_read.php', {
            method: 'POST',
            body: new FormData()
        });
    } catch(e) {}
}
</script>
