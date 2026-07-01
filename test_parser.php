<?php
$file = 'sample_test.csv';
$output = fopen($file, 'w');
fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($output, ['Chapter Number', 'Topic Name', 'Topic Type', 'Sub Topic Name']);
fputcsv($output, ['1', 'Introduction to Course', 'both', 'What is this course about?']);
fclose($output);

$handle = fopen($file, "r");
$first_row = fgetcsv($handle, 1000, ",");
if ($first_row && stripos(implode(',', $first_row), 'chapter') === false) {
    rewind($handle);
}

$main_added = 0;
while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    echo "Row: " . implode('|', $data) . "\n";
    if (count($data) < 2) continue; 
    
    $chapter = trim($data[0]);
    $topic_name = trim($data[1]);
    
    if (empty($chapter) || empty($topic_name)) continue;
    $main_added++;
}
echo "Added: $main_added\n";
