<?php
$file = 'c:\\xampp\\htdocs\\_public_html (4)\\stu_dash\\my_batches.php';
$content = file_get_contents($file);

$search_str = "<?php else: ?>\n                        <?php foreach (\$topics as \$topic): ?>";

$replacement_str = "<?php else: ?>
                            <!-- Horizontal Tab List -->
                            <div class=\"flex overflow-x-auto space-x-3 py-3 mb-6 scrollbar-hide\" style=\"scrollbar-width: none; -ms-overflow-style: none;\">
                                <?php foreach (\$topics as \$index => \$topic): ?>
                                    <button onclick=\"showChapterCard(<?= \$topic['id'] ?>, '<?= \$current_course_id ?>')\"
                                            id=\"tab-<?= \$current_course_id ?>-<?= \$topic['id'] ?>\"
                                            class=\"chapter-tab-<?= \$current_course_id ?> flex-shrink-0 px-5 py-2.5 rounded-full border text-sm font-bold transition-all duration-300 flex items-center
                                                <?= \$index === 0 ? 'bg-indigo-600 text-white border-indigo-600 shadow-md' : 'bg-white text-indigo-600 border-indigo-200 hover:bg-indigo-50' ?>\">
                                        <i class=\"fas fa-chapter mr-2\"></i> Chapter <?= htmlspecialchars(\$topic['chapter']) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Cards Container -->
                            <div class=\"w-full max-w-2xl\">
                        <?php foreach (\$topics as \$index => \$topic): ?>";

// If \n doesn't match, maybe \r\n
if (strpos($content, $search_str) === false) {
    $search_str = "<?php else: ?>\r\n                        <?php foreach (\$topics as \$topic): ?>";
}

$content = str_replace($search_str, $replacement_str, $content);
file_put_contents($file, $content);
echo "Done";
?>
