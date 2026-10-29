<?php
/**
 * Mail on PHPMailer (spec 0.2, 1.8, 6.1). Owner: W4.
 *
 * Transactional mail (form notifications, confirmations, waitlist offers,
 * owner alerts) goes through the mail.tx.* settings: by default the Hostinger
 * mailbox website@icmtennis.com over SMTP, or the newsletter relay's SMTP
 * credentials when mail.tx.transport = relay. In development config
 * mail.transport = 'log' writes every message as an .eml file to
 * storage/mail-outbox/ instead of sending (tests read the headers there).
 *
 * Rules kept from NHF: no CR or LF ever reaches a header, and callers store
 * first, then send, then record the result, so a mail failure never loses data.
 *
 * Transactional cap (spec 6.1): mail_log rows for the mailbox over the rolling
 * 24 hours never exceed mail.tx.daily_cap. A message that would exceed it waits
 * as JSON in storage/mail-outbox-deferred/ and housekeeping retries it through
 * Mailer::retryDeferred() (staff notifications first), so nothing is dropped.
 *
 * The newsletter worker (W6) builds its own messages with Mailer::create('newsletter')
 * and sends them with Mailer::deliver(), which logs to mail_log the same way.
 */
defined('ICM') or exit;

use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /** mail_log.stream values (spec 3.2). */
    public const STREAMS = ['transactional', 'newsletter', 'test', 'confirm'];
    public const DEFAULT_FROM = 'no-reply@rielbuild.ca';
    public const DEFAULT_FROM_NAME = 'RIELBUILD website';
    public const DEFERRED_ATTEMPTS = 10;

    private static bool $loaded = false;

    // -----------------------------------------------------------------
    // Setup
    // -----------------------------------------------------------------

    /** Load vendor/autoload.php (PHPMailer) on first use. False when it is missing. */
    public static function vendor(): bool
    {
        if (self::$loaded) return true;
        $file = ROOT_DIR . '/vendor/autoload.php';
        if (!is_file($file)) return false;
        require_once $file;
        return self::$loaded = class_exists(PHPMailer::class);
    }

    /**
     * The transport in force for a profile.
     *   'tx':         log | smtp | relay | disabled   (setting mail.tx.transport, default smtp)
     *   'newsletter': log | smtp | api | disabled     (setting newsletter.transport)
     * Config mail.transport = 'log' (the developer config) forces 'log' for both.
     */
    public static function transport(string $profile = 'tx'): string
    {
        if (cfg('mail.transport') === 'log') return 'log';
        if ($profile === 'newsletter') {
            return match (site('newsletter.transport', 'disabled')) {
                'relay_smtp', 'hostinger_smtp' => 'smtp',
                'relay_api' => 'api',
                'log'       => 'log',
                default     => 'disabled',
            };
        }
        $t = site('mail.tx.transport', 'mail');
        return in_array($t, ['mail', 'smtp', 'log', 'disabled'], true) ? $t : 'mail';
    }

    public static function fromAddress(string $profile = 'tx'): string
    {
        $from = $profile === 'newsletter'
            ? site('newsletter.from_email', 'news@icmtennis.com')
            : site('mail.tx.from', self::DEFAULT_FROM);
        return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : self::DEFAULT_FROM;
    }

    public static function fromName(string $profile = 'tx'): string
    {
        $name = $profile === 'newsletter'
            ? site('newsletter.from_name', self::DEFAULT_FROM_NAME)
            : site('mail.tx.from_name', self::DEFAULT_FROM_NAME);
        return self::clean($name) ?: self::DEFAULT_FROM_NAME;
    }

    /**
     * SMTP settings for a profile: host, port, secure ('ssl' | 'tls' | ''), user, pass.
     * Transactional mail uses mail.tx.*, or newsletter.* when mail.tx.transport = relay.
     */
    public static function smtpSettings(string $profile = 'tx'): array
    {
        $relay = $profile === 'newsletter' || self::transport('tx') === 'relay';
        $p = $relay ? 'newsletter.' : 'mail.tx.';
        $secure = strtolower(site($p . 'secure', $relay ? 'tls' : 'ssl'));
        return [
            'host'   => site($p . 'host', ''),
            'port'   => (int)site($p . 'port', $relay ? '587' : '465'),
            'secure' => in_array($secure, ['ssl', 'tls'], true) ? $secure : '',
            'user'   => site($p . 'user', $relay ? '' : self::fromAddress('tx')),
            'pass'   => (string)Db::secret($p . 'pass'),
        ];
    }

    /** The mailbox that caps are counted against: the SMTP user, else the From address. */
    public static function mailbox(string $profile = 'tx'): string
    {
        if (in_array(self::transport($profile), ['smtp', 'relay'], true)) {
            $user = self::smtpSettings($profile)['user'];
            if ($user !== '') return mb_substr($user, 0, 191);
        }
        return self::fromAddress($profile);
    }

    /**
     * A PHPMailer configured for the profile (UTF-8, quoted-printable, From set,
     * SMTP settings applied when the transport is SMTP). Throws when PHPMailer
     * is not installed. W6 extends or configures it further (DKIM, list headers).
     */
    public static function create(string $profile = 'tx'): PHPMailer
    {
        if (!self::vendor()) throw new RuntimeException('PHPMailer is not installed (vendor/autoload.php is missing).');
        $m = new PHPMailer(true);
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $m->XMailer = ' ';
        $host = (string)(parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'icmtennis.com');
        $m->Hostname = $host;
        $m->MessageID = '<' . bin2hex(random_bytes(8)) . '.' . Time::ts() . '@' . $host . '>';
        $m->setFrom(self::fromAddress($profile), self::fromName($profile));

        $t = self::transport($profile);
        if ($t === 'smtp' || $t === 'relay') {
            $s = self::smtpSettings($profile);
            $m->isSMTP();
            $m->Host = $s['host'];
            $m->Port = $s['port'] > 0 ? $s['port'] : 587;
            $m->SMTPSecure = match ($s['secure']) {
                'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
                'tls'   => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $m->SMTPAuth = $s['user'] !== '';
            $m->Username = $s['user'];
            $m->Password = $s['pass'];
            $m->Timeout = 15;
        }
        if ($profile === 'tx' && in_array($t, ['relay', 'ses_api'], true)) $m->addCustomHeader('Feedback-ID', 'tx:icm:transactional');
        return $m;
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    /**
     * Send one transactional message. Keys:
     *   to        string or list of addresses (or [email, name] pairs)   required
     *   subject   string                                                   required
     *   html      string                                                   required
     *   text      plain-text part (default: htmlToText(html))
     *   reply_to, reply_name
     *   stream    transactional | confirm | test          (mail_log.stream, default transactional)
     *   priority  high (staff notifications, alerts) | low (confirmations, offers, autoreplies)
     *   headers   [name => value] extra headers
     *   attachments  [['name' => file name, 'type' => mime type, 'data' => base64]] (base64 so a deferred message keeps them)
     *   meta      ['notified_submission' => id] updates form_submissions.notified_ok after a deferred send
     * Returns ['ok' => bool, 'deferred' => bool, 'info' => string, 'message_id' => string].
     */
    public static function send(array $msg): array
    {
        $stream = in_array($msg['stream'] ?? '', self::STREAMS, true) ? (string)$msg['stream'] : 'transactional';
        $msg['stream'] = $stream;
        $msg['priority'] = ($msg['priority'] ?? ($stream === 'transactional' ? 'high' : 'low')) === 'high' ? 'high' : 'low';

        $to = self::recipients($msg['to'] ?? []);
        if (!$to) return self::result(false, 'invalid recipient');
        if (trim((string)($msg['subject'] ?? '')) === '' || trim((string)($msg['html'] ?? '')) === '') {
            return self::result(false, 'empty message');
        }
        if (self::transport('tx') === 'disabled') {
            Log::write('mail', 'transactional mail is disabled; not sent: ' . self::clean((string)$msg['subject']));
            return self::result(false, 'mail transport disabled');
        }
        if (empty($msg['_force']) && self::capReached()) return self::defer($msg);
        // The web host's own mail (PHP mail()), and the fallback when PHPMailer is not installed.
        if (self::transport('tx') === 'mail' || (self::transport('tx') === 'log' && !self::vendor()) || !self::vendor()) {
            return self::sendNative($msg, $to, $stream) + ['deferred' => false];
        }

        try {
            $m = self::create('tx');
            foreach ($to as [$email, $name]) $m->addAddress($email, $name);
            $reply = self::clean((string)($msg['reply_to'] ?? ''));
            if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) {
                $m->addReplyTo($reply, self::clean((string)($msg['reply_name'] ?? '')));
            }
            $m->Subject = self::clean((string)$msg['subject']);
            $m->isHTML(true);
            $m->Body = (string)$msg['html'];
            $m->AltBody = (string)(($msg['text'] ?? '') ?: self::htmlToText((string)$msg['html']));
            foreach ((array)($msg['headers'] ?? []) as $k => $v) {
                if (is_string($k) && preg_match('/^[A-Za-z0-9-]{1,60}$/', $k)) $m->addCustomHeader($k, self::clean((string)$v));
            }
            foreach ((array)($msg['attachments'] ?? []) as $a) {
                $data = is_array($a) ? base64_decode((string)($a['data'] ?? ''), true) : false;
                if ($data === false || $data === '') continue;
                $m->addStringAttachment($data, self::clean((string)($a['name'] ?? 'attachment')) ?: 'attachment', PHPMailer::ENCODING_BASE64, (string)($a['type'] ?? 'application/octet-stream'));
            }
        } catch (Throwable $e) {
            Log::write('mail', 'build failed: ' . $e->getMessage());
            return self::result(false, 'build failed: ' . $e->getMessage());
        }
        return self::deliver($m, $stream, 'tx') + ['deferred' => false];
    }

    /**
     * Deliver a prepared PHPMailer through the profile's transport and record
     * one mail_log row. 'log' writes storage/mail-outbox/*.eml.
     * Returns ['ok', 'info', 'message_id', 'file' (log transport only)].
     */
    public static function deliver(PHPMailer $m, string $stream = 'transactional', string $profile = 'tx'): array
    {
        $stream = in_array($stream, self::STREAMS, true) ? $stream : 'transactional';
        $t = self::transport($profile);
        $file = '';
        try {
            if ($t === 'disabled') {
                $ok = false;
                $info = 'mail transport disabled';
            } elseif ($t === 'api') {
                $ok = false;
                $info = 'the api transport is sent by the newsletter worker';
            } elseif ($t === 'log') {
                // The 'mail' mailer leaves To and Subject out of the headers (mail() adds them);
                // the outbox file must carry them, so build it as sendmail would.
                if ($m->Mailer === 'mail') $m->Mailer = 'sendmail';
                if (!$m->preSend()) throw new RuntimeException($m->ErrorInfo ?: 'preSend failed');
                $file = self::writeEml($m->getSentMIMEMessage(), $stream);
                $ok = $file !== '';
                $info = $ok ? 'log: ' . basename($file) : 'log: could not write the outbox file';
            } else {
                $ok = $m->send();
                $reply = '';
                try {
                    $reply = trim((string)$m->getSMTPInstance()->getLastReply());
                } catch (Throwable) {
                }
                $info = 'smtp: ' . ($reply !== '' ? $reply : 'accepted');
            }
        } catch (Throwable $e) {
            $ok = false;
            $info = ($m->ErrorInfo ?: $e->getMessage());
        }
        $to = array_map(fn($a) => (string)$a[0], $m->getToAddresses());
        self::logRow($stream, self::mailbox($profile), $to[0] ?? '', (string)$m->Subject, (bool)$ok, (string)$info);
        if (!$ok) Log::write('mail', 'FAILED ' . $stream . ' to ' . implode(',', $to) . ': ' . $info);
        return ['ok' => (bool)$ok, 'info' => (string)$info, 'message_id' => (string)$m->getLastMessageID(), 'file' => $file];
    }

    /**
     * Send one message without PHPMailer: a multipart/alternative (text + HTML) email through PHP mail(),
     * or the outbox file for the log transport. SMTP needs PHPMailer (vendor/), so without it SMTP falls
     * back to mail() too.
     */
    private static function sendNative(array $msg, array $to, string $stream): array
    {
        $from = self::fromAddress('tx');
        $name = self::fromName('tx');
        $enc = static fn(string $s): string => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
        $subject = self::clean((string)$msg['subject']);
        $html = (string)$msg['html'];
        $text = (string)(($msg['text'] ?? '') ?: self::htmlToText($html));
        $boundary = 'b' . bin2hex(random_bytes(12));
        $host = (string)(parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'localhost');
        $mid = '<' . bin2hex(random_bytes(8)) . '.' . Time::ts() . '@' . $host . '>';
        $headers = [
            'From: ' . $enc($name) . ' <' . $from . '>',
            'Message-ID: ' . $mid,
            'Date: ' . date(DATE_RFC2822),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $reply = self::clean((string)($msg['reply_to'] ?? ''));
        if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $enc(self::clean((string)($msg['reply_name'] ?? ''))) . ' <' . $reply . '>';
        foreach ((array)($msg['headers'] ?? []) as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Za-z0-9-]{1,60}$/', $k)) $headers[] = $k . ': ' . self::clean((string)$v);
        }
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
            . "--$boundary--\r\n";
        $toList = implode(', ', array_map(static fn(array $a): string => $a[1] !== '' ? $enc((string)$a[1]) . ' <' . $a[0] . '>' : (string)$a[0], $to));
        $file = '';
        if (self::transport('tx') === 'log') {
            $file = self::writeEml('To: ' . $toList . "\r\nSubject: " . $enc($subject) . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body, $stream);
            $ok = $file !== '';
            $info = $ok ? 'log: ' . basename($file) : 'log: could not write the outbox file';
        } else {
            $ok = @mail($toList, $enc($subject), $body, implode("\r\n", $headers), '-f' . $from);
            $info = $ok ? 'mail(): accepted' : 'mail(): refused by the server';
        }
        self::logRow($stream, $from, (string)($to[0][0] ?? ''), $subject, $ok, $info);
        if (!$ok) Log::write('mail', 'FAILED ' . $stream . ' to ' . $toList . ': ' . $info);
        return ['ok' => $ok, 'info' => $info, 'message_id' => $mid, 'file' => $file];
    }

    /** Messages sent (ok) from a mailbox in the last $seconds, from mail_log. */
    public static function sentInWindow(string $mailbox, int $seconds = 86400): int
    {
        try {
            return (int)Db::value(
                'SELECT COUNT(*) FROM mail_log WHERE mailbox = ? AND ok = 1 AND created_at >= ?',
                [$mailbox, Time::utcAgo($seconds)],
                0
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /** True when mail.tx.daily_cap is set and the transactional mailbox has reached it. */
    public static function capReached(): bool
    {
        $cap = Db::settingInt('mail.tx.daily_cap', 0);
        return $cap > 0 && self::sentInWindow(self::mailbox('tx')) >= $cap;
    }

    /**
     * Housekeeping (hourly): send deferred messages while the cap allows,
     * staff notifications first, then oldest first. Returns a summary.
     */
    public static function retryDeferred(int $max = 100): string
    {
        $dir = storagePath('mail-outbox-deferred');
        $files = glob($dir . '/*.json') ?: [];
        sort($files);   // "0-" (high) before "1-" (low), then by time
        $sent = $failed = 0;
        foreach ($files as $i => $file) {
            if ($i >= $max || self::capReached()) break;
            $data = json_decode((string)@file_get_contents($file), true);
            if (!is_array($data) || !is_array($data['msg'] ?? null)) {
                @rename($file, $file . '.bad');
                continue;
            }
            $r = self::send($data['msg'] + ['_force' => true]);
            if ($r['ok']) {
                @unlink($file);
                $sent++;
                $sid = (int)($data['msg']['meta']['notified_submission'] ?? 0);
                if ($sid > 0) {
                    try {
                        Db::update('form_submissions', ['notified_ok' => 1, 'updated_at' => Time::nowUtc()], 'id = ?', [$sid]);
                    } catch (Throwable $e) {
                        Log::exception($e, 'deferred notified_ok');
                    }
                }
                continue;
            }
            $data['attempts'] = (int)($data['attempts'] ?? 0) + 1;
            $data['last_error'] = mb_substr((string)$r['info'], 0, 300);
            if ($data['attempts'] >= self::DEFERRED_ATTEMPTS) {
                @file_put_contents($file, (string)json_encode($data));
                @rename($file, $file . '.failed');
                $failed++;
            } else {
                @file_put_contents($file, (string)json_encode($data), LOCK_EX);
            }
        }
        $waiting = count(glob($dir . '/*.json') ?: []);
        return $sent . ' sent, ' . $waiting . ' waiting' . ($failed ? ', ' . $failed . ' given up' : '');
    }

    /** Number of deferred messages waiting (the dashboard shows it). */
    public static function deferredCount(): int
    {
        return count(glob(storagePath('mail-outbox-deferred') . '/*.json') ?: []);
    }

    // -----------------------------------------------------------------
    // Text helpers
    // -----------------------------------------------------------------

    /** Plain text from an email's HTML: links become "text (url)", blocks become lines. */
    public static function htmlToText(string $html): string
    {
        $s = (string)preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1>#is', '', $html);
        $s = (string)preg_replace('#<span\b[^>]*class="[^"]*preheader[^"]*"[^>]*>.*?</span>#is', '', $s);
        $s = (string)preg_replace_callback('#<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $bare = (string)preg_replace('#^(mailto:|tel:)#i', '', $url);
            if ($text === '' || $text === $url || $text === $bare) return $text !== '' ? $text : $bare;
            return $text . ' (' . $url . ')';
        }, $s);
        $s = (string)preg_replace('#<br\s*/?>#i', "\n", $s);
        $s = (string)preg_replace('#<li\b[^>]*>#i', "\n- ", $s);
        $s = (string)preg_replace('#</(p|div|h[1-6]|tr|table|ul|ol|blockquote|section)>#i', "\n\n", $s);
        $s = (string)preg_replace('#</t[dh]>#i', '  ', $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = (string)preg_replace("/[ \t\x{00A0}]+/u", ' ', $s);
        $s = (string)preg_replace("/ *\n */", "\n", $s);
        $s = (string)preg_replace("/\n{3,}/", "\n\n", $s);
        return trim($s);
    }

    /** Header-safe text: CR, LF and NUL become spaces. */
    public static function clean(string $s): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
    }

    /** storage/mail-outbox (the log transport's folder). */
    public static function outboxDir(): string
    {
        return storagePath('mail-outbox');
    }

    /** Write a raw message to storage/mail-outbox/. Returns the file path, or '' on failure. */
    public static function writeEml(string $raw, string $stream = 'transactional'): string
    {
        $dir = self::outboxDir();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/' . gmdate('Ymd-His', Time::ts()) . '-' . preg_replace('/[^a-z]/', '', $stream) . '-' . bin2hex(random_bytes(4)) . '.eml';
        return @file_put_contents($file, $raw, LOCK_EX) === false ? '' : $file;
    }

    // -----------------------------------------------------------------

    /** @return list<array{0:string,1:string}> valid [email, name] pairs */
    private static function recipients(mixed $to): array
    {
        $list = is_array($to) ? $to : [$to];
        if (isset($list[0]) && is_string($list[0]) && isset($list[1]) && is_string($list[1]) && !filter_var($list[1], FILTER_VALIDATE_EMAIL) && count($list) === 2 && filter_var($list[0], FILTER_VALIDATE_EMAIL)) {
            $list = [$list];   // a single [email, name] pair
        }
        $out = [];
        foreach ($list as $item) {
            [$email, $name] = is_array($item) ? [(string)($item[0] ?? ''), (string)($item[1] ?? '')] : [(string)$item, ''];
            $email = self::clean($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($out[strtolower($email)])) {
                $out[strtolower($email)] = [$email, self::clean($name)];
            }
        }
        return array_values($out);
    }

    private static function defer(array $msg): array
    {
        $dir = storagePath('mail-outbox-deferred');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        unset($msg['_force']);
        $file = $dir . '/' . ($msg['priority'] === 'high' ? '0' : '1') . '-' . gmdate('YmdHis', Time::ts()) . '-' . bin2hex(random_bytes(4)) . '.json';
        $ok = @file_put_contents($file, (string)json_encode(['msg' => $msg, 'queued_at' => Time::nowUtc(), 'attempts' => 0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
        Log::write('mail', 'deferred (daily cap reached): ' . self::clean((string)($msg['subject'] ?? '')));
        return ['ok' => false, 'deferred' => $ok, 'info' => $ok ? 'deferred: daily cap reached' : 'deferral failed', 'message_id' => ''];
    }

    private static function result(bool $ok, string $info): array
    {
        return ['ok' => $ok, 'deferred' => false, 'info' => $info, 'message_id' => ''];
    }

    private static function logRow(string $stream, string $mailbox, string $to, string $subject, bool $ok, string $info): void
    {
        try {
            Db::insert('mail_log', [
                'stream'     => $stream,
                'mailbox'    => mb_substr($mailbox, 0, 191),
                'to_hash'    => hash_hmac('sha256', strtolower(trim($to)), (string)cfg('app_secret') ?: 'icm'),
                'subject'    => mb_substr($subject, 0, 200),
                'ok'         => $ok ? 1 : 0,
                'info'       => mb_substr(Log::redact($info), 0, 500),
                'created_at' => Time::nowUtc(),
            ]);
        } catch (Throwable $e) {
            Log::exception($e, 'mail_log');
        }
    }
}
