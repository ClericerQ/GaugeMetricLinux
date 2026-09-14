<?php

class cMessage {
    public const CHANNELS = ['mail', 'telegram', 'whatsapp', 'signal', 'matrix'];

    private const IMPLEMENTED = ['mail'];

    private const CRLF = "\r\n";

    public ?string $lastError = null;

    private array $config;

    private string $cryptKey = '';

    private ?cCrypt $crypt = null;

    private $imap = null;
    private int $imapTag = 0;

    public function __construct(?array $config = null) {
        $this->config = $config ?? $this->configFromFile();
        $this->cryptKey = $this->resolveCryptKey();
    }

    public function channels(): array {
        $out = [];
        foreach (self::CHANNELS as $channel) {
            $cfg       = $this->channelConfig($channel);
            $missing   = $this->missingFields($channel);
            $supported = in_array($channel, self::IMPLEMENTED, true);

            $out[$channel] = [
                'implemented' => $supported,
                'enabled'     => (bool) ($cfg['enabled'] ?? false),
                'configured'  => $missing === [],
                'ready'       => $supported && (bool) ($cfg['enabled'] ?? false) && $missing === [],
                'missing'     => $missing,
                'status'      => $supported ? 'ready' : 'placeholder',
                'can_fetch'   => $channel === 'mail',
            ];
        }
        return $out;
    }

    public function available(string $channel): bool {
        return (bool) ($this->channels()[$channel]['ready'] ?? false);
    }

    public function channelConfig(string $channel): array {
        $cfg = $this->config[$channel] ?? [];
        return is_array($cfg) ? $this->decodeSecrets($cfg) : [];
    }

    public function configure(string $channel, array $values, bool $persist = false): bool {
        if (!in_array($channel, self::CHANNELS, true)) {
            $this->fail("cMessage: unbekannter Kanal '$channel'");
            return false;
        }

        $this->config[$channel] = $this->mergeDeep($this->config[$channel] ?? [], $values);

        if (!$persist) {
            return true;
        }

        if (!class_exists('cConfig')) {
            $this->fail('cMessage: cConfig nicht verfuegbar, configure() konnte nicht speichern');
            return false;
        }

        cConfig::set("message.$channel", $this->encodeSecrets($this->config[$channel]));

        if (!cConfig::save()) {
            $this->fail('cMessage: config.json konnte nicht geschrieben werden');
            return false;
        }
        return true;
    }

    public function credentials(string $channel): array {
        return $this->maskSecrets($this->channelConfig($channel));
    }

    public function missingFields(string $channel): array {
        $cfg = $this->channelConfig($channel);

        $required = match ($channel) {
            'mail'     => $this->mailerUrl() === null ? ['smtp.host', 'from'] : ['from'],
            'telegram' => ['bot_token'],
            'whatsapp' => ['token', 'phone_number_id'],
            'signal'   => ['api_url', 'number'],
            'matrix'   => ['homeserver', 'access_token'],
            default    => [],
        };

        $missing = [];
        foreach ($required as $path) {
            if ($this->dig($cfg, $path) === '') {
                $missing[] = $path;
            }
        }
        return $missing;
    }

    public function send(string $channel, string|array $to = '', string $subject = '', string $body = '', array $options = []): array {
        $this->lastError = null;

        if (!in_array($channel, self::CHANNELS, true)) {
            return $this->result($channel, false, 'error', ['error' => "unbekannter Kanal '$channel'"]);
        }

        $cfg = $this->channelConfig($channel);
        if (($cfg['enabled'] ?? false) === false && ($options['force'] ?? false) === false) {
            return $this->result($channel, false, 'disabled', [
                'error' => "Kanal '$channel' ist in der Konfiguration abgeschaltet",
            ]);
        }

        $recipients = $this->recipients($to, $channel);

        return match ($channel) {
            'mail'     => $this->sendMail($recipients, $subject, $body, $options),
            'telegram' => $this->sendTelegram($recipients, $this->joinSubject($subject, $body), $options),
            'whatsapp' => $this->sendWhatsapp($recipients, $this->joinSubject($subject, $body), $options),
            'signal'   => $this->sendSignal($recipients, $this->joinSubject($subject, $body), $options),
            'matrix'   => $this->sendMatrix($recipients, $this->joinSubject($subject, $body), $options),
        };
    }

    public function notify(string $subject, string $body, array $options = []): array {
        $channel = (string) ($this->config['default_channel'] ?? 'mail');
        return $this->send($channel, $options['to'] ?? '', $subject, $body, $options);
    }

    public function mail(string|array $to, string $subject, string $body, array $options = []): array {
        return $this->send('mail', $to, $subject, $body, $options);
    }

    public function telegram(string $text, string|array $to = '', array $options = []): array {
        return $this->send('telegram', $to, '', $text, $options);
    }

    public function whatsapp(string $text, string|array $to = '', array $options = []): array {
        return $this->send('whatsapp', $to, '', $text, $options);
    }

    public function signal(string $text, string|array $to = '', array $options = []): array {
        return $this->send('signal', $to, '', $text, $options);
    }

    public function matrix(string $text, string|array $to = '', array $options = []): array {
        return $this->send('matrix', $to, '', $text, $options);
    }

    public function fetch(string $channel = 'mail', array $options = []): array {
        $this->lastError = null;

        if ($channel !== 'mail') {
            return $this->result($channel, false, 'placeholder', [
                'error'    => "Abruf fuer '$channel' ist noch nicht implementiert",
                'messages' => [],
                'count'    => 0,
            ]);
        }
        return $this->fetchMail($options);
    }

    public function mailboxStatus(?string $mailbox = null): array {
        $cfg     = $this->channelConfig('mail');
        $mailbox = $mailbox ?? (string) ($cfg['imap']['mailbox'] ?? 'INBOX');

        return $this->withImap(function () use ($mailbox) {
            $res = $this->imapCommand('STATUS ' . $this->imapQuote($mailbox) . ' (MESSAGES UNSEEN RECENT)');
            if (!$res['ok']) {
                return $this->result('mail', false, 'error', ['error' => $res['response']]);
            }

            $status = [];
            foreach ($res['lines'] as $line) {
                if (preg_match('/\((.*)\)/s', $line, $m)) {
                    $parts = preg_split('/\s+/', trim($m[1]));
                    for ($i = 0; $i + 1 < count($parts); $i += 2) {
                        $status[strtolower($parts[$i])] = (int) $parts[$i + 1];
                    }
                }
            }

            return $this->result('mail', true, 'ok', [
                'mailbox' => $mailbox,
                'total'   => $status['messages'] ?? 0,
                'unseen'  => $status['unseen']   ?? 0,
                'recent'  => $status['recent']   ?? 0,
            ]);
        });
    }

