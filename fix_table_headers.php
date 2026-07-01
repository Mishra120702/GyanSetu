<?php
$file = 'attendance/course_attendance.php';
$content = file_get_contents($file);

// 1. Table headers HTML
$targetHeader = <<<HTML
                                    <th>Student ID</th>
                                    <th>Student Name</th>
                                    <th>Batch Name</th>
                                    <th>Course Name</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Camera</th>
                                    <th>Remarks</th>
HTML;

$replacementHeader = <<<HTML
                                    <th>Date</th>
                                    <th>Batch Name</th>
                                    <th>Course Name</th>
                                    <th>Student Name</th>
                                    <th>Status</th>
                                    <th>Camera</th>
HTML;

$content = str_replace($targetHeader, $replacementHeader, $content);

// 2. DataTables initialization JS
$targetJS = <<<JAVASCRIPT
            columns: [
                { data: 'student_id' },
                { data: 'student_name' },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return '<?= htmlspecialchars(\$batch_name_display) ?>';
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return '<?= htmlspecialchars(\$course_name) ?>';
                    }
                },
                { data: 'date' },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return `
                            <label class="switch status-slider">
                                <input type="checkbox" class="status-toggle" data-id="\${row.id}" \${row.status === 'Present' ? 'checked' : ''}>
                                <span class="slider">
                                    <span class="status-label status-present-label">P</span>
                                    <span class="status-label status-absent-label">A</span>
                                </span>
                            </label>
                        `;
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        const isPresent = row.status === 'Present';
                        const isCameraOn = row.camera_status === 'On';
                        const disabledClass = !isPresent ? 'disabled' : '';
                        
                        return `
                            <label class="switch camera-slider \${disabledClass}">
                                <input type="checkbox" class="camera-toggle" data-id="\${row.id}" 
                                    \${isCameraOn ? 'checked' : ''} 
                                    \${!isPresent ? 'disabled' : ''}>
                                <span class="slider"></span>
                            </label>
                        `;
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return `<input type="text" class="remarks-input minimal-input" data-id="\${row.id}" value="\${row.remarks || ''}" placeholder="Add remarks">`;
                    }
                }
            ],
JAVASCRIPT;

$replacementJS = <<<JAVASCRIPT
            columns: [
                { data: 'date' },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return '<?= htmlspecialchars(\$batch_name_display) ?>';
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return '<?= htmlspecialchars(\$course_name) ?>';
                    }
                },
                { data: 'student_name' },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return `
                            <label class="switch status-slider">
                                <input type="checkbox" class="status-toggle" data-id="\${row.id}" \${row.status === 'Present' ? 'checked' : ''}>
                                <span class="slider">
                                    <span class="status-label status-present-label">P</span>
                                    <span class="status-label status-absent-label">A</span>
                                </span>
                            </label>
                        `;
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        const isPresent = row.status === 'Present';
                        const isCameraOn = row.camera_status === 'On';
                        const disabledClass = !isPresent ? 'disabled' : '';
                        
                        return `
                            <label class="switch camera-slider \${disabledClass}">
                                <input type="checkbox" class="camera-toggle" data-id="\${row.id}" 
                                    \${isCameraOn ? 'checked' : ''} 
                                    \${!isPresent ? 'disabled' : ''}>
                                <span class="slider"></span>
                            </label>
                        `;
                    }
                }
            ],
JAVASCRIPT;

// Let's use preg_replace if there are any spacing differences
$content = preg_replace('/columns:\s*\[[\s\S]*?\]\,/m', $replacementJS, $content);

// Ensure the HTML header is also replaced correctly via regex in case spacing differed
$content = preg_replace(
    "/<th>Student ID<\/th>\s*<th>Student Name<\/th>\s*<th>Batch Name<\/th>\s*<th>Course Name<\/th>\s*<th>Date<\/th>\s*<th>Status<\/th>\s*<th>Camera<\/th>\s*<th>Remarks<\/th>/m",
    $replacementHeader,
    $content
);


file_put_contents($file, $content);
echo "Headers fixed.";
?>
