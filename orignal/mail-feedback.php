<?php
$message = $_POST['message'];
$from    = $_POST['from'];
// In case any of our lines are larger than 70 characters, we should use wordwrap()
$message = wordwrap($message, 200, "\r\n");
$headers = "From: $from\r\n";

if(filter_var($from, FILTER_VALIDATE_EMAIL))
	mail('feedback@arden.ie', 'dup Feedback', $message, $headers);
else
	mail('feedback@arden.ie', 'dup Feedback', $message);

header("Location: index.php");
?>