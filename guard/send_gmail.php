<?php
$autoloadPath = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

/**
 * Core function to send emails via Gmail API
 */
function sendGmailApi($recipientEmail, $subject, $bodyText) {
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath) || !class_exists('Google\Client')) {
        error_log("Gmail API Warning: google/apiclient is not installed via Composer.");
        return false;
    }

    $refreshToken = '1//04LMS7NHYcrTBCgYIARAAGAQSNwF-L9Irs3Jvc-AwuhkcTc0vrAse4G0z_xz7lmzo17E0a3O_qCHEtb-k_U1ESoWMoD3A4Prmnr0'; 

    if (empty($refreshToken) || $refreshToken === 'PASTE_YOUR_FULL_REFRESH_TOKEN_HERE') {
        error_log("Gmail API Notice: Refresh token pending configuration.");
        return false;
    }

    try {
        $client = new Google\Client();
        $client->setClientId('322980361833-175qeljb7ck0islhbd669jhu4cg4e2c1.apps.googleusercontent.com');
        $client->setClientSecret('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');
        $client->refreshToken($refreshToken);

        $service = new Google\Service\Gmail($client);

        $rawMessageString  = "To: {$recipientEmail}\r\n";
        $rawMessageString .= "Subject: {$subject}\r\n";
        $rawMessageString .= "MIME-Version: 1.0\r\n";
        $rawMessageString .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        $rawMessageString .= $bodyText;

        $mime = rtrim(strtr(base64_encode($rawMessageString), '+/', '-_'), '=');
        $msg = new Google\Service\Gmail\Message();
        $msg->setRaw($mime);

        $service->users_messages->send('me', $msg);
        return true;
    } catch (\Throwable $e) {
        error_log("Gmail API Notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send Visitor Entry/Exit Notification
 */
function sendVisitorNotification($recipientEmail, $residentName, $visitorName, $timestamp, $actionType) {
    if ($actionType === 'TIME IN') {
        $subject = "Visitor Arrival Alert - ResiCured Gate Pass";
        $body = "Hello {$residentName},\n\n"
              . "Your visitor, {$visitorName}, has scanned their QR pass and entered the subdivision at {$timestamp}.\n\n"
              . "Regards,\nResiCured Security Team";
    } else if ($actionType === 'TIME OUT') {
        $subject = "Visitor Departure Alert - ResiCured Gate Pass";
        $body = "Hello {$residentName},\n\n"
              . "Your visitor, {$visitorName}, has scanned their QR pass and exited the subdivision at {$timestamp}.\n\n"
              . "Regards,\nResiCured Security Team";
    } else {
        return false;
    }

    return sendGmailApi($recipientEmail, $subject, $body);
}

/**
 * Send Billing Statement Notification
 */
function sendBillingNotification($recipientEmail, $residentName, $billingMonth, $amount, $dueDate) {
    $formattedAmount = number_format($amount, 2);
    $formattedDueDate = date('F j, Y', strtotime($dueDate));
    
    $subject = "Billing Statement Issued - " . $billingMonth;
    $body = "Hello {$residentName},\n\n"
          . "A new billing statement has been posted for your account.\n\n"
          . "Purpose / Month: {$billingMonth}\n"
          . "Amount Due: PHP {$formattedAmount}\n"
          . "Due Date: {$formattedDueDate}\n\n"
          . "Please settle your account on or before the due date. Thank you!\n\n"
          . "Regards,\nResiCured Admin";

    return sendGmailApi($recipientEmail, $subject, $body);
}
?>