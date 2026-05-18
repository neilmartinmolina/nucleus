<?php
// PHPMailer email helper. Install via Composer or drop PHPMailer in PHPMailer/src/.

function nucleusLoadPHPMailer(): bool {
    if (class_exists("\\PHPMailer\\PHPMailer\\PHPMailer")) {
        return true;
    }

    $manualBase = __DIR__ . "/../PHPMailer/src";
    $manualFiles = [
        $manualBase . "/Exception.php",
        $manualBase . "/PHPMailer.php",
        $manualBase . "/SMTP.php",
    ];

    if (is_file($manualFiles[0]) && is_file($manualFiles[1]) && is_file($manualFiles[2])) {
        require_once $manualFiles[0];
        require_once $manualFiles[1];
        require_once $manualFiles[2];
    }

    return class_exists("\\PHPMailer\\PHPMailer\\PHPMailer");
}

function sendNucleusEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $plainBody = ""): bool {
    if (!nucleusLoadPHPMailer()) {
        error_log("PHPMailer is not installed. Email was not sent to {$toEmail}.");
        return false;
    }

    try {
        $mailClass = "\\PHPMailer\\PHPMailer\\PHPMailer";
        $mail = new $mailClass(true);

        $host = $_ENV["SMTP_HOST"] ?? "";
        if ($host !== "") {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = filter_var($_ENV["SMTP_AUTH"] ?? "true", FILTER_VALIDATE_BOOLEAN);
            $mail->Username = $_ENV["SMTP_USERNAME"] ?? "";
            $mail->Password = $_ENV["SMTP_PASSWORD"] ?? "";
            $mail->Port = (int) ($_ENV["SMTP_PORT"] ?? 587);

            $secure = strtolower((string) ($_ENV["SMTP_SECURE"] ?? "tls"));
            if (in_array($secure, ["ssl", "smtps"], true)) {
                $mail->SMTPSecure = "ssl";
            } elseif (in_array($secure, ["tls", "starttls"], true)) {
                $mail->SMTPSecure = "tls";
            }
        }

        $fromEmail = $_ENV["MAIL_FROM_ADDRESS"] ?? "noreply@nucleus.local";
        $fromName = $_ENV["MAIL_FROM_NAME"] ?? "Nucleus";

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody !== "" ? $plainBody : trim(strip_tags(str_replace(["<br>", "<br/>", "<br />"], "\n", $htmlBody)));

        return $mail->send();
    } catch (Throwable $e) {
        error_log("Email send failed: " . $e->getMessage());
        return false;
    }
}

function nucleusMailAppUrl(string $path = ""): string {
    $baseUrl = rtrim((string) (defined("APP_URL") ? APP_URL : ($_ENV["APP_URL"] ?? "")), "/");
    if ($baseUrl === "") {
        $baseUrl = "http://localhost/Nucleus";
    }

    $basePath = trim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/");
    if ($basePath !== "" && $basePath !== "." && !preg_match('#/' . preg_quote($basePath, "#") . '$#i', $baseUrl)) {
        $baseUrl .= "/" . $basePath;
    }

    return $baseUrl . "/" . ltrim($path, "/");
}

function nucleusVerificationToken(): array {
    $token = bin2hex(random_bytes(32));
    return [$token, hash("sha256", $token)];
}

function nucleusVerificationEmailHtml(string $fullName, string $verifyUrl): string {
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, "UTF-8");
    $safeUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, "UTF-8");

    return <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#E8F5FF;font-family:Inter,Segoe UI,Arial,sans-serif;color:#0f172a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#E8F5FF;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #dbeafe;border-radius:18px;overflow:hidden;box-shadow:0 18px 45px rgba(15,23,42,0.10);">
          <tr>
            <td style="background:linear-gradient(135deg,#0050D8 0%,#1F7BFF 68%,#E8F5FF 100%);padding:34px 32px;color:#ffffff;">
              <div style="font-size:13px;letter-spacing:0.12em;text-transform:uppercase;font-weight:800;color:#E8F5FF;">NUCLEUS</div>
              <h1 style="margin:12px 0 0;font-size:28px;line-height:1.2;font-weight:800;">Verify your workspace email</h1>
              <p style="margin:12px 0 0;font-size:15px;line-height:1.6;color:#eef6ff;">One quick confirmation keeps project requests, subjects, and deployment updates tied to the right person.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              <p style="margin:0 0 16px;font-size:16px;line-height:1.6;">Hello {$safeName},</p>
              <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#475569;">Your Nucleus account has been created. Verify your email address to finish setting up your account and start using your workspace.</p>
              <a href="{$safeUrl}" style="display:inline-block;background:#0050D8;color:#ffffff;text-decoration:none;font-weight:800;font-size:14px;padding:13px 20px;border-radius:10px;">Verify Email Address</a>
              <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#64748b;">This link expires in 24 hours. If the button does not work, paste this link into your browser:</p>
              <p style="margin:8px 0 0;font-size:12px;line-height:1.6;word-break:break-all;color:#0050D8;">{$safeUrl}</p>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;line-height:1.5;">
              If you did not create this account, you can safely ignore this email.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

function sendNucleusVerificationEmail(string $toEmail, string $toName, int $userId, string $token): bool {
    $verifyUrl = nucleusMailAppUrl("verify_email.php?user=" . urlencode((string) $userId) . "&token=" . urlencode($token));
    $plainBody = "Hello {$toName},\n\nVerify your Nucleus email address using this link:\n{$verifyUrl}\n\nThis link expires in 24 hours.";

    return sendNucleusEmail(
        $toEmail,
        $toName,
        "Verify your Nucleus email",
        nucleusVerificationEmailHtml($toName, $verifyUrl),
        $plainBody
    );
}
