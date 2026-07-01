<?php
require 'db_connection.php';
$file = 'sample_test.csv';
$output = fopen($file, 'w');
fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($output, ['Chapter Number', 'Topic Name', 'Topic Type', 'Sub Topic Name']);
fputcsv($output, ['1', 'Introduction to Course', 'both', 'What is this course about?']);
fclose($output);

$handle = fopen($file, "r");
$first_row = fgetcsv($handle, 1000, ",");
echo "First row: "; print_r($first_row);
if ($first_row && stripos(implode(',', $first_row), 'chapter') === false) {
    echo "Rewinding!\n";
    rewind($handle);
} else {
    echo "Not rewinding.\n";
}
while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    echo "Data row: "; print_r($data);
    $chapter = trim($data[0]);
    $topic_name = trim($data[1]);
    $topic_type = isset($data[2]) ? trim(strtolower($data[2])) : 'both';
    echo "Chapter: '$chapter', Topic: '$topic_name'\n";
    if (empty($chapter) || empty($topic_name)) {
        echo "Skipping because empty chapter or topic name\n";
    }
}
fclose($handle);
