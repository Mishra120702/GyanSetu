<?php
$file = 'attendance/course_attendance.php';
$content = file_get_contents($file);

$target = <<<JAVASCRIPT
    $(document).ready(function() {
        // Initialize date pickers
        flatpickr("#dateFilter", {
            dateFormat: "Y-m-d",
            defaultDate: "<?= \$preselected_date ?>",
            maxDate: "today"
        });
        
        flatpickr("#createDate", {
            dateFormat: "Y-m-d",
            defaultDate: "today",
            maxDate: "today"
        });

        flatpickr("#deleteDate", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });
JAVASCRIPT;

$replacement = <<<JAVASCRIPT
    $(document).ready(function() {
        // Initialize date pickers
        const disableSundays = [
            function(date) {
                // return true to disable
                return (date.getDay() === 0);
            }
        ];

        flatpickr("#dateFilter", {
            dateFormat: "Y-m-d",
            defaultDate: "<?= \$preselected_date ?>",
            maxDate: "today",
            disable: disableSundays
        });
        
        flatpickr("#createDate", {
            dateFormat: "Y-m-d",
            defaultDate: "today",
            maxDate: "today",
            disable: disableSundays
        });

        flatpickr("#deleteDate", {
            dateFormat: "Y-m-d",
            maxDate: "today",
            disable: disableSundays
        });
JAVASCRIPT;

// Let's use preg_replace or str_replace with exact lines if we can
// or just regex if spaces changed
$content = preg_replace(
    "/flatpickr\\(\"#dateFilter\"(.*?)\\}/s",
    "flatpickr(\"#dateFilter\"$1, disable: [function(d){return d.getDay()===0;}]}",
    $content
);
$content = preg_replace(
    "/flatpickr\\(\"#createDate\"(.*?)\\}/s",
    "flatpickr(\"#createDate\"$1, disable: [function(d){return d.getDay()===0;}]}",
    $content
);
$content = preg_replace(
    "/flatpickr\\(\"#deleteDate\"(.*?)\\}/s",
    "flatpickr(\"#deleteDate\"$1, disable: [function(d){return d.getDay()===0;}]}",
    $content
);

file_put_contents($file, $content);
echo "Fixed flatpickr.";
?>
