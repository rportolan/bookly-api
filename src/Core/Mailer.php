<?php
declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

final class Mailer
{
    /**
     * Drivers:
     * - log  : log dans error_log (dev)
     * - smtp : envoi via SMTP (préprod/prod recommandé)
     */
    public static function send(string $to, string $subject, string $htmlBody, string $textBody = ''): void
    {
        $driver = Env::get('MAIL_DRIVER', 'log');

        $from = Env::get('MAIL_FROM', 'no-reply@bookly.local');
        $fromName = Env::get('MAIL_FROM_NAME', 'Bookly');

        if ($driver === 'log') {
            error_log("[BOOKLY][mail][log] To={$to} Subject={$subject}");
            error_log("[BOOKLY][mail][log] Body=" . $htmlBody);
            return;
        }

        if ($driver !== 'smtp') {
            error_log("[BOOKLY][mail] Unknown driver={$driver}, fallback log.");
            error_log("[BOOKLY][mail][log] To={$to} Subject={$subject}");
            error_log("[BOOKLY][mail][log] Body=" . $htmlBody);
            return;
        }

        $host = Env::get('MAIL_HOST', '');
        $port = (int)Env::get('MAIL_PORT', '587');
        $user = Env::get('MAIL_USER', '');
        $pass = Env::get('MAIL_PASS', '');
        $enc  = strtolower(Env::get('MAIL_ENCRYPTION', 'tls')); // tls|ssl|none

        if ($host === '' || $user === '' || $pass === '') {
            error_log("[BOOKLY][mail][smtp] Missing SMTP config. Fallback log.");
            error_log("[BOOKLY][mail][log] To={$to} Subject={$subject}");
            error_log("[BOOKLY][mail][log] Body=" . $htmlBody);
            return;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $pass;

            // Mailtrap sandbox: STARTTLS sur 587
            if ($enc === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // 465
            } elseif ($enc === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 587
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($from, $fromName);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

            $mail->send();
        } catch (MailException $e) {
            // Ne pas casser l'API, mais loguer
            error_log("[BOOKLY][mail][smtp][error] " . $e->getMessage());
            error_log("[BOOKLY][mail][smtp][error] To={$to} Subject={$subject}");
        }
    }
}
