<?php
declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

final class Mailer
{
    /**
     * Drivers:
     * - log    : log dans error_log (dev)
     * - smtp   : envoi via SMTP (souvent bloqué en préprod/prod)
     * - resend : envoi via API HTTPS (recommandé, port 443)
     */
    public static function send(string $to, string $subject, string $htmlBody, string $textBody = ''): void
    {
        $driver = strtolower(trim((string)Env::get('MAIL_DRIVER', 'log')));

        $from = (string)Env::get('MAIL_FROM', 'no-reply@bookly.local');
        $fromName = (string)Env::get('MAIL_FROM_NAME', 'Bookly');

        $alt = $textBody !== '' ? $textBody : strip_tags($htmlBody);

        if ($driver === 'log') {
            error_log("[BOOKLY][mail][log] To={$to} Subject={$subject}");
            error_log("[BOOKLY][mail][log] Body=" . $htmlBody);
            return;
        }

        if ($driver === 'resend') {
            self::sendWithResend($to, $subject, $htmlBody, $alt, $from, $fromName);
            return;
        }

        if ($driver === 'smtp') {
            self::sendWithSmtp($to, $subject, $htmlBody, $alt, $from, $fromName);
            return;
        }

        // Unknown driver => log fallback
        error_log("[BOOKLY][mail] Unknown driver={$driver}, fallback log.");
        error_log("[BOOKLY][mail][log] To={$to} Subject={$subject}");
        error_log("[BOOKLY][mail][log] Body=" . $htmlBody);
    }

    /* -------------------- RESEND (HTTP API) -------------------- */

    private static function sendWithResend(
        string $to,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $from,
        string $fromName
    ): void {
        $apiKey = (string)Env::get('RESEND_API_KEY', '');

        if ($apiKey === '') {
            error_log("[BOOKLY][mail][resend] Missing RESEND_API_KEY. Fallback log.");
            error_log("[BOOKLY][mail][log] To={$to} Subject={$subject}");
            return;
        }

        // Resend attend "Name <email@domain>"
        $fromHeader = trim($fromName) !== '' ? "{$fromName} <{$from}>" : $from;

        $payload = [
            'from' => $fromHeader,
            'to' => [$to],
            'subject' => $subject,
            'html' => $htmlBody,
            'text' => $textBody,
        ];

        [$ok, $status, $resp] = self::httpPostJson(
            'https://api.resend.com/emails',
            $payload,
            [
                'Accept: application/json',
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            5,
            10
        );

        if (!$ok) {
            $snippet = is_string($resp) ? substr($resp, 0, 600) : '';
            error_log("[BOOKLY][mail][resend][error] HTTP {$status} resp={$snippet}");
            error_log("[BOOKLY][mail][resend][error] To={$to} Subject={$subject} From={$fromHeader}");
            return;
        }

        // Optionnel: log minimal en dev
        if (Env::get('APP_ENV', 'prod') === 'dev') {
            error_log("[BOOKLY][mail][resend] sent To={$to} Subject={$subject}");
        }
    }

    /**
     * POST JSON avec fallback:
     * - utilise cURL si dispo
     * - sinon file_get_contents (streams)
     *
     * @return array{0:bool,1:int,2:string} [ok, httpStatus, responseBody]
     */
    private static function httpPostJson(
        string $url,
        array $payload,
        array $headers,
        int $connectTimeoutSeconds,
        int $timeoutSeconds
    ): array {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return [false, 0, 'json_encode_failed'];
        }

        // Prefer cURL if available
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) return [false, 0, 'curl_init_failed'];

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $timeoutSeconds,
            ]);

            $resp = curl_exec($ch);
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                return [false, 0, "curl_errno={$errno} err={$err}"];
            }

            $body = is_string($resp) ? $resp : '';
            $ok = ($status >= 200 && $status < 300);
            return [$ok, $status, $body];
        }

        // Fallback streams
        $headerStr = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerStr,
                'content' => $json,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true, // allow reading body on non-2xx
            ],
        ]);

        $resp = @file_get_contents($url, false, $context);
        $body = is_string($resp) ? $resp : '';

        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int)$m[1];
                    break;
                }
            }
        }

        $ok = ($status >= 200 && $status < 300);
        return [$ok, $status, $body !== '' ? $body : 'stream_request_failed_or_empty'];
    }

    /* -------------------- SMTP (PHPMailer) -------------------- */

    private static function sendWithSmtp(
        string $to,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $from,
        string $fromName
    ): void {
        $host = (string)Env::get('MAIL_HOST', '');
        $port = (int)Env::get('MAIL_PORT', '587');
        $user = (string)Env::get('MAIL_USER', '');
        $pass = (string)Env::get('MAIL_PASS', '');
        $enc  = strtolower((string)Env::get('MAIL_ENCRYPTION', 'tls')); // tls|ssl|none

        if ($host === '' || $user === '' || $pass === '') {
            error_log("[BOOKLY][mail][smtp] Missing SMTP config. Fallback log.");
            error_log("[BOOKLY][mail][log] To={$to} Subject={$subject}");
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

            // Anti “moulinage”
            $mail->Timeout = 10;
            $mail->SMTPKeepAlive = false;
            $mail->SMTPDebug = 0;

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
            $mail->AltBody = $textBody;

            $mail->send();
        } catch (MailException $e) {
            error_log("[BOOKLY][mail][smtp][error] " . $e->getMessage());
            error_log("[BOOKLY][mail][smtp][error] To={$to} Subject={$subject}");
        }
    }
}
