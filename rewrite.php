<?php
$file = 'dash_t/content/trainer_content.php';
$content = file_get_contents($file);

// Replace batch fetching with course fetching
$batch_fetch_orig = <<<EOT
// Get batches taught by this trainer
\$batches = \$db->prepare("
    SELECT batch_id, batch_name 
    FROM batches 
    WHERE batch_mentor_id = :trainer_id 
    ORDER BY batch_id ASC
");
\$batches->execute([':trainer_id' => \$trainer['id']]);
\$mentor_batches = \$batches->fetchAll(PDO::FETCH_ASSOC);
EOT;

$course_fetch_new = <<<EOT
// Get courses taught by this trainer
\$courses = \$db->prepare("
    SELECT DISTINCT c.id as course_id, c.name as course_name 
    FROM courses c
    JOIN batch_courses bc ON c.id = bc.course_id
    JOIN batches b ON bc.batch_id = b.batch_id
    WHERE b.batch_mentor_id = :trainer_id AND b.status != 'completed'
    ORDER BY c.name ASC
");
\$courses->execute([':trainer_id' => \$trainer['id']]);
\$mentor_courses = \$courses->fetchAll(PDO::FETCH_ASSOC);
EOT;
$content = str_replace($batch_fetch_orig, $course_fetch_new, $content);

// In POST handler, add content_source and course_id
$post_orig = <<<EOT
    \$title = trim(\$_POST['title'] ?? '');
    \$description = trim(\$_POST['description'] ?? '');
    \$fileType = \$_POST['file_type'] ?? '';
    \$batchIds = \$_POST['batch_ids'] ?? [];
    \$due_date = !empty(\$_POST['due_date']) ? \$_POST['due_date'] : null;
    \$max_marks = !empty(\$_POST['max_marks']) ? floatval(\$_POST['max_marks']) : 100.00;
EOT;

$post_new = <<<EOT
    \$title = trim(\$_POST['title'] ?? '');
    \$description = trim(\$_POST['description'] ?? '');
    \$fileType = \$_POST['file_type'] ?? '';
    \$course_id = \$_POST['course_id'] ?? '';
    \$content_source = \$_POST['content_source'] ?? 'file';
    \$drive_link = trim(\$_POST['drive_link'] ?? '');
    \$due_date = !empty(\$_POST['due_date']) ? \$_POST['due_date'] : null;
    \$max_marks = !empty(\$_POST['max_marks']) ? floatval(\$_POST['max_marks']) : 100.00;
EOT;
$content = str_replace($post_orig, $post_new, $content);

$validations_orig = <<<EOT
    if (empty(\$batchIds)) {
        \$_SESSION['error'] = 'Please select at least one batch';
        header("Location: trainer_content.php");
        exit;
    }
EOT;

$validations_new = <<<EOT
    if (empty(\$course_id)) {
        \$_SESSION['error'] = 'Please select a course';
        header("Location: trainer_content.php");
        exit;
    }
    
    // Validate drive link if content source is drive
    if (\$content_source === 'drive') {
        if (empty(\$drive_link)) {
            \$_SESSION['error'] = 'Google Drive link is required';
            header("Location: trainer_content.php");
            exit;
        } elseif (!filter_var(\$drive_link, FILTER_VALIDATE_URL) || 
                  !preg_match('/drive\.google\.com/i', \$drive_link)) {
            \$_SESSION['error'] = 'Please enter a valid Google Drive link';
            header("Location: trainer_content.php");
            exit;
        }
    }
EOT;
$content = str_replace($validations_orig, $validations_new, $content);

$upload_handle_orig = <<<EOT
    // Handle file upload
    if (isset(\$_FILES['file']) && \$_FILES['file']['error'] === UPLOAD_ERR_OK) {
EOT;

$upload_handle_new = <<<EOT
    // For drive links, store the link directly
    if (\$content_source === 'drive') {
        \$filePath = \$drive_link;
        try {
            \$db->beginTransaction();
            
            // Insert upload record
            \$stmt = \$db->prepare("INSERT INTO uploads (title, description, file_path, file_type, due_date, max_marks, uploaded_by, content_source, course_id) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            \$stmt->execute([\$title, \$description, \$filePath, \$fileType, \$due_date, \$max_marks, \$trainer_id, \$content_source, \$course_id]);
            \$uploadId = \$db->lastInsertId();
            
            // Insert batch associations based on visibility (all batches for this course)
            \$stmt = \$db->prepare("
                INSERT INTO batch_uploads (upload_id, batch_id, course_id) 
                SELECT ?, batch_id, ? FROM batch_courses bc JOIN batches b ON bc.batch_id = b.batch_id WHERE bc.course_id = ? AND b.batch_mentor_id = ?
            ");
            \$stmt->execute([\$uploadId, \$course_id, \$course_id, \$trainer['id']]);
            
            \$db->commit();
            \$_SESSION['success'] = 'Content uploaded successfully';
        } catch (Exception \$e) {
            \$db->rollBack();
            \$_SESSION['error'] = 'Error: ' . \$e->getMessage();
        }
    } 
    // Handle file upload
    else if (isset(\$_FILES['file']) && \$_FILES['file']['error'] === UPLOAD_ERR_OK) {
EOT;
$content = str_replace($upload_handle_orig, $upload_handle_new, $content);

$insert_orig = <<<EOT
                // Insert upload record
                \$stmt = \$db->prepare("INSERT INTO uploads (title, description, file_path, file_type, due_date, max_marks, uploaded_by) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?)");
                \$stmt->execute([\$title, \$description, \$filePath, \$fileType, \$due_date, \$max_marks, \$trainer_id]);
                \$uploadId = \$db->lastInsertId();
                
                // Verify trainer has access to selected batches
                \$placeholders = implode(',', array_fill(0, count(\$batchIds), '?'));
                \$checkStmt = \$db->prepare("SELECT batch_id FROM batches WHERE batch_id IN (\$placeholders) AND batch_mentor_id = ?");
                \$checkParams = array_merge(\$batchIds, [\$trainer['id']]);
                \$checkStmt->execute(\$checkParams);
                \$validBatches = \$checkStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (count(\$validBatches) !== count(\$batchIds)) {
                    throw new Exception('You do not have permission to upload content to some selected batches');
                }
                
                // Insert batch associations
                \$stmt = \$db->prepare("INSERT INTO batch_uploads (upload_id, batch_id) VALUES (?, ?)");
                foreach (\$validBatches as \$batchId) {
                    \$stmt->execute([\$uploadId, \$batchId]);
                }
EOT;

$insert_new = <<<EOT
                // Insert upload record
                \$stmt = \$db->prepare("INSERT INTO uploads (title, description, file_path, file_type, due_date, max_marks, uploaded_by, content_source, course_id) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                \$stmt->execute([\$title, \$description, \$filePath, \$fileType, \$due_date, \$max_marks, \$trainer_id, 'file', \$course_id]);
                \$uploadId = \$db->lastInsertId();
                
                // Insert batch associations based on visibility (all batches for this course)
                \$stmt = \$db->prepare("
                    INSERT INTO batch_uploads (upload_id, batch_id, course_id) 
                    SELECT ?, batch_id, ? FROM batch_courses bc JOIN batches b ON bc.batch_id = b.batch_id WHERE bc.course_id = ? AND b.batch_mentor_id = ?
                ");
                \$stmt->execute([\$uploadId, \$course_id, \$course_id, \$trainer['id']]);
EOT;
$content = str_replace($insert_orig, $insert_new, $content);

// Form replacement
$form_batch_html = <<<EOT
                    <div class="md:col-span-2 space-y-1">
                        <label for="batch_ids" class="block text-sm font-medium text-gray-700 required-field">Associated Batch(es)</label>
                        <select id="batch_ids" name="batch_ids[]" multiple required
                                class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm sm:text-base">
                            <?php if (!empty(\$mentor_batches)): ?>
                                <?php foreach (\$mentor_batches as \$batch): ?>
                                    <option value="<?= htmlspecialchars(\$batch['batch_id']) ?>">
                                        <?= htmlspecialchars(\$batch['batch_id'] . ' - ' . \$batch['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No batches assigned to you</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="md:col-span-2 space-y-1">
                        <label class="block text-sm font-medium text-gray-700 required-field">File Upload</label>
                        <div id="fileDropArea" class="file-upload-container p-4 sm:p-8 text-center cursor-pointer">
                            <input type="file" id="file" name="file" required class="hidden"
                                   accept=".pdf,.doc,.docx">
                            <div class="flex flex-col items-center justify-center space-y-2">
                                <i class="fas fa-cloud-upload-alt text-3xl sm:text-4xl mb-2 bg-gradient-to-br from-indigo-500 to-purple-600 bg-clip-text text-transparent"></i>
                                <p class="text-xs sm:text-sm text-gray-600">Drag & drop your file here or click to browse</p>
                                <p class="text-xs text-gray-500 mt-1">Supports: PDF, DOC, DOCX (Max 10MB)</p>
                                <div id="fileNameDisplay" class="mt-2 text-sm font-medium text-indigo-600 hidden"></div>
                            </div>
                        </div>
                    </div>
EOT;

$form_new_html = <<<EOT
                    <div class="md:col-span-2 space-y-1">
                        <label for="course_id" class="block text-sm font-medium text-gray-700 required-field">Associated Course</label>
                        <select id="course_id" name="course_id" required
                                class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm sm:text-base">
                            <option value="">Select a course</option>
                            <?php if (!empty(\$mentor_courses)): ?>
                                <?php foreach (\$mentor_courses as \$course): ?>
                                    <option value="<?= htmlspecialchars(\$course['course_id']) ?>">
                                        <?= htmlspecialchars(\$course['course_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No courses assigned to you</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="md:col-span-2 space-y-2">
                        <label class="block text-sm font-medium text-gray-700 required-field">Content Source</label>
                        
                        <div class="flex gap-2 mb-2">
                            <div class="source-tab cursor-pointer px-4 py-2 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 font-medium active" onclick="switchSource('file')" id="tabFile">
                                <i class="fas fa-file-upload mr-1"></i> File Upload
                            </div>
                            <div class="source-tab cursor-pointer px-4 py-2 rounded-lg border border-gray-200 bg-white text-gray-600 font-medium" onclick="switchSource('drive')" id="tabDrive">
                                <i class="fab fa-google-drive mr-1"></i> Google Drive
                            </div>
                        </div>
                        <input type="hidden" name="content_source" id="contentSource" value="file">
                        
                        <div id="fileSourceContent" class="block">
                            <div id="fileDropArea" class="file-upload-container p-4 sm:p-8 text-center cursor-pointer border-2 border-dashed border-indigo-200 rounded-lg">
                                <input type="file" id="file" name="file" required class="hidden" accept=".pdf,.doc,.docx">
                                <div class="flex flex-col items-center justify-center space-y-2">
                                    <i class="fas fa-cloud-upload-alt text-3xl sm:text-4xl mb-2 bg-gradient-to-br from-indigo-500 to-purple-600 bg-clip-text text-transparent"></i>
                                    <p class="text-xs sm:text-sm text-gray-600">Drag & drop your file here or click to browse</p>
                                    <p class="text-xs text-gray-500 mt-1">Supports: PDF, DOC, DOCX (Max 10MB)</p>
                                    <div id="fileNameDisplay" class="mt-2 text-sm font-medium text-indigo-600 hidden"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="driveSourceContent" class="hidden">
                            <div class="p-4 bg-blue-50 rounded-lg border border-blue-100">
                                <label for="driveLink" class="block text-sm font-medium text-gray-700 mb-1 required-field">Google Drive Link</label>
                                <input type="url" name="drive_link" id="driveLink"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                       placeholder="https://drive.google.com/file/d/.../view?usp=sharing">
                                <p class="text-xs text-gray-500 mt-2">
                                    <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                                    Make sure the link is set to "Anyone with the link can view"
                                </p>
                            </div>
                        </div>
                    </div>
EOT;
$content = str_replace($form_batch_html, $form_new_html, $content);

// Add JavaScript for the tabs
$script_orig = <<<EOT
<script>
    // File upload drag and drop
    const dropArea = document.getElementById('fileDropArea');
EOT;

$script_new = <<<EOT
<script>
    function switchSource(source) {
        document.getElementById('contentSource').value = source;
        
        const fileContent = document.getElementById('fileSourceContent');
        const driveContent = document.getElementById('driveSourceContent');
        const fileTab = document.getElementById('tabFile');
        const driveTab = document.getElementById('tabDrive');
        const fileInput = document.getElementById('file');
        const driveInput = document.getElementById('driveLink');
        
        if (source === 'file') {
            fileContent.classList.remove('hidden');
            driveContent.classList.add('hidden');
            
            fileTab.classList.add('bg-indigo-50', 'text-indigo-700', 'border-indigo-200');
            fileTab.classList.remove('bg-white', 'text-gray-600', 'border-gray-200');
            
            driveTab.classList.remove('bg-indigo-50', 'text-indigo-700', 'border-indigo-200');
            driveTab.classList.add('bg-white', 'text-gray-600', 'border-gray-200');
            
            fileInput.required = true;
            driveInput.required = false;
        } else {
            fileContent.classList.add('hidden');
            driveContent.classList.remove('hidden');
            
            driveTab.classList.add('bg-indigo-50', 'text-indigo-700', 'border-indigo-200');
            driveTab.classList.remove('bg-white', 'text-gray-600', 'border-gray-200');
            
            fileTab.classList.remove('bg-indigo-50', 'text-indigo-700', 'border-indigo-200');
            fileTab.classList.add('bg-white', 'text-gray-600', 'border-gray-200');
            
            fileInput.required = false;
            driveInput.required = true;
        }
    }

    // File upload drag and drop
    const dropArea = document.getElementById('fileDropArea');
EOT;
$content = str_replace($script_orig, $script_new, $content);

file_put_contents($file, $content);
echo "Done";
?>
