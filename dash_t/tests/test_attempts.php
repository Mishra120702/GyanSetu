<?php
$test_id = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;
if ($test_id) {
    header("Location: test_details.php?test_id=" . $test_id);
} else {
    header("Location: tests.php");
}
exit;
?>
