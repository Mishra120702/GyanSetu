<?php
$file = 'c:\\xampp\\htdocs\\_public_html (4)\\stu_dash\\my_batches.php';
$content = file_get_contents($file);

// Replace space-y-8 back to grid
$content = str_replace('<div class="space-y-8">', '<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">', $content);

// Replace <div class="mb-2"> back to <div class="col-span-full mt-4 mb-2">
$content = str_replace('<div class="mb-2">', '<div class="col-span-full mt-4 mb-2">', $content);

// Replace <div class="mb-4"> back to <div class="col-span-full mb-4">
$content = str_replace('<div class="mb-4">', '<div class="col-span-full mb-4">', $content);

// Remove Horizontal Tab List and cards container logic
$tabs_start = '                            <!-- Horizontal Tab List -->';
$tabs_end = '                        <?php foreach ($topics as $index => $topic): ?>';

$search_str = '<?php else: ?>
' . $tabs_start;
$pos_start = strpos($content, $search_str);
if ($pos_start === false) {
    // try with \r\n
    $search_str = "<?php else: ?>\r\n" . $tabs_start;
    $pos_start = strpos($content, $search_str);
}

if ($pos_start !== false) {
    $pos_end = strpos($content, $tabs_end, $pos_start) + strlen($tabs_end);
    $tabs_chunk = substr($content, $pos_start, $pos_end - $pos_start);
    $replacement = '<?php else: ?>
                        <?php foreach ($topics as $topic): ?>';
    $content = str_replace($tabs_chunk, $replacement, $content);
}

// Remove card wrappers
$card_wrapper_pattern = '/<div id="card-\<\?= \$current_course_id \?\>-\<\?= \$topic\[\'id\'\] \?\>" class="chapter-card-\<\?= \$current_course_id \?\> transition-opacity duration-300 \<\?= \$index === 0 \? \'block\' : \'hidden\' \?\>">\s*<div class="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-all duration-300 overflow-hidden flex flex-col hover:border-indigo-300">/';
$card_wrapper_replacement = '<div class="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-all duration-300 overflow-hidden flex flex-col h-full hover:border-indigo-300">';
$content = preg_replace($card_wrapper_pattern, $card_wrapper_replacement, $content);

// And the ending pattern
$end_pattern = '/<\/div>\s*<\/div>\s*\<\?php endforeach; \?\>\s*<\/div>\s*\<\?php endif; \?\>/';
$end_replacement = '</div>
                        <?php endforeach; ?>
                        <?php endif; ?>';
$content = preg_replace($end_pattern, $end_replacement, $content);

// Remove the injected script
$script_start = '<script>
function showChapterCard(topicId, courseId) {';
$script_end = '</script>';

$pos_script_start = strpos($content, $script_start);
if ($pos_script_start !== false) {
    $pos_script_end = strpos($content, $script_end, $pos_script_start) + strlen($script_end);
    $script_chunk = substr($content, $pos_script_start, $pos_script_end - $pos_script_start);
    $content = str_replace($script_chunk, '', $content);
}

file_put_contents($file, $content);
echo "Reverted\n";
?>