    public function mailboxes(): array {
        return $this->withImap(function () {
            $res = $this->imapCommand('LIST "" "*"');
            if (!$res['ok']) {
                return $this->result('mail', false, 'error', ['error' => $res['response'], 'mailboxes' => []]);
            }

            $boxes = [];
            foreach ($res['lines'] as $line) {
                if (preg_match('/^\* LIST \([^)]*\) (?:"[^"]*"|NIL) (?:"(.*)"|(\S+))\s*$/', trim($line), $m)) {
                    $boxes[] = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
                }
            }

            return $this->result('mail', true, 'ok', ['mailboxes' => $boxes, 'count' => count($boxes)]);
        });
    }

    public function markSeen(array $uids, ?string $mailbox = null): array {
        return $this->storeFlag($uids, '\\Seen', $mailbox);
    }

    public function markUnseen(array $uids, ?string $mailbox = null): array {
        return $this->storeFlag($uids, '\\Seen', $mailbox, remove: true);
    }

    public function deleteMessages(array $uids, ?string $mailbox = null): array {
        $res = $this->storeFlag($uids, '\\Deleted', $mailbox, expunge: true);
        return $res;
    }

    public function test(string $channel = 'mail'): array {
        $this->lastError = null;

        if ($channel !== 'mail') {
            $missing = $this->missingFields($channel);
            return $this->result($channel, false, 'placeholder', [
                'error'      => "Kanal '$channel' ist ein Platzhalter - es gibt noch keine API-Anbindung zum Testen",
                'missing'    => $missing,
                'configured' => $missing === [],
            ]);
        }

        $cfg  = $this->channelConfig('mail');
        $out  = ['smtp' => null, 'imap' => null];
        $ok   = true;

        if (($cfg['smtp']['host'] ?? '') !== '') {
            $sock = $this->smtpConnect($cfg['smtp'], $error);
            if ($sock === null) {
                $out['smtp'] = ['success' => false, 'error' => $error];
                $ok = false;
            } else {
                $this->smtpCommand($sock, 'QUIT');
                fclose($sock);
                $out['smtp'] = ['success' => true, 'error' => null];
            }
        } else {
            $out['smtp'] = ['success' => false, 'error' => 'kein SMTP-Host konfiguriert'];
            $ok = false;
        }

        if (($cfg['imap']['host'] ?? '') !== '') {
            $res = $this->withImap(fn() => $this->result('mail', true, 'ok', []));
            $out['imap'] = ['success' => $res['success'], 'error' => $res['error'] ?? null];
            $ok = $ok && $res['success'];
        } else {
            $out['imap'] = ['success' => false, 'error' => 'kein IMAP-Host konfiguriert'];
        }

        return $this->result('mail', $ok, $ok ? 'ok' : 'error', [
            'smtp'  => $out['smtp'],
            'imap'  => $out['imap'],
            'error' => $ok ? null : ($out['smtp']['error'] ?? $out['imap']['error'] ?? 'Test fehlgeschlagen'),
        ]);
    }

    private function sendMail(array $to, string $subject, string $body, array $options): array {
        $cfg = $this->channelConfig('mail');

        if ($to === []) {
            $to = $this->recipients('', 'mail');
        }
        if ($to === []) {
            return $this->result('mail', false, 'error', ['error' => 'kein Empfaenger angegeben']);
        }

        foreach ($to as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                return $this->result('mail', false, 'error', ['error' => "ungueltige Empfaengeradresse: $address"]);
            }
        }

        $transport = (string) ($options['transport'] ?? 'auto');
        if ($transport === 'auto') {
            $transport = ($cfg['smtp']['host'] ?? '') !== '' ? 'smtp'
                : ($this->mailerUrl() !== null ? 'mailer' : 'php');
        }

