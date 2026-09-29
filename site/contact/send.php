<?php
/* Consultation form handler. PHP 7.4+. Returns JSON for the fetch() form, redirects for no-JS posts.
   FORM_MODE 'demo' validates and succeeds without sending; 'mail' sends with PHP mail(). */
require_once dirname(__DIR__) . '/inc/config.php';
require_once dirname(__DIR__) . '/inc/data.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch';
function respond($ok, $error, $isAjax)
{
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($ok ? array('ok' => true) : array('ok' => false, 'error' => $error));
    } else {
        header('Location: ' . url('contact/') . ($ok ? '?sent=1' : '?error=1'), true, 303);
    }
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

$in = function ($k, $max) {
    $v = isset($_POST[$k]) ? trim((string) $_POST[$k]) : '';
    $v = str_replace(array("\r", "\0"), '', $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
};
$name    = preg_replace('/[\n\t]+/', ' ', $in('name', 80));
$phone   = preg_replace('/[^0-9+()\-\s.]/', '', $in('phone', 20));
$email   = $in('email', 120);
$service = $in('service', 60);
$message = $in('message', 2000);

/* spam guards: honeypot + minimum fill time */
$ts = isset($_POST['ts']) ? (int) $_POST['ts'] : 0;
if ($in('company_site', 100) !== '' || ($ts && time() - $ts < 3)) respond(true, '', $isAjax);

if ($name === '' || strlen(preg_replace('/\D/', '', $phone)) < 7 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please add your name, a phone number and a valid email.', $isAjax);
}
$serviceName = isset($SERVICES[$service]) ? $SERVICES[$service]['name'] : ($service === 'other' ? 'Something else' : 'Not given');

if (FORM_MODE === 'mail') {
    $subject = 'New consultation request: ' . $serviceName;
    $body = "New consultation request from the website\n\n"
        . "Name:    $name\nPhone:   $phone\nEmail:   $email\nProject: $serviceName\n\n"
        . "Message:\n" . ($message !== '' ? $message : '(none)') . "\n\n"
        . 'Sent ' . date('Y-m-d H:i') . ' from ' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown') . "\n";
    $headers = 'From: ' . SITE_NAME . ' Website <' . FORM_FROM . ">\r\n"
        . 'Reply-To: ' . str_replace(array("\r", "\n"), '', $name) . ' <' . $email . ">\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: PHP/" . phpversion();
    $sent = @mail(FORM_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . FORM_FROM);
    if (!$sent) respond(false, 'We could not send your message right now. Please call ' . PHONE . '.', $isAjax);
}

respond(true, '', $isAjax);
