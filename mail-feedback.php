<?php

require_once("dup.php");

$dup = new dup($profile);

LogStr("Mail Feedback", 1, __FILE__, __LINE__, __FUNCTION__);

$message = isset($_POST['message']) ? trim((string)$_POST['message']) : "";
$from = isset($_POST['from']) ? trim((string)$_POST['from']) : "";

$message = str_replace(array("\0", "\r"), "", $message);
$from = str_replace(array("\0", "\r", "\n"), "", $from);

if($message == "")
{
    LogError("Feedback not sent: empty message.");
    header("Location: feedback.php");
    exit;
}

if(strlen($message) > 5000)
    $message = substr($message, 0, 5000);

$subject = "dup Feedback";
$body = wordwrap($message, 200, "\r\n");
$headers = array();

if(filter_var($from, FILTER_VALIDATE_EMAIL))
    $headers[] = "From: " . $from;
else
    $headers[] = "From: feedback@arden.ie";

$headers[] = "X-Mailer: DUP";
$headersText = implode("\r\n", $headers);

if(!@mail('feedback@arden.ie', $subject, $body, $headersText))
    LogError("Feedback mail() failed.");

header("Location: index.php");
exit;

?>