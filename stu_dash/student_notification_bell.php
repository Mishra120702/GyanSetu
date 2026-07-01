<?php 
$notif_suffix = uniqid(); 
if (!isset($notif_script_included)) {
    $notif_script_included = true;
    $include_script = true;
} else {
    $include_script = false;
}
?>
<!-- Notification Bell & Dropdown -->
<div class="relative inline-block text-left student-notification-container" id="studentNotificationContainer_<?= $notif_suffix ?>">
    <button onclick="toggleStudentNotifications('<?= $notif_suffix ?>')" id="studentNotifBell_<?= $notif_suffix ?>" class="p-2 rounded-full hover:bg-white/50 relative transition-all duration-200">
        <i class="fas fa-bell text-gray-600 text-xl"></i>
        <!-- Badge -->
        <span id="studentNotifBadge_<?= $notif_suffix ?>" class="student-notif-badge absolute top-0 right-0 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-red-600 rounded-full transform translate-x-1/4 -translate-y-1/4 hidden scale-0 transition-transform duration-300">
            0
        </span>
    </button>

    <!-- Dropdown -->
    <div id="studentNotifDropdown_<?= $notif_suffix ?>" class="absolute right-0 mt-2 w-96 sm:w-[28rem] md:w-[32rem] max-w-[95vw] bg-white rounded-2xl shadow-2xl z-50 border border-gray-100 hidden opacity-0 transform scale-95 transition-all duration-200 origin-top-right overflow-hidden">
        <div class="p-4 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-indigo-50 flex justify-between items-center">
            <h3 class="font-bold text-gray-800 flex items-center">
                <i class="fas fa-bell mr-2 text-blue-600"></i>
                Notifications
            </h3>
        </div>
        
        <div id="studentNotifList_<?= $notif_suffix ?>" class="student-notif-list max-h-96 md:max-h-[32rem] overflow-y-auto custom-scrollbar bg-gray-50/50">
            <!-- Notifications injected here -->
            <div class="p-6 text-center text-gray-500">
                <i class="fas fa-circle-notch fa-spin text-2xl text-blue-500 mb-2"></i>
                <p class="text-sm">Loading...</p>
            </div>
        </div>
        
        <div class="p-3 border-t border-gray-100 text-center bg-white cursor-pointer hover:bg-blue-50 transition-colors rounded-b-2xl" onclick="openAllNotificationsModal()">
            <span class="text-sm font-semibold text-blue-600">View All Notifications</span>
        </div>
    </div>
</div>

<?php if ($include_script): ?>

<!-- Full View Notifications Modal -->
<div id="allNotificationsModal" class="fixed inset-0 bg-black/60 z-[100] hidden flex items-center justify-center p-4 backdrop-blur-sm transition-opacity opacity-0" style="z-index: 9999; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); top: 0; left: 0; right: 0; bottom: 0; position: fixed;">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl h-[85vh] flex flex-col transform scale-95 transition-transform duration-300" style="max-height: 85vh; height: 85vh;">
        <div class="p-5 border-b border-gray-100 flex justify-between items-center bg-gradient-to-r from-blue-50 to-indigo-50 rounded-t-2xl flex-shrink-0">
            <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-bell text-blue-600"></i> All Notifications
            </h2>
            <button onclick="closeAllNotificationsModal()" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-200 text-gray-500 hover:text-gray-800 transition-colors focus:outline-none">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto overscroll-contain p-2 sm:p-6 custom-scrollbar bg-gray-50/50" id="allNotificationsList">
            <!-- Full notifications will be rendered here -->
        </div>
    </div>
</div>

<style>
    /* Custom scrollbar for dropdown */
    .student-notification-container .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }
    .student-notification-container .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }
    .student-notification-container .custom-scrollbar::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }
    
    .notif-item {
        transition: all 0.2s ease;
    }
    .notif-item:hover {
        background-color: #f8fafc;
    }
    .notif-unread {
        background-color: #eff6ff;
        border-left: 3px solid #3b82f6;
    }
</style>

