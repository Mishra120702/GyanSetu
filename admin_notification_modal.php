<!-- Admin Notification Modal -->
<div id="adminNotificationModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center backdrop-blur-sm transition-opacity duration-300 opacity-0">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl mx-4 transform transition-transform duration-300 scale-95 overflow-hidden flex flex-col max-h-[90vh]">
        
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-indigo-50 flex justify-between items-center flex-shrink-0">
            <h3 class="text-xl font-bold text-gray-800 flex items-center">
                <div class="w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center mr-3 shadow-md">
                    <i class="fas fa-bullhorn text-sm"></i>
                </div>
                Send Notification
            </h3>
            <button onclick="closeAdminNotificationModal()" class="text-gray-400 hover:text-red-500 transition-colors focus:outline-none">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6 overflow-y-auto flex-1 custom-scrollbar">
            <form id="adminNotificationForm" onsubmit="submitAdminNotification(event)" enctype="multipart/form-data">
                
                <!-- Category -->
                <div class="mb-5">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Category <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <select id="notifCategory" name="category" required class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all text-gray-700 appearance-none">
                            <option value="">Select Category</option>
                            <option value="Announcement">Announcement</option>
                            <option value="Warning">Warning</option>
                            <option value="Information">Information</option>
                            <option value="Reminder">Reminder</option>
                        </select>
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-tags text-gray-400"></i>
                        </div>
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                        </div>
                    </div>
                </div>

                <!-- Target Type -->
                <div class="mb-5">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Target Audience <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <label class="cursor-pointer h-full">
                            <input type="radio" name="target_type" value="all" class="sr-only" checked onchange="handleTargetTypeChange()">
                            <div class="target-radio-card px-2 py-3 bg-white border border-gray-200 rounded-xl text-center text-gray-700 hover:bg-gray-50 transition-all font-medium text-xs flex flex-col items-center gap-1 h-full justify-center">
                                <i class="fas fa-globe text-lg"></i>
                                All Users
                            </div>
                        </label>
                        <label class="cursor-pointer h-full">
                            <input type="radio" name="target_type" value="batch" class="sr-only" onchange="handleTargetTypeChange()">
                            <div class="target-radio-card px-2 py-3 bg-white border border-gray-200 rounded-xl text-center text-gray-700 hover:bg-gray-50 transition-all font-medium text-xs flex flex-col items-center gap-1 h-full justify-center">
                                <i class="fas fa-users text-lg"></i>
                                Specific Batch
                            </div>
                        </label>
                        <label class="cursor-pointer h-full">
                            <input type="radio" name="target_type" value="course" class="sr-only" onchange="handleTargetTypeChange()">
                            <div class="target-radio-card px-2 py-3 bg-white border border-gray-200 rounded-xl text-center text-gray-700 hover:bg-gray-50 transition-all font-medium text-xs flex flex-col items-center gap-1 h-full justify-center">
                                <i class="fas fa-book-open text-lg"></i>
                                Specific Course
                            </div>
                        </label>
                        <label class="cursor-pointer h-full">
                            <input type="radio" name="target_type" value="trainers" class="sr-only" onchange="handleTargetTypeChange()">
                            <div class="target-radio-card px-2 py-3 bg-white border border-gray-200 rounded-xl text-center text-gray-700 hover:bg-gray-50 transition-all font-medium text-xs flex flex-col items-center gap-1 h-full justify-center">
                                <i class="fas fa-chalkboard-teacher text-lg"></i>
                                All Trainers
                            </div>
                        </label>
                        <label class="cursor-pointer h-full">
                            <input type="radio" name="target_type" value="trainer" class="sr-only" onchange="handleTargetTypeChange()">
                            <div class="target-radio-card px-2 py-3 bg-white border border-gray-200 rounded-xl text-center text-gray-700 hover:bg-gray-50 transition-all font-medium text-xs flex flex-col items-center gap-1 h-full justify-center">
                                <i class="fas fa-user-tie text-lg"></i>
                                Specific Trainer
                            </div>
                        </label>
                        <label class="cursor-pointer h-full">
                            <input type="radio" name="target_type" value="students" class="sr-only" onchange="handleTargetTypeChange()">
                            <div class="target-radio-card px-2 py-3 bg-white border border-gray-200 rounded-xl text-center text-gray-700 hover:bg-gray-50 transition-all font-medium text-xs flex flex-col items-center gap-1 h-full justify-center">
                                <i class="fas fa-user-graduate text-lg"></i>
                                Students Only
                            </div>
                        </label>
                        <label class="cursor-pointer h-full">
                            <input type="radio" name="target_type" value="student" class="sr-only" onchange="handleTargetTypeChange()">
                            <div class="target-radio-card px-2 py-3 bg-white border border-gray-200 rounded-xl text-center text-gray-700 hover:bg-gray-50 transition-all font-medium text-xs flex flex-col items-center gap-1 h-full justify-center">
                                <i class="fas fa-user text-lg"></i>
                                Specific Student
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Normal Target Selection Container (Batch/Course) -->
                <div id="dynamicTargetContainer" class="mb-5 hidden">
                    <label id="dynamicTargetLabel" class="block text-sm font-semibold text-gray-700 mb-2">Select Target <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <select id="targetId" name="target_id" class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all text-gray-700 appearance-none disabled:opacity-50 disabled:cursor-not-allowed">
                            <option value="">Select option...</option>
                        </select>
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i id="dynamicTargetIcon" class="fas fa-crosshairs text-gray-400"></i>
                        </div>
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                        </div>
                        <!-- Loading spinner -->
                        <div id="targetLoadingSpinner" class="absolute inset-y-0 right-8 flex items-center hidden">
                            <i class="fas fa-circle-notch fa-spin text-blue-500"></i>
                        </div>
                    </div>
                </div>

                <!-- Student Filter Container (Removed as requested) -->

                <!-- Title -->
                <div class="mb-5">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Notification Title <span class="text-red-500">*</span></label>
                    <input type="text" id="notifTitle" name="title" required placeholder="Enter title (e.g. Tomorrow's Class Cancelled)" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all text-gray-700">
                </div>

                <!-- Message -->
                <div class="mb-5">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Message <span class="text-red-500">*</span></label>
                    <textarea id="notifMessage" name="message" required rows="3" placeholder="Enter detailed notification message here..." class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all text-gray-700 resize-none"></textarea>
                </div>

                <!-- Image Upload -->
                <div class="mb-5">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Attach Image (Optional)</label>
                    <div class="flex items-center justify-center w-full">
                        <label for="notifImage" class="flex flex-col items-center justify-center w-full h-24 border-2 border-gray-300 border-dashed rounded-xl cursor-pointer bg-gray-50 hover:bg-gray-100 transition-colors">
                            <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                <i class="fas fa-cloud-upload-alt text-2xl text-gray-400 mb-2"></i>
                                <p class="mb-1 text-sm text-gray-500"><span class="font-semibold">Click to upload</span> or drag and drop</p>
                                <p class="text-xs text-gray-500">SVG, PNG, JPG or GIF (MAX. 2MB)</p>
                            </div>
                            <input id="notifImage" name="image" type="file" accept="image/*" class="hidden" onchange="previewImage(this)" />
                        </label>
                    </div>
                    <div id="imagePreviewContainer" class="hidden mt-3 relative inline-block">
                        <img id="imagePreview" src="#" alt="Preview" class="h-20 w-auto rounded-lg shadow-sm border border-gray-200 object-cover">
                        <button type="button" onclick="removeImage()" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 shadow-md">
                            <i class="fas fa-times text-xs"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Status Message -->
                <div id="notifStatusMessage" class="hidden mb-4 p-3 rounded-lg text-sm font-medium"></div>

            </form>
        </div>
        
        <!-- Modal Footer -->
        <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-end gap-3 flex-shrink-0">
            <button type="button" onclick="closeAdminNotificationModal()" class="px-5 py-2.5 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-200 transition-colors">
                Cancel
            </button>
            <button type="submit" form="adminNotificationForm" id="btnSubmitNotif" class="px-6 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-xl hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all shadow-md shadow-blue-500/30 flex items-center justify-center min-w-[120px]">
                <span><i class="fas fa-paper-plane mr-2"></i> Send</span>
                <i class="fas fa-circle-notch fa-spin ml-2 hidden" id="spinnerSubmitNotif"></i>
            </button>
        </div>
    </div>
