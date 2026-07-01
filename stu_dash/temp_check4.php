<?php
session_start();
$_SESSION['student_id'] = 'STD003';
require '../db_connection.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
include 'api_get_notifications.php';
$output = ob_get_clean();
echo $output;
?>