<script>
    let notificationsLoaded = false;
    let cachedNotifications = [];
    let cachedUnreadCount = 0;

    function toggleStudentNotifications(suffix) {
        const dropdown = document.getElementById('studentNotifDropdown_' + suffix);
        if (!dropdown) return;
        
        const isHidden = dropdown.classList.contains('hidden');
        
        // Hide all other dropdowns
        document.querySelectorAll('[id^="studentNotifDropdown_"]').forEach(el => {
            if (el.id !== 'studentNotifDropdown_' + suffix) {
                el.classList.remove('opacity-100', 'scale-100');
                el.classList.add('opacity-0', 'scale-95');
                setTimeout(() => {
                    el.classList.add('hidden');
                }, 200);
            }
        });
        
        if (isHidden) {
            dropdown.classList.remove('hidden');
            setTimeout(() => {
                dropdown.classList.remove('opacity-0', 'scale-95');
                dropdown.classList.add('opacity-100', 'scale-100');
            }, 10);
            
            if (!notificationsLoaded) {
                fetchStudentNotifications(suffix);
            } else {
                renderNotificationsToSuffix(cachedNotifications, suffix);
            }
        } else {
            dropdown.classList.remove('opacity-100', 'scale-100');
            dropdown.classList.add('opacity-0', 'scale-95');
            setTimeout(() => {
                dropdown.classList.add('hidden');
            }, 200);
        }
    }

    // Close when clicking outside
    document.addEventListener('click', function(e) {
        document.querySelectorAll('.student-notification-container').forEach(container => {
            const dropdown = container.querySelector('[id^="studentNotifDropdown_"]');
            if (container && !container.contains(e.target) && dropdown && !dropdown.classList.contains('hidden')) {
                dropdown.classList.remove('opacity-100', 'scale-100');
                dropdown.classList.add('opacity-0', 'scale-95');
                setTimeout(() => {
                    dropdown.classList.add('hidden');
                }, 200);
            }
        });
    });

    async function fetchStudentNotifications(suffixToRender) {
        try {
            const response = await fetch('api_get_notifications.php?_t=' + new Date().getTime(), { cache: 'no-store' });
            const data = await response.json();
            
            if (data.success) {
                cachedNotifications = data.data;
                cachedUnreadCount = data.unread_count;
                
                if (suffixToRender) {
                    renderNotificationsToSuffix(data.data, suffixToRender);
                } else {
                    document.querySelectorAll('.student-notif-list').forEach(list => {
                        const id = list.id;
                        const sfx = id.split('_')[1];
                        if (sfx) renderNotificationsToSuffix(data.data, sfx);
                    });
                }
                
                updateAllBadges(data.unread_count);
                notificationsLoaded = true;
            }
        } catch (error) {
            console.error('Error fetching notifications:', error);
            document.querySelectorAll('.student-notif-list').forEach(list => {
                list.innerHTML = '<div class="p-6 text-center text-red-500"><p class="text-sm">Failed to load notifications</p></div>';
            });
        }
    }

    function renderNotificationsToSuffix(notifications, suffix) {
        const list = document.getElementById('studentNotifList_' + suffix);
        if (!list) return;
        
        if (!notifications || notifications.length === 0) {
            list.innerHTML = `
                <div class="p-8 text-center text-gray-500 flex flex-col items-center">
                    <div class="bg-gray-100 p-4 rounded-full mb-3">
                        <i class="fas fa-bell-slash text-3xl text-gray-400"></i>
                    </div>
                    <p class="text-sm font-medium text-gray-600">No notifications yet</p>
                </div>
            `;
            return;
        }

        let html = '';
        notifications.forEach(notif => {
            const isUnread = notif.is_read == 0;
            const bgClass = isUnread ? 'notif-unread' : '';
            
            // Icon logic
            let icon = 'fa-bell text-blue-500';
            let iconBg = 'bg-blue-100';
            if (notif.category === 'Exam') { icon = 'fa-file-alt text-purple-500'; iconBg = 'bg-purple-100'; }
            else if (notif.category === 'Class Update') { icon = 'fa-chalkboard-teacher text-green-500'; iconBg = 'bg-green-100'; }
            else if (notif.category === 'Event') { icon = 'fa-calendar-alt text-yellow-500'; iconBg = 'bg-yellow-100'; }
            else if (notif.category === 'Important') { icon = 'fa-exclamation-circle text-red-500'; iconBg = 'bg-red-100'; }
            else if (notif.category === 'CURRICULUM') { icon = 'fa-book-open text-indigo-500'; iconBg = 'bg-indigo-100'; }
            
            // Date formatting
            const dateObj = new Date(notif.created_at);
            const dateStr = dateObj.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' });

            let imageHtml = '';
            if (notif.image_path) {
                imageHtml = `
                    <div class="mt-4 relative rounded-xl overflow-hidden border border-gray-100 shadow-sm max-w-full">
                        <img src="../${notif.image_path}" alt="Notification Attachment" class="w-full h-auto object-cover max-h-64 rounded-xl">
                    </div>
                `;
            }
            
            // Verify/Reject buttons for CURRICULUM notifications
            let verifHtml = '';
            if (notif.category === 'CURRICULUM') {
                if (notif.response_action === 'verified') {
                    verifHtml = `
                        <div class="mt-3 flex gap-2 verif-btns-container">
                            <span class="text-sm font-bold text-green-600">
                                <i class="fas fa-check-circle mr-1"></i>You verified this topic
                            </span>
                        </div>
                    `;
                } else if (notif.response_action === 'rejected') {
                    verifHtml = `
                        <div class="mt-3 flex gap-2 verif-btns-container">
                            <span class="text-sm font-bold text-red-600">
                                <i class="fas fa-times-circle mr-1"></i>Marked as not covered
                            </span>
                        </div>
                    `;
                } else {
                    verifHtml = `
                        <div class="mt-3 flex gap-2 verif-btns-container">
                            <button onclick="respondToVerification(${notif.id}, 'verified', event, this)" 
                                    class="flex-1 bg-green-500 hover:bg-green-600 text-white text-xs font-bold py-1.5 px-3 rounded-lg transition-colors flex items-center justify-center gap-1">
                                <i class="fas fa-check"></i> Verified
                            </button>
                            <button onclick="respondToVerification(${notif.id}, 'rejected', event, this)" 
                                    class="flex-1 bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-1.5 px-3 rounded-lg transition-colors flex items-center justify-center gap-1">
                                <i class="fas fa-times"></i> Not Covered
                            </button>
                        </div>
                    `;
                }
            }

            html += `
                <div class="notif-item p-5 border-b border-gray-100 cursor-pointer ${bgClass}" onclick="markNotificationRead(${notif.id}, this)">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full ${iconBg} flex items-center justify-center mt-1 shadow-sm">
                            <i class="fas ${icon} text-lg"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start mb-1.5">
                                <h4 class="text-base font-bold text-gray-900 truncate pr-3 leading-tight">${notif.title}</h4>
                                <span class="text-sm font-medium text-gray-400 whitespace-nowrap">${dateStr}</span>
                            </div>
                            <p class="text-base text-gray-600 line-clamp-4 leading-relaxed">${notif.message}</p>
                            ${imageHtml}
                            <div class="mt-3 flex items-center gap-2">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-gray-100 text-gray-600 uppercase tracking-wide">
                                    ${notif.category}
                                </span>
                            </div>
                            ${verifHtml}
                        </div>
                    </div>
                </div>
            `;
        });
        
        list.innerHTML = html;
    }

    function updateAllBadges(count) {
        cachedUnreadCount = count;
        document.querySelectorAll('.student-notif-badge').forEach(badge => {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.remove('hidden');
                setTimeout(() => { badge.classList.remove('scale-0'); badge.classList.add('scale-100'); }, 10);
            } else {
                badge.classList.remove('scale-100');
                badge.classList.add('scale-0');
                setTimeout(() => { badge.classList.add('hidden'); }, 300);
            }
        });
    }

    async function markNotificationRead(id, element) {
        if (!element.classList.contains('notif-unread')) return;

        // Optimistically update UI
        element.classList.remove('notif-unread');
        
        let currentCount = cachedUnreadCount;
        if (!isNaN(currentCount) && currentCount > 0) {
            updateAllBadges(currentCount - 1);
        }
        
        // Update the cached notifications state so it's consistent if they open the other dropdown
        const cachedItem = cachedNotifications.find(n => n.id == id);
        if (cachedItem) cachedItem.is_read = 1;

        try {
            const formData = new FormData();
            formData.append('notification_id', id);
            
            await fetch('api_mark_notification_read.php', {
                method: 'POST',
                body: formData
            });
        } catch (error) {
            console.error('Error marking as read:', error);
            // Revert on error
            element.classList.add('notif-unread');
            if (cachedItem) cachedItem.is_read = 0;
            updateAllBadges(currentCount);
        }
    }

    async function respondToVerification(notifId, action, event, btnElement) {
        event.stopPropagation(); // Don't trigger markNotificationRead
        
        const btnGroup = btnElement.closest('.verif-btns-container');
        if (!btnGroup) return;
        
        // Get the notification's target_id (batch_id) to find matching verif record
        const notif = cachedNotifications.find(n => n.id == notifId);
        if (!notif) return;
        
        btnGroup.innerHTML = '<span class="text-xs text-gray-500 italic"><i class="fas fa-circle-notch fa-spin mr-1"></i>Saving...</span>';
        
        try {
            const fd = new FormData();
            fd.append('action', action);
            fd.append('notif_id', notifId);
            fd.append('batch_id', notif.target_id);
            
            const res = await fetch('topic_verify_action.php', { method: 'POST', body: fd });
            const data = await res.json();
            
            if (data.success) {
                btnGroup.innerHTML = action === 'verified'
                    ? '<span class="text-xs font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>You verified this topic</span>'
                    : '<span class="text-xs font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Marked as not covered</span>';
                // Mark notification as read
                markNotificationRead(notifId, btnGroup.closest('.notif-item'));
            } else {
                btnGroup.innerHTML = '<span class="text-xs text-red-500">Error. Try again.</span>';
            }
        } catch(e) {
            btnGroup.innerHTML = '<span class="text-xs text-red-500">Error. Try again.</span>';
        }
    }

    function openAllNotificationsModal() {
        // Hide dropdowns
        document.querySelectorAll('[id^="studentNotifDropdown_"]').forEach(el => {
            el.classList.remove('opacity-100', 'scale-100');
            el.classList.add('opacity-0', 'scale-95');
            setTimeout(() => {
                el.classList.add('hidden');
            }, 200);
        });

        const modal = document.getElementById('allNotificationsModal');
        if (modal) {
            document.body.style.overflow = 'hidden'; // Prevent body scroll
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modal.querySelector('div').classList.remove('scale-95');
                modal.querySelector('div').classList.add('scale-100');
            }, 10);
            renderAllNotifications();
        }
    }

    function closeAllNotificationsModal() {
        const modal = document.getElementById('allNotificationsModal');
        if (modal) {
            document.body.style.overflow = ''; // Restore body scroll
            modal.classList.add('opacity-0');
            modal.querySelector('div').classList.remove('scale-100');
            modal.querySelector('div').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }
    }

    function renderAllNotifications() {
        const list = document.getElementById('allNotificationsList');
        if (!list) return;
        
        if (!cachedNotifications || cachedNotifications.length === 0) {
            list.innerHTML = `
                <div class="p-12 text-center text-gray-500 flex flex-col items-center justify-center h-full min-h-[300px]">
                    <div class="bg-gray-200 p-6 rounded-full mb-4">
                        <i class="fas fa-bell-slash text-4xl text-gray-400"></i>
                    </div>
                    <p class="text-xl font-medium text-gray-700">No Notifications</p>
                    <p class="text-base mt-2 text-gray-500">You're all caught up!</p>
                </div>
            `;
            return;
        }
        
        let html = '<div class="space-y-4">';
        cachedNotifications.forEach(notif => {
            const date = new Date(notif.created_at);
            const dateStr = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' });
            
            const isRead = notif.is_read == 1;
            const bgClass = isRead ? 'bg-white border-gray-100' : 'notif-unread bg-blue-50 border-blue-200 shadow-md';
            
            let icon = 'fa-bell text-blue-500';
            let iconBg = 'bg-blue-100';
            if (notif.category === 'CURRICULUM') { icon = 'fa-book-open text-indigo-500'; iconBg = 'bg-indigo-100'; }
            else if (notif.category === 'SYSTEM') { icon = 'fa-cogs text-gray-500'; iconBg = 'bg-gray-100'; }
            
            let imageHtml = '';
            if (notif.image_path) {
                imageHtml = `
                    <div class="mt-4 relative rounded-xl overflow-hidden border border-gray-200 shadow-sm max-w-lg">
                        <img src="../${notif.image_path}" alt="Notification Attachment" class="w-full h-auto object-cover max-h-80 rounded-xl">
                    </div>
                `;
            }
            
            let verifHtml = '';
            if (notif.category === 'CURRICULUM') {
                if (notif.response_action === 'verified') {
                    verifHtml = `
                        <div class="mt-4 flex gap-2 verif-btns-container">
                            <span class="text-sm font-bold text-green-600 bg-green-50 px-3 py-1.5 rounded-lg border border-green-100">
                                <i class="fas fa-check-circle mr-1"></i>You verified this topic
                            </span>
                        </div>
                    `;
                } else if (notif.response_action === 'rejected') {
                    verifHtml = `
                        <div class="mt-4 flex gap-2 verif-btns-container">
                            <span class="text-sm font-bold text-red-600 bg-red-50 px-3 py-1.5 rounded-lg border border-red-100">
                                <i class="fas fa-times-circle mr-1"></i>Marked as not covered
                            </span>
                        </div>
                    `;
                } else {
                    verifHtml = `
                        <div class="mt-4 flex gap-3 verif-btns-container max-w-md">
                            <button onclick="respondToVerification(${notif.id}, 'verified', event, this)" 
                                    class="flex-1 bg-green-500 hover:bg-green-600 text-white text-sm font-bold py-2 px-4 rounded-xl transition-all shadow-sm hover:shadow flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-offset-1">
                                <i class="fas fa-check"></i> Verified
                            </button>
                            <button onclick="respondToVerification(${notif.id}, 'rejected', event, this)" 
                                    class="flex-1 bg-red-500 hover:bg-red-600 text-white text-sm font-bold py-2 px-4 rounded-xl transition-all shadow-sm hover:shadow flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-1">
                                <i class="fas fa-times"></i> Not Covered
                            </button>
                        </div>
                    `;
                }
            }

            html += `
                <div class="notif-item p-5 sm:p-6 rounded-2xl border transition-all duration-200 cursor-pointer ${bgClass}" onclick="markNotificationRead(${notif.id}, this)">
                    <div class="flex items-start gap-4 sm:gap-6">
                        <div class="flex-shrink-0 w-12 h-12 sm:w-14 sm:h-14 rounded-full ${iconBg} flex items-center justify-center shadow-sm">
                            <i class="fas ${icon} text-xl sm:text-2xl"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-2 gap-2">
                                <h4 class="text-lg font-bold text-gray-900 leading-tight">${notif.title}</h4>
                                <span class="text-sm font-medium text-gray-500 whitespace-nowrap bg-white/80 px-2.5 py-1 rounded-md border border-gray-100 shadow-sm">${dateStr}</span>
                            </div>
                            <p class="text-base text-gray-700 leading-relaxed">${notif.message}</p>
                            ${imageHtml}
                            <div class="mt-4 flex items-center gap-2">
                                <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-bold bg-white border border-gray-200 text-gray-600 uppercase tracking-wider shadow-sm">
                                    ${notif.category}
                                </span>
                            </div>
                            ${verifHtml}
                        </div>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        
        list.innerHTML = html;
    }

    // Fetch initial badge count on load
    document.addEventListener('DOMContentLoaded', () => {
        fetchStudentNotifications(null);
        
        // Move modal to body so it isn't affected by parent display states (e.g. md:hidden)
        const modal = document.getElementById('allNotificationsModal');
        if (modal) {
            document.body.appendChild(modal);
        }
    });
</script>
<?php endif; ?>