</div>

<style>
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>

<script>
    const targetOptionsCache = { batch: null, course: null, trainer: null, student: null };
    let selectedStudentIds = new Set();

    function openAdminNotificationModal(e) {
        if(e) e.preventDefault();
        const modal = document.getElementById('adminNotificationModal');
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modal.querySelector('.transform').classList.remove('scale-95');
        }, 10);
        
        document.getElementById('adminNotificationForm').reset();
        removeImage();
        handleTargetTypeChange();
        hideStatusMessage();
    }

    function closeAdminNotificationModal() {
        const modal = document.getElementById('adminNotificationModal');
        modal.classList.add('opacity-0');
        modal.querySelector('.transform').classList.add('scale-95');
        setTimeout(() => { modal.classList.add('hidden'); }, 300);
    }

    function handleTargetTypeChange() {
        const targetType = document.querySelector('input[name="target_type"]:checked').value;
        const normalContainer = document.getElementById('dynamicTargetContainer');
        const select = document.getElementById('targetId');
        const label = document.getElementById('dynamicTargetLabel');
        const icon = document.getElementById('dynamicTargetIcon');
        
        // Manual Highlighting Logic
        document.querySelectorAll('input[name="target_type"]').forEach(radio => {
            const div = radio.nextElementSibling;
            if(radio.checked) {
                div.classList.add('bg-blue-50', 'border-blue-500', 'text-blue-700', 'ring-1', 'ring-blue-500');
                div.classList.remove('border-gray-200', 'text-gray-700', 'hover:bg-gray-50');
            } else {
                div.classList.remove('bg-blue-50', 'border-blue-500', 'text-blue-700', 'ring-1', 'ring-blue-500');
                div.classList.add('border-gray-200', 'text-gray-700', 'hover:bg-gray-50');
            }
        });

        // Hide initially
        normalContainer.classList.add('hidden');
        select.removeAttribute('required');

        if (targetType === 'batch' || targetType === 'course' || targetType === 'trainer' || targetType === 'student') {
            normalContainer.classList.remove('hidden');
            select.setAttribute('required', 'required');
            
            if(targetType === 'batch') {
                label.innerHTML = 'Select Batch <span class="text-red-500">*</span>';
                icon.className = 'fas fa-users text-gray-400';
            } else if(targetType === 'course') {
                label.innerHTML = 'Select Course <span class="text-red-500">*</span>';
                icon.className = 'fas fa-book-open text-gray-400';
            } else if(targetType === 'trainer') {
                label.innerHTML = 'Select Trainer <span class="text-red-500">*</span>';
                icon.className = 'fas fa-chalkboard-teacher text-gray-400';
            } else if(targetType === 'student') {
                label.innerHTML = 'Select Student <span class="text-red-500">*</span>';
                icon.className = 'fas fa-user-graduate text-gray-400';
            }
            
            fetchTargetOptions(targetType);
        }
    }

    async function fetchTargetOptions(type) {
        const select = document.getElementById('targetId');
        const spinner = document.getElementById('targetLoadingSpinner');
        
        select.innerHTML = '<option value="">Select option...</option>';
        select.disabled = true;
        spinner.classList.remove('hidden');

        try {
            if (!targetOptionsCache[type]) {
                const response = await fetch(`../dashboard/api_get_notification_targets.php?type=${type}`);
                const data = await response.json();
                if (data.success) {
                    targetOptionsCache[type] = data.data;
                }
            }

            if (type === 'batch') {
                populateSelect(select, targetOptionsCache[type], type);
            } else if (type === 'course') {
                populateSelect(select, targetOptionsCache[type], type);
            } else if (type === 'trainer') {
                populateSelect(select, targetOptionsCache[type], type);
            } else if (type === 'student') {
                populateSelect(select, targetOptionsCache[type], type);
            }
        } catch (error) {
            console.error('Error fetching targets:', error);
        } finally {
            select.disabled = false;
            spinner.classList.add('hidden');
        }
    }

    function populateSelect(select, data, type) {
        select.innerHTML = '<option value="">Select option...</option>';
        data.forEach(item => {
            const option = document.createElement('option');
            option.value = type === 'batch' ? item.batch_id : (type === 'student' ? item.student_id : item.id);
            option.textContent = type === 'batch' ? item.batch_name : (type === 'student' ? item.first_name + ' ' + item.last_name + (item.batch_name ? ' ('+item.batch_name+')' : '') : item.name);
            select.appendChild(option);
        });
    }

    // Unused filter functions removed

    function previewImage(input) {
        const container = document.getElementById('imagePreviewContainer');
        const preview = document.getElementById('imagePreview');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                container.classList.remove('hidden');
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function removeImage() {
        document.getElementById('notifImage').value = '';
        document.getElementById('imagePreviewContainer').classList.add('hidden');
        document.getElementById('imagePreview').src = '#';
    }

    function showStatusMessage(message, isError = false) {
        const statusDiv = document.getElementById('notifStatusMessage');
        statusDiv.textContent = message;
        statusDiv.className = `mb-4 p-3 rounded-lg text-sm font-medium ${isError ? 'bg-red-50 text-red-600 border border-red-200' : 'bg-green-50 text-green-600 border border-green-200'}`;
        statusDiv.classList.remove('hidden');
    }

    function hideStatusMessage() {
        document.getElementById('notifStatusMessage').classList.add('hidden');
    }

    async function submitAdminNotification(e) {
        e.preventDefault();
        
        const targetType = document.querySelector('input[name="target_type"]:checked').value;

        const form = document.getElementById('adminNotificationForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const submitBtn = document.getElementById('btnSubmitNotif');
        const spinner = document.getElementById('spinnerSubmitNotif');
        
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
        spinner.classList.remove('hidden');
        hideStatusMessage();

        const formData = new FormData(form);

        try {
            const response = await fetch('../dashboard/api_send_admin_notification.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (data.success) {
                showStatusMessage('Notification sent successfully!', false);
                form.reset();
                removeImage();
                setTimeout(() => { closeAdminNotificationModal(); }, 1500);
            } else {
                showStatusMessage(data.message || 'Failed to send notification', true);
            }
        } catch (error) {
            showStatusMessage('Network error. Please try again.', true);
        } finally {
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
            spinner.classList.add('hidden');
        }
    }
</script>