        return match ($transport) {
            'smtp'   => $this->sendMailSmtp($cfg, $to, $subject, $body, $options),
            'mailer' => $this->sendMailViaMailer($to, $subject, $body, $options),
            'php'    => $this->sendMailPhp($cfg, $to, $subject, $body, $options),
            default  => $this->result('mail', false, 'error', ['error' => "unbekannter Transport '$transport'"]),
        };
    }

    private function sendMailSmtp(array $cfg, array $to, string $subject, string $body, array $options): array {
        $smtp = $cfg['smtp'] ?? [];
        $from = (string) ($options['from'] ?? $cfg['from'] ?? '');

        if ($from === '') {
            return $this->result('mail', false, 'error', ['error' => 'keine Absenderadresse (message.mail.from)']);
        }

        $cc  = $this->addressList($options['cc']  ?? []);
        $bcc = $this->addressList($options['bcc'] ?? []);

        $message   = $this->buildMailMessage($cfg, $to, $cc, $subject, $body, $options, $messageId);
        $envelope  = array_merge($to, $cc, $bcc);

        $sock = $this->smtpConnect($smtp, $error);
        if ($sock === null) {
            return $this->result('mail', false, 'error', ['error' => $error]);
        }

        try {
            $reply = $this->smtpCommand($sock, 'MAIL FROM:<' . $from . '>');
            if (!$this->smtpOk($reply, 250)) {
                return $this->result('mail', false, 'error', ['error' => 'MAIL FROM abgelehnt: ' . $reply['text']]);
            }

            $accepted = [];
            foreach ($envelope as $rcpt) {
                $reply = $this->smtpCommand($sock, 'RCPT TO:<' . $rcpt . '>');
                if ($this->smtpOk($reply, 250) || $this->smtpOk($reply, 251)) {
                    $accepted[] = $rcpt;
                }
            }
            if ($accepted === []) {
                return $this->result('mail', false, 'error', ['error' => 'kein Empfaenger akzeptiert: ' . $reply['text']]);
            }

            $reply = $this->smtpCommand($sock, 'DATA');
            if (!$this->smtpOk($reply, 354)) {
                return $this->result('mail', false, 'error', ['error' => 'DATA abgelehnt: ' . $reply['text']]);
            }

            $data  = preg_replace('/^\./m', '..', $message);
            $reply = $this->smtpCommand($sock, $data . self::CRLF . '.');
            if (!$this->smtpOk($reply, 250)) {
                return $this->result('mail', false, 'error', ['error' => 'Zustellung abgelehnt: ' . $reply['text']]);
            }

            $this->smtpCommand($sock, 'QUIT');

            $this->log("mail gesendet an " . implode(', ', $accepted) . " ($subject)");

            return $this->result('mail', true, 'sent', [
                'transport'  => 'smtp',
                'message_id' => $messageId,
                'recipients' => $accepted,
                'response'   => $reply['text'],
            ]);
        } finally {
            if (is_resource($sock)) {
                fclose($sock);
            }
        }
    }

    private function sendMailViaMailer(array $to, string $subject, string $body, array $options): array {
        if (!class_exists('cWeb')) {
            return $this->result('mail', false, 'error', ['error' => 'cWeb nicht verfuegbar']);
        }

        $web    = new cWeb();
        $sender = (string) ($options['from'] ?? $this->channelConfig('mail')['from'] ?? 'pve');
        $answer = $web->sendmail(implode(',', $to), $subject, $body, $sender);

        if ($answer === false) {
            return $this->result('mail', false, 'error', ['error' => $web->lastError ?? 'mailer_url nicht erreichbar']);
        }

        $this->log("mail ueber mailer_url gesendet an " . implode(', ', $to) . " ($subject)");

        return $this->result('mail', true, 'sent', [
            'transport'  => 'mailer',
            'recipients' => $to,
            'response'   => is_string($answer) ? $answer : '',
        ]);
    }

    private function sendMailPhp(array $cfg, array $to, string $subject, string $body, array $options): array {
        if (!function_exists('mail')) {
            return $this->result('mail', false, 'error', ['error' => 'mail() ist nicht verfuegbar']);
        }

        $message = $this->buildMailMessage($cfg, $to, [], $subject, $body, $options, $messageId);

        [$headerBlock, $bodyBlock] = explode(self::CRLF . self::CRLF, $message, 2) + ['', ''];
        $headers = array_values(array_filter(
            preg_split('/\r\n(?![ \t])/', $headerBlock),
            fn($h) => !preg_match('/^(To|Subject):/i', $h)
        ));

        $ok = @mail(implode(', ', $to), $this->encodeHeader($subject), $bodyBlock, implode(self::CRLF, $headers));

        return $this->result('mail', (bool) $ok, $ok ? 'sent' : 'error', [
            'transport'  => 'php',
            'message_id' => $messageId,
            'recipients' => $to,
            'error'      => $ok ? null : 'mail() hat die Nachricht nicht angenommen',
        ]);
    }

    private function buildMailMessage(array $cfg, array $to, array $cc, string $subject, string $body, array $options, ?string &$messageId = null): string {
        $from     = (string) ($options['from']      ?? $cfg['from'] ?? '');
        $fromName = (string) ($options['from_name'] ?? $cfg['from_name'] ?? '');
        $html     = $options['html'] ?? null;

        $domain    = substr(strrchr($from, '@') ?: '@localhost', 1);
        $messageId = sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(8)), $domain);

        $headers = [
            'Date'         => date('r'),
            'Message-ID'   => $messageId,
            'From'         => $fromName !== '' ? $this->encodeHeader($fromName) . " <$from>" : $from,
            'To'           => implode(', ', $to),
            'Subject'      => $this->encodeHeader($subject),
            'MIME-Version' => '1.0',
        ];
        if ($cc !== []) {
            $headers['Cc'] = implode(', ', $cc);
        }
        if (!empty($options['reply_to'])) {
            $headers['Reply-To'] = (string) $options['reply_to'];
        }
        if (!empty($options['priority'])) {
            $headers['X-Priority'] = (string) (int) $options['priority'];
        }
        foreach (($options['headers'] ?? []) as $name => $value) {
            $headers[$name] = $this->headerSafe((string) $value);
        }

        $attachments = $this->prepareAttachments($options['attachments'] ?? []);

        $textPart = $this->mimePart('text/plain; charset=UTF-8', $this->normalizeEol($body));

        if ($html !== null && $html !== '' && $html !== false) {
            $htmlBody = is_string($html) ? $html : $this->textToHtml($body);
            $altBoundary = $this->boundary('alt');
            $content = "--$altBoundary" . self::CRLF . $textPart . self::CRLF
                     . "--$altBoundary" . self::CRLF . $this->mimePart('text/html; charset=UTF-8', $this->normalizeEol($htmlBody)) . self::CRLF
                     . "--$altBoundary--" . self::CRLF;
            $contentType = "multipart/alternative; boundary=\"$altBoundary\"";
        } else {
            $content     = $textPart;
            $contentType = null;
        }

        if ($attachments !== []) {
            $mixBoundary = $this->boundary('mix');
            $inner = $contentType === null
                ? $content
                : "Content-Type: $contentType" . self::CRLF . self::CRLF . $content;

            $parts = "--$mixBoundary" . self::CRLF . $inner . self::CRLF;
            foreach ($attachments as $file) {
                $parts .= "--$mixBoundary" . self::CRLF
                    . 'Content-Type: ' . $file['mime'] . '; name="' . $file['name'] . '"' . self::CRLF
                    . 'Content-Transfer-Encoding: base64' . self::CRLF
                    . 'Content-Disposition: attachment; filename="' . $file['name'] . '"' . self::CRLF . self::CRLF
                    . chunk_split(base64_encode($file['data']), 76, self::CRLF)
                    . self::CRLF;
            }
            $parts .= "--$mixBoundary--" . self::CRLF;

            $headers['Content-Type'] = "multipart/mixed; boundary=\"$mixBoundary\"";
            $head = '';
            foreach ($headers as $name => $value) {
                $head .= "$name: $value" . self::CRLF;
            }
            return $head . self::CRLF . $parts;
        }

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        $head = '';
        foreach ($headers as $name => $value) {
            $head .= "$name: $value" . self::CRLF;
        }

        if ($contentType === null) {
            return $head . $content;
        }
        return $head . self::CRLF . $content;
    }

    private function mimePart(string $contentType, string $body): string {
        return "Content-Type: $contentType" . self::CRLF
            . 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF
            . chunk_split(base64_encode($body), 76, self::CRLF);
    }

    private function prepareAttachments(array|string $attachments): array {
        if (is_string($attachments)) {
            $attachments = [$attachments];
        }

        $out = [];
        foreach ($attachments as $item) {
            if (is_string($item)) {
                if (!is_readable($item)) {
                    $this->log("Anhang nicht lesbar, uebersprungen: $item");
                    continue;
                }
                $out[] = [
                    'name' => basename($item),
                    'data' => (string) file_get_contents($item),
                    'mime' => $this->mimeType($item),
                ];
                continue;
            }

            if (is_array($item) && isset($item['data'])) {
                $out[] = [
                    'name' => $this->headerSafe((string) ($item['name'] ?? 'attachment.bin')),
                    'data' => (string) $item['data'],
                    'mime' => (string) ($item['mime'] ?? 'application/octet-stream'),
                ];
            }
        }
        return $out;
    }

    private function mimeType(string $path): string {
        if (function_exists('mime_content_type')) {
            $type = @mime_content_type($path);
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }
        return 'application/octet-stream';
    }

    private function textToHtml(string $text): string {
        return '<html><body><p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p></body></html>';
    }

    private function smtpConnect(array $smtp, ?string &$error = null): mixed {
        $host = (string) ($smtp['host'] ?? '');
        if ($host === '') {
            $error = 'kein SMTP-Host konfiguriert';
            return null;
        }

        $encryption = strtolower((string) ($smtp['encryption'] ?? 'tls'));
        $port       = (int)   ($smtp['port'] ?? ($encryption === 'ssl' ? 465 : 587));
        $timeout    = (int)   ($smtp['timeout'] ?? 15);
        $scheme     = $encryption === 'ssl' ? 'ssl' : 'tcp';

        $sock = @stream_socket_client(
            "$scheme://$host:$port",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $this->streamContext($smtp)
        );

        if ($sock === false) {
            $error = "SMTP-Verbindung zu $host:$port fehlgeschlagen ($errno $errstr)";
            $this->fail($error);
            return null;
        }
        stream_set_timeout($sock, $timeout);

        $greeting = $this->smtpRead($sock);
        if (!$this->smtpOk($greeting, 220)) {
            fclose($sock);
            $error = 'SMTP-Server meldet sich nicht mit 220: ' . $greeting['text'];
            $this->fail($error);
            return null;
        }

        $ehloName = (string) ($smtp['helo'] ?? gethostname() ?: 'localhost');
        $reply    = $this->smtpCommand($sock, "EHLO $ehloName");
        if (!$this->smtpOk($reply, 250)) {
            $reply = $this->smtpCommand($sock, "HELO $ehloName");
            if (!$this->smtpOk($reply, 250)) {
                fclose($sock);
                $error = 'EHLO abgelehnt: ' . $reply['text'];
                $this->fail($error);
                return null;
            }
        }
        $capabilities = strtoupper($reply['text']);

        if ($encryption === 'tls' || $encryption === 'starttls') {
            $reply = $this->smtpCommand($sock, 'STARTTLS');
            if (!$this->smtpOk($reply, 220)) {
                fclose($sock);
                $error = 'STARTTLS abgelehnt: ' . $reply['text'];
                $this->fail($error);
                return null;
            }
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock);
                $error = 'TLS-Handshake fehlgeschlagen';
                $this->fail($error);
                return null;
            }

            $reply = $this->smtpCommand($sock, "EHLO $ehloName");
            $capabilities = strtoupper($reply['text']);
        }

        $user = (string) ($smtp['user'] ?? '');
        $pass = (string) ($smtp['pass'] ?? '');

        if ($user !== '' && ($smtp['auth'] ?? true)) {
            if (!$this->smtpAuth($sock, $user, $pass, $capabilities, $error)) {
                fclose($sock);
                $this->fail($error);
                return null;
            }
        }

        return $sock;
    }

    private function smtpAuth($sock, string $user, string $pass, string $capabilities, ?string &$error): bool {
        if (str_contains($capabilities, 'AUTH') && str_contains($capabilities, 'PLAIN')) {
            $reply = $this->smtpCommand($sock, 'AUTH PLAIN ' . base64_encode("\0$user\0$pass"));
        } else {
            $reply = $this->smtpCommand($sock, 'AUTH LOGIN');
            if (!$this->smtpOk($reply, 334)) {
                $error = 'AUTH LOGIN abgelehnt: ' . $reply['text'];
                return false;
            }
            $reply = $this->smtpCommand($sock, base64_encode($user));
            if (!$this->smtpOk($reply, 334)) {
                $error = 'SMTP-Benutzer abgelehnt: ' . $reply['text'];
                return false;
            }
            $reply = $this->smtpCommand($sock, base64_encode($pass));
        }

        if (!$this->smtpOk($reply, 235)) {
            $error = 'SMTP-Anmeldung fehlgeschlagen: ' . $reply['text'];
            return false;
        }
        return true;
    }

    private function smtpCommand($sock, string $command): array {
        fwrite($sock, $command . self::CRLF);
        return $this->smtpRead($sock);
    }

    private function smtpRead($sock): array {
        $text = '';
        $code = 0;

        while (($line = fgets($sock, 4096)) !== false) {
            $text .= $line;
            if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
                $code = (int) $m[1];
                if ($m[2] === ' ') {
                    break;
                }
            }
            $meta = stream_get_meta_data($sock);
            if ($meta['timed_out']) {
                break;
            }
        }

        return ['code' => $code, 'text' => trim($text)];
    }

    private function smtpOk(array $reply, int $expected): bool {
        return $reply['code'] === $expected;
    }

    private function fetchMail(array $options): array {
        $cfg     = $this->channelConfig('mail');
        $mailbox = (string) ($options['mailbox'] ?? $cfg['imap']['mailbox'] ?? 'INBOX');
        $limit   = max(1, (int) ($options['limit'] ?? 25));
        $withBody = (bool) ($options['body'] ?? true);
        $peek    = (bool) ($options['peek'] ?? true);

        return $this->withImap(function () use ($options, $mailbox, $limit, $withBody, $peek) {
            $res = $this->imapCommand('SELECT ' . $this->imapQuote($mailbox));
            if (!$res['ok']) {
                return $this->result('mail', false, 'error', [
                    'error' => "Ordner '$mailbox' nicht waehlbar: " . $res['response'],
                    'messages' => [], 'count' => 0,
                ]);
            }

            $criteria = (string) ($options['search'] ?? '');
            if ($criteria === '') {
                $criteria = ($options['unseen'] ?? false) ? 'UNSEEN' : 'ALL';
                if (!empty($options['since'])) {
                    $criteria .= ' SINCE ' . date('d-M-Y', is_int($options['since']) ? $options['since'] : strtotime((string) $options['since']));
                }
                if (!empty($options['from'])) {
                    $criteria .= ' FROM ' . $this->imapQuote((string) $options['from']);
                }
            }

            $res = $this->imapCommand("UID SEARCH $criteria");
            if (!$res['ok']) {
                return $this->result('mail', false, 'error', [
                    'error' => 'SEARCH fehlgeschlagen: ' . $res['response'],
                    'messages' => [], 'count' => 0,
                ]);
            }

            $uids = [];
            foreach ($res['lines'] as $line) {
                if (preg_match('/^\* SEARCH(.*)$/i', trim($line), $m)) {
                    $uids = array_map('intval', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY));
                }
            }

            if ($uids === []) {
                return $this->result('mail', true, 'ok', ['messages' => [], 'count' => 0, 'mailbox' => $mailbox]);
            }

            rsort($uids);
            $uids = array_slice($uids, 0, $limit);

            $section = $withBody
                ? ($peek ? 'BODY.PEEK[]' : 'BODY[]')
                : ($peek ? 'BODY.PEEK[HEADER]' : 'BODY[HEADER]');

            $res = $this->imapCommand('UID FETCH ' . implode(',', $uids) . " (UID FLAGS INTERNALDATE RFC822.SIZE $section)");
            if (!$res['ok']) {
                return $this->result('mail', false, 'error', [
                    'error' => 'FETCH fehlgeschlagen: ' . $res['response'],
                    'messages' => [], 'count' => 0,
                ]);
            }

            $messages = [];
            foreach ($res['lines'] as $line) {
                $parsed = $this->parseFetchLine($line, $withBody);
                if ($parsed !== null) {
                    $messages[] = $parsed;
                }
            }

            usort($messages, fn($a, $b) => $b['uid'] <=> $a['uid']);

            return $this->result('mail', true, 'ok', [
                'mailbox'  => $mailbox,
                'count'    => count($messages),
                'messages' => $messages,
            ]);
        });
    }

    private function storeFlag(array $uids, string $flag, ?string $mailbox, bool $remove = false, bool $expunge = false): array {
        $uids = array_values(array_filter(array_map('intval', $uids)));
        if ($uids === []) {
            return $this->result('mail', false, 'error', ['error' => 'keine UIDs angegeben']);
        }

        $cfg     = $this->channelConfig('mail');
        $mailbox = $mailbox ?? (string) ($cfg['imap']['mailbox'] ?? 'INBOX');

        return $this->withImap(function () use ($uids, $flag, $mailbox, $remove, $expunge) {
            $res = $this->imapCommand('SELECT ' . $this->imapQuote($mailbox));
            if (!$res['ok']) {
                return $this->result('mail', false, 'error', ['error' => "Ordner '$mailbox' nicht waehlbar"]);
            }

            $op  = $remove ? '-FLAGS' : '+FLAGS';
            $res = $this->imapCommand('UID STORE ' . implode(',', $uids) . " $op ($flag)");
            if (!$res['ok']) {
                return $this->result('mail', false, 'error', ['error' => 'STORE fehlgeschlagen: ' . $res['response']]);
            }

            if ($expunge) {
                $res = $this->imapCommand('EXPUNGE');
                if (!$res['ok']) {
                    return $this->result('mail', false, 'error', ['error' => 'EXPUNGE fehlgeschlagen: ' . $res['response']]);
                }
            }

            return $this->result('mail', true, 'ok', ['uids' => $uids, 'flag' => $flag, 'removed' => $remove]);
        });
    }

    private function withImap(callable $work): array {
        $error = null;
        if (!$this->imapConnect($error)) {
            return $this->result('mail', false, 'error', ['error' => $error, 'messages' => [], 'count' => 0]);
        }
        try {
            return $work();
        } finally {
            $this->imapDisconnect();
        }
    }

    private function imapConnect(?string &$error = null): bool {
        $imap = $this->channelConfig('mail')['imap'] ?? [];
        $host = (string) ($imap['host'] ?? '');

        if ($host === '') {
            $error = 'kein IMAP-Host konfiguriert';
            return false;
        }

        $encryption = strtolower((string) ($imap['encryption'] ?? 'ssl'));
        $port       = (int) ($imap['port'] ?? ($encryption === 'ssl' ? 993 : 143));
        $timeout    = (int) ($imap['timeout'] ?? 15);
        $scheme     = $encryption === 'ssl' ? 'ssl' : 'tcp';

        $this->imap = @stream_socket_client(
            "$scheme://$host:$port",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $this->streamContext($imap)
        );

        if ($this->imap === false) {
            $this->imap = null;
            $error = "IMAP-Verbindung zu $host:$port fehlgeschlagen ($errno $errstr)";
            $this->fail($error);
            return false;
        }
        stream_set_timeout($this->imap, $timeout);
        $this->imapTag = 0;

        $greeting = $this->imapReadLine();
        if (!str_contains(strtoupper((string) $greeting), 'OK')) {
            $this->imapDisconnect();
            $error = 'IMAP-Server meldet sich nicht mit OK: ' . trim((string) $greeting);
            $this->fail($error);
            return false;
        }

        if ($encryption === 'tls' || $encryption === 'starttls') {
            $res = $this->imapCommand('STARTTLS');
            if (!$res['ok'] || !@stream_socket_enable_crypto($this->imap, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->imapDisconnect();
                $error = 'IMAP STARTTLS fehlgeschlagen';
                $this->fail($error);
                return false;
            }
        }

        $res = $this->imapCommand(
            'LOGIN ' . $this->imapQuote((string) ($imap['user'] ?? '')) . ' ' . $this->imapQuote((string) ($imap['pass'] ?? ''))
        );
        if (!$res['ok']) {
            $this->imapDisconnect();

            $error = 'IMAP-Anmeldung fehlgeschlagen: ' . $res['response'];
            $this->fail($error);
            return false;
        }

        return true;
    }

    private function imapDisconnect(): void {
        if (is_resource($this->imap)) {
            @fwrite($this->imap, 'zzz LOGOUT' . self::CRLF);
            @fclose($this->imap);
        }
        $this->imap = null;
    }

    private function imapCommand(string $command): array {
        $tag = sprintf('a%03d', ++$this->imapTag);
        fwrite($this->imap, "$tag $command" . self::CRLF);

        $lines    = [];
        $response = '';
        $ok       = false;

        while (($line = $this->imapReadLine()) !== null) {
            if (preg_match('/^' . $tag . ' (OK|NO|BAD)(.*)$/i', $line, $m)) {
                $ok       = strtoupper($m[1]) === 'OK';
                $response = trim($m[2]);
                break;
            }
            $lines[] = $line;
        }

        return ['ok' => $ok, 'lines' => $lines, 'response' => $response];
    }

    private function imapReadLine(): ?string {
        $line = fgets($this->imap, 8192);
        if ($line === false) {
            return null;
        }

        while (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
            $need = (int) $m[1];
            $data = '';
            while ($need > 0) {
                $chunk = fread($this->imap, min($need, 8192));
                if ($chunk === false || $chunk === '') {
                    $meta = stream_get_meta_data($this->imap);
                    if ($meta['timed_out'] || feof($this->imap)) {
                        break 2;
                    }
                    continue;
                }
                $data .= $chunk;
                $need -= strlen($chunk);
            }
            $line .= $data;

            $next = fgets($this->imap, 8192);
            if ($next === false) {
                break;
            }
            $line .= $next;
        }

        return $line;
    }

    private function imapQuote(string $value): string {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function parseFetchLine(string $line, bool $withBody): ?array {
        if (!preg_match('/^\* \d+ FETCH /i', $line)) {
            return null;
        }

        $msg = [
            'uid'     => 0,
            'flags'   => [],
            'seen'    => false,
            'date'    => null,
            'size'    => 0,
            'from'    => '',
            'to'      => '',
            'cc'      => '',
            'subject' => '',
            'text'    => '',
            'html'    => '',
            'attachments' => [],
        ];

        if (preg_match('/UID (\d+)/', $line, $m)) {
            $msg['uid'] = (int) $m[1];
        }
        if (preg_match('/FLAGS \(([^)]*)\)/', $line, $m)) {
            $msg['flags'] = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
            $msg['seen']  = in_array('\\Seen', $msg['flags'], true);
        }
        if (preg_match('/INTERNALDATE "([^"]+)"/', $line, $m)) {
            $msg['internaldate'] = $m[1];
        }
        if (preg_match('/RFC822\.SIZE (\d+)/', $line, $m)) {
            $msg['size'] = (int) $m[1];
        }

        $raw = $this->extractLiteral($line);
        if ($raw === null) {
            return $msg;
        }

        $parsed = $this->parseMessage($raw, $withBody);
        return array_merge($msg, $parsed);
    }

    private function extractLiteral(string $line): ?string {
        if (preg_match('/BODY\[[^\]]*\](?:<\d+>)? \{(\d+)\}\r?\n/', $line, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[0][0]);
            return substr($line, $start, (int) $m[1][0]);
        }

        if (preg_match('/BODY\[[^\]]*\](?:<\d+>)? "((?:[^"\\\\]|\\\\.)*)"/s', $line, $m)) {
            return stripcslashes($m[1]);
        }
        return null;
    }

    public function parseMessage(string $raw, bool $withBody = true): array {
        [$headerBlock, $bodyBlock] = $this->splitMessage($raw);
        $headers = $this->parseHeaders($headerBlock);

        $out = [
            'from'        => $this->decodeHeader($headers['from']    ?? ''),
            'to'          => $this->decodeHeader($headers['to']      ?? ''),
            'cc'          => $this->decodeHeader($headers['cc']      ?? ''),
            'subject'     => $this->decodeHeader($headers['subject'] ?? ''),
            'message_id'  => $headers['message-id'] ?? '',
            'date'        => isset($headers['date']) ? (strtotime($headers['date']) ?: null) : null,
            'date_string' => $headers['date'] ?? '',
            'headers'     => $headers,
            'text'        => '',
            'html'        => '',
            'attachments' => [],
        ];

        if (preg_match('/<([^>]+)>/', $out['from'], $m)) {
            $out['from_address'] = trim($m[1]);
            $out['from_name']    = trim(str_replace("<{$m[1]}>", '', $out['from']), " \t\"");
        } else {
            $out['from_address'] = trim($out['from']);
            $out['from_name']    = '';
        }

        if (!$withBody || $bodyBlock === '') {
            return $out;
        }

        $this->walkParts($headers, $bodyBlock, $out);

        return $out;
    }

    private function splitMessage(string $raw): array {
        $pos = strpos($raw, "\r\n\r\n");
        if ($pos !== false) {
            return [substr($raw, 0, $pos), substr($raw, $pos + 4)];
        }
        $pos = strpos($raw, "\n\n");
        if ($pos !== false) {
            return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
        }
        return [$raw, ''];
    }

    private function parseHeaders(string $block): array {
        $headers = [];
        $name    = null;

        foreach (preg_split('/\r?\n/', $block) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^[ \t]/', $line) && $name !== null) {
                $headers[$name] .= ' ' . trim($line);
                continue;
            }
            if (preg_match('/^([A-Za-z0-9\-]+):\s?(.*)$/', $line, $m)) {
                $name = strtolower($m[1]);
                $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $m[2] : $m[2];
            }
        }
        return $headers;
    }

    private function walkParts(array $headers, string $body, array &$out, int $depth = 0): void {
        if ($depth > 8) {
            return;
        }

        $contentType = strtolower($headers['content-type'] ?? 'text/plain');

        if (str_starts_with($contentType, 'multipart/') && preg_match('/boundary="?([^";\r\n]+)"?/i', $headers['content-type'] ?? '', $m)) {
            $boundary = $m[1];
            $chunks   = preg_split('/--' . preg_quote($boundary, '/') . '(--)?\r?\n/', $body);

            foreach ($chunks as $index => $chunk) {
                if ($index === 0 || trim($chunk) === '') {
                    continue;
                }
                [$partHead, $partBody] = $this->splitMessage($chunk);
                $this->walkParts($this->parseHeaders($partHead), $partBody, $out, $depth + 1);
            }
            return;
        }

        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        $content  = match ($encoding) {
            'base64'           => (string) base64_decode($body, false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };

        $disposition = strtolower($headers['content-disposition'] ?? '');
        $isAttachment = str_contains($disposition, 'attachment')
            || (str_contains($disposition, 'filename') && !str_starts_with($contentType, 'text/'));

        if ($isAttachment) {
            $name = '';
            if (preg_match('/filename="?([^";\r\n]+)"?/i', $disposition, $m)
                || preg_match('/name="?([^";\r\n]+)"?/i', $headers['content-type'] ?? '', $m)) {
                $name = $this->decodeHeader($m[1]);
            }
            $out['attachments'][] = [
                'name' => $name !== '' ? $name : 'unbenannt',
                'mime' => trim(explode(';', $contentType)[0]),
                'size' => strlen($content),
                'data' => $content,
            ];
            return;
        }

        $charset = 'UTF-8';
        if (preg_match('/charset="?([^";\r\n]+)"?/i', $headers['content-type'] ?? '', $m)) {
            $charset = trim($m[1]);
        }
        $content = $this->toUtf8($content, $charset);

        if (str_starts_with($contentType, 'text/html')) {
            $out['html'] .= $content;
        } else {
            $out['text'] .= $content;
        }
    }

    private function toUtf8(string $text, string $charset): string {
        $charset = strtoupper(trim($charset));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return $text;
        }
        if (function_exists('mb_convert_encoding') && in_array(strtolower($charset), array_map('strtolower', mb_list_encodings()), true)) {
            return (string) @mb_convert_encoding($text, 'UTF-8', $charset);
        }
        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//TRANSLIT', $text);
            if ($converted !== false) {
                return $converted;
            }
        }
        return $text;
    }

    private function decodeHeader(string $value): string {
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }
        if (function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($value);
        }
        return $value;
    }

    private function encodeHeader(string $value): string {
        $value = $this->headerSafe($value);
        if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function headerSafe(string $value): string {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }

    private function sendTelegram(array $to, string $text, array $options): array {
        $cfg     = $this->channelConfig('telegram');
        $token   = (string) ($cfg['bot_token'] ?? '');
        $chats   = $to !== [] ? $to : array_filter([(string) ($cfg['chat_id'] ?? '')]);
        $missing = $this->missingFields('telegram');

        if ($chats === []) {
            $missing[] = 'chat_id';
        }

        $url = str_replace('{token}', $token, (string) ($cfg['api_url'] ?? 'https://api.telegram.org/bot{token}/')) . 'sendMessage';

        return $this->placeholder('telegram', $missing, [
            'method'  => 'POST',
            'url'     => $url,
            'payload' => [
                'chat_id'    => $chats[0] ?? '',
                'text'       => $text,
                'parse_mode' => (string) ($options['parse_mode'] ?? $cfg['parse_mode'] ?? 'HTML'),
            ],
            'recipients' => $chats,
        ]);
    }

    private function sendWhatsapp(array $to, string $text, array $options): array {
        $cfg      = $this->channelConfig('whatsapp');
        $numberId = (string) ($cfg['phone_number_id'] ?? '');
        $targets  = $to !== [] ? $to : array_filter([(string) ($cfg['default_to'] ?? '')]);
        $missing  = $this->missingFields('whatsapp');

        if ($targets === []) {
            $missing[] = 'default_to';
        }

        $url = str_replace(
            '{phone_number_id}',
            $numberId,
            (string) ($cfg['api_url'] ?? 'https://graph.facebook.com/v20.0/{phone_number_id}/messages')
        );

        return $this->placeholder('whatsapp', $missing, [
            'method'  => 'POST',
            'url'     => $url,
            'headers' => ['Authorization: Bearer ' . ($cfg['token'] ?? '' ? '***' : '')],
            'payload' => [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $targets[0] ?? '',
                'type'              => 'text',
                'text'              => ['body' => $text],
            ],
            'recipients' => $targets,
        ]);
    }

    private function sendSignal(array $to, string $text, array $options): array {
        $cfg     = $this->channelConfig('signal');
        $targets = $to !== [] ? $to : array_filter((array) ($cfg['default_to'] ?? []));
        $missing = $this->missingFields('signal');

        if ($targets === []) {
            $missing[] = 'default_to';
        }

        return $this->placeholder('signal', $missing, [
            'method'  => 'POST',
            'url'     => rtrim((string) ($cfg['api_url'] ?? ''), '/') . '/v2/send',
            'payload' => [
                'message'    => $text,
                'number'     => (string) ($cfg['number'] ?? ''),
                'recipients' => array_values($targets),
            ],
            'recipients' => array_values($targets),
        ]);
    }

    private function sendMatrix(array $to, string $text, array $options): array {
        $cfg     = $this->channelConfig('matrix');
        $rooms   = $to !== [] ? $to : array_filter([(string) ($cfg['default_room'] ?? '')]);
        $missing = $this->missingFields('matrix');

        if ($rooms === []) {
            $missing[] = 'default_room';
        }

        $txnId = 'bfvc' . time() . bin2hex(random_bytes(4));
        $url   = rtrim((string) ($cfg['homeserver'] ?? ''), '/')
            . '/_matrix/client/v3/rooms/' . rawurlencode((string) ($rooms[0] ?? ''))
            . '/send/m.room.message/' . $txnId;

        return $this->placeholder('matrix', $missing, [
            'method'  => 'PUT',
            'url'     => $url,
            'headers' => ['Authorization: Bearer ***'],
            'payload' => [
                'msgtype' => (string) ($options['msgtype'] ?? 'm.text'),
                'body'    => $text,
            ],
            'recipients' => $rooms,
        ]);
    }

    private function placeholder(string $channel, array $missing, array $preview): array {
        $this->log("$channel: Platzhalter aufgerufen, nichts gesendet");

        return $this->result($channel, false, 'placeholder', [
            'error'      => "Kanal '$channel' ist ein Platzhalter - die API-Anbindung fehlt noch",
            'missing'    => array_values(array_unique($missing)),
            'configured' => $missing === [],
            'preview'    => $preview,
        ]);
    }

    private function configFromFile(): array {
        $config = [];
        if (defined('CONFIG') && isset(CONFIG['message']) && is_array(CONFIG['message'])) {
            $config = CONFIG['message'];
        } elseif (class_exists('cConfig')) {
            $config = (array) cConfig::get('message', []);
        }
        return $this->mergeDeep(self::defaults(), $config);
    }

    public static function defaults(): array {
        return [
            'default_channel' => 'mail',
            'crypt_key'       => '',
            'mail' => [
                'enabled'   => true,
                'from'      => '',
                'from_name' => '',
                'imap' => [
                    'host' => '', 'port' => 993, 'encryption' => 'ssl',
                    'user' => '', 'pass' => '', 'mailbox' => 'INBOX',
                    'timeout' => 15, 'validate_cert' => true,
                ],
                'smtp' => [
                    'host' => '', 'port' => 587, 'encryption' => 'tls',
                    'user' => '', 'pass' => '', 'auth' => true,
                    'timeout' => 15, 'validate_cert' => true,
                ],
            ],
            'telegram' => [
                'enabled' => false, 'bot_token' => '', 'chat_id' => '',
                'api_url' => 'https://api.telegram.org/bot{token}/', 'parse_mode' => 'HTML',
            ],
            'whatsapp' => [
                'enabled' => false, 'token' => '', 'phone_number_id' => '', 'default_to' => '',
                'api_url' => 'https://graph.facebook.com/v20.0/{phone_number_id}/messages',
            ],
            'signal' => [
                'enabled' => false, 'api_url' => 'http://127.0.0.1:8080',
                'number' => '', 'default_to' => [],
            ],
            'matrix' => [
                'enabled' => false, 'homeserver' => '', 'access_token' => '',
                'user_id' => '', 'default_room' => '',
            ],
        ];
    }

    private function recipients(string|array $to, string $channel): array {
        $list = $this->addressList($to);
        if ($list !== []) {
            return $list;
        }

        $cfg = $this->channelConfig($channel);
        return $this->addressList(match ($channel) {
            'mail'     => $cfg['default_to'] ?? '',
            'telegram' => $cfg['chat_id']    ?? '',
            'whatsapp' => $cfg['default_to'] ?? '',
            'signal'   => $cfg['default_to'] ?? '',
            'matrix'   => $cfg['default_room'] ?? '',
            default    => '',
        });
    }

    private function addressList(string|array $value): array {
        if (is_string($value)) {
            $value = preg_split('/[,;]+/', $value) ?: [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }

    private function joinSubject(string $subject, string $body): string {
        $subject = trim($subject);
        return $subject === '' ? $body : ($body === '' ? $subject : "$subject\n\n$body");
    }

    private function streamContext(array $cfg) {
        $verify = (bool) ($cfg['validate_cert'] ?? true);
        return stream_context_create([
            'ssl' => [
                'verify_peer'       => $verify,
                'verify_peer_name'  => $verify,
                'allow_self_signed' => !$verify,
                'SNI_enabled'       => true,
            ],
        ]);
    }

    private function result(string $channel, bool $success, string $status, array $extra = []): array {
        $out = array_merge([
            'success' => $success,
            'channel' => $channel,
            'status'  => $status,
            'error'   => null,
        ], $extra);

        if (!$success && !empty($out['error'])) {
            $this->lastError = (string) $out['error'];
        }
        return $out;
    }

    private function fail(string $message): ?string {
        $this->lastError = $message;
        $this->log($message);
        return $message;
    }

    private function log(string $message): void {
        if (class_exists('cLog')) {
            cLog::getInstance()->log('message.log', $message);
        }
    }

    private function dig(array $data, string $path): string {
        $node = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return '';
            }
            $node = $node[$key];
        }
        return is_scalar($node) ? trim((string) $node) : (is_array($node) && $node !== [] ? 'set' : '');
    }

    private function mergeDeep(array $base, array $override): array {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = $this->mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private const SECRET_KEYS = ['pass', 'password', 'token', 'bot_token', 'access_token', 'secret', 'api_key'];

    private function resolveCryptKey(): string {
        $key = trim((string) ($this->config['crypt_key'] ?? ''));

        if (str_starts_with($key, 'env:')) {
            $key = (string) getenv(substr($key, 4));
        }
        if ($key === '') {
            $key = (string) getenv('BFVC_CRYPT_KEY');
        }
        return $key;
    }

    private function crypt(): ?cCrypt {
        if ($this->cryptKey === '' || !class_exists('cCrypt')) {
            return null;
        }
        return $this->crypt ??= new cCrypt();
    }

    private function decodeSecrets(array $cfg): array {
        foreach ($cfg as $key => $value) {
            if (is_array($value)) {
                $cfg[$key] = $this->decodeSecrets($value);
            } elseif (is_string($value) && str_starts_with($value, 'enc:')) {
                $crypt = $this->crypt();
                $plain = $crypt?->decryptIt(substr($value, 4), $this->cryptKey);
                if ($plain === false || $plain === null) {
                    $this->log("Zugangsdaten '$key' konnten nicht entschluesselt werden (falscher crypt_key?)");
                    $cfg[$key] = '';
                } else {
                    $cfg[$key] = $plain;
                }
            }
        }
        return $cfg;
    }

    private function encodeSecrets(array $cfg): array {
        $crypt = $this->crypt();

        foreach ($cfg as $key => $value) {
            if (is_array($value)) {
                $cfg[$key] = $this->encodeSecrets($value);
            } elseif ($crypt !== null && is_string($value) && $value !== ''
                && in_array(strtolower((string) $key), self::SECRET_KEYS, true)
                && !str_starts_with($value, 'enc:')) {
                $encrypted = $crypt->encryptIt($value, $this->cryptKey);
                if ($encrypted !== false) {
                    $cfg[$key] = 'enc:' . $encrypted;
                }
            }
        }
        return $cfg;
    }

    private function maskSecrets(array $cfg): array {
        foreach ($cfg as $key => $value) {
            if (is_array($value)) {
                $cfg[$key] = $this->maskSecrets($value);
            } elseif (is_string($value) && $value !== '' && in_array(strtolower((string) $key), self::SECRET_KEYS, true)) {
                $cfg[$key] = '********';
            }
        }
        return $cfg;
    }

    private function normalizeEol(string $text): string {
        return preg_replace('/\r\n|\r|\n/', self::CRLF, $text);
    }

    private function boundary(string $prefix): string {
        return '=_' . $prefix . '_' . bin2hex(random_bytes(12));
    }

    private function mailerUrl(): ?string {
        $url = defined('CONFIG') ? (CONFIG['server']['mailer_url'] ?? '') : '';
        $url = trim((string) $url);
        return $url === '' ? null : $url;
    }
}
