<?php
$file = 'c:\\xampp\\htdocs\\_public_html (4)\\stu_dash\\my_batches.php';
$content = file_get_contents($file);

$start_tag = '<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">';
$start_pos = strpos($content, $start_tag);

if ($start_pos === false) {
    echo "Start tag not found!\n";
    exit;
}

$end_pos = strpos($content, '<?php endforeach; ?>', $start_pos);
$end_pos = strpos($content, '</div>', $end_pos);
$end_pos += 6;

$chunk = substr($content, $start_pos, $end_pos - $start_pos);

$new_chunk = str_replace('<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">', '<div class="space-y-8">', $chunk);
$new_chunk = str_replace('<div class="col-span-full mt-4 mb-2">', '<div class="mb-2">', $new_chunk);
$new_chunk = str_replace('<div class="col-span-full mb-4">', '<div class="mb-4">', $new_chunk);

$search_str = '<?php else: ?>
                        <?php foreach ($topics as $topic): ?>';

$replacement_str = '<?php else: ?>
                            <!-- Horizontal Tab List -->
                            <div class="flex overflow-x-auto space-x-3 py-3 mb-6 scrollbar-hide" style="scrollbar-width: none; -ms-overflow-style: none;">
                                <?php foreach ($topics as $index => $topic): ?>
                                    <button onclick="showChapterCard(<?= $topic[\'id\'] ?>, \'<?= $current_course_id ?>\')"
                                            id="tab-<?= $current_course_id ?>-<?= $topic[\'id\'] ?>"
                                            class="chapter-tab-<?= $current_course_id ?> flex-shrink-0 px-5 py-2.5 rounded-full border text-sm font-bold transition-all duration-300 flex items-center
                                                <?= $index === 0 ? \'bg-indigo-600 text-white border-indigo-600 shadow-md\' : \'bg-white text-indigo-600 border-indigo-200 hover:bg-indigo-50\' ?>">
                                        <i class="fas fa-chapter mr-2"></i> Chapter <?= htmlspecialchars($topic[\'chapter\']) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Cards Container -->
                            <div class="w-full max-w-lg">
                        <?php foreach ($topics as $index => $topic): ?>';

$new_chunk = str_replace($search_str, $replacement_str, $new_chunk);

$card_start = '<div class="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-all duration-300 overflow-hidden flex flex-col h-full hover:border-indigo-300">';
$card_replacement = '<div id="card-<?= $current_course_id ?>-<?= $topic[\'id\'] ?>" class="chapter-card-<?= $current_course_id ?> transition-opacity duration-300 <?= $index === 0 ? \'block\' : \'hidden\' ?>">
                                <div class="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-all duration-300 overflow-hidden flex flex-col hover:border-indigo-300">';
$new_chunk = str_replace($card_start, $card_replacement, $new_chunk);

$card_end = '</div>
                        <?php endforeach; ?>
                        <?php endif; ?>';
$card_end_replacement = '</div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <?php endif; ?>';

$new_chunk = str_replace($card_end, $card_end_replacement, $new_chunk);

$content = str_replace($chunk, $new_chunk, $content);

$js = "
<script>
function showChapterCard(topicId, courseId) {
    const cards = document.querySelectorAll('.chapter-card-' + courseId);
    cards.forEach(card => {
        card.classList.remove('block');
        card.classList.add('hidden');
    });
    
    const selectedCard = document.getElementById('card-' + courseId + '-' + topicId);
    if (selectedCard) {
        selectedCard.classList.remove('hidden');
        selectedCard.classList.add('block');
    }
    
    const tabs = document.querySelectorAll('.chapter-tab-' + courseId);
    tabs.forEach(tab => {
        tab.classList.remove('bg-indigo-600', 'text-white', 'border-indigo-600', 'shadow-md');
        tab.classList.add('bg-white', 'text-indigo-600', 'border-indigo-200', 'hover:bg-indigo-50');
    });
    
    const selectedTab = document.getElementById('tab-' + courseId + '-' + topicId);
    if (selectedTab) {
        selectedTab.classList.remove('bg-white', 'text-indigo-600', 'border-indigo-200', 'hover:bg-indigo-50');
        selectedTab.classList.add('bg-indigo-600', 'text-white', 'border-indigo-600', 'shadow-md');
    }
}
</script>
</body>
";
$content = str_replace('</body>', $js, $content);

file_put_contents($file, $content);
echo "Done\n";
?>
