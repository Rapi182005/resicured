<?php
$autoloadPath = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

function sendVisitorNotification($recipientEmail, $residentName, $visitorName, $timestamp, $actionType) {
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath) || !class_exists('Google\Client')) {
        error_log("Gmail API Warning: google/apiclient is not installed via Composer.");
        return false;
    }

    // Replace with your full Refresh Token from OAuth Playground
    $refreshToken = '1//04LMS7NHYcrTBCgYIARAAGAQSNwF-L9Irs3Jvc-AwuhkcTc0vrAse4G0z_xz7lmzo17E0a3O_qCHEtb-k_U1ESoWMoD3A4Prmnr0'; 

    // Skip sending if refresh token is not configured yet
    if (empty($refreshToken) || $refreshToken === 'PASTE_YOUR_FULL_REFRESH_TOKEN_HERE') {
        error_log("Gmail API Notice: Refresh token pending configuration.");
        return false;
    }

    try {
        // 1. Configure Google Client Credentials
        $client = new Google\Client();
        $client->setClientId('322980361833-175qeljb7ck0islhbd669jhu4cg4e2c1.apps.googleusercontent.com');
        $client->setClientSecret('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');
        $client->refreshToken($refreshToken);

        $service = new Google\Service\Gmail($client);

        // 2. Build email content depending on action type
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

        // 3. Format raw MIME email message
        $rawMessageString  = "To: {$recipientEmail}\r\n";
        $rawMessageString .= "Subject: {$subject}\r\n";
        $rawMessageString .= "MIME-Version: 1.0\r\n";
        $rawMessageString .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        $rawMessageString .= $body;

        $mime = rtrim(strtr(base64_encode($rawMessageString), '+/', '-_'), '=');
        $msg = new Google\Service\Gmail\Message();
        $msg->setRaw($mime);

        // 4. Send email via Gmail API
        $service->users_messages->send('me', $msg);
        return true;
    } catch (\Throwable $e) {
        error_log("Gmail API Notification Error: " . $e->getMessage());
        return false;
    }
}
?>