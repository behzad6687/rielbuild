<?php
/**
 * Notification and confirmation emails (spec 5.1 steps 4 and 5, 5.2.3). Owner: W4.
 *
 *   Notify::submission($id)        staff notification for a stored form submission:
 *                                  every field as label and value, the files by name
 *                                  (never attached), a link to the admin detail, and
 *                                  Reply-To set to the submitter. Records notified_ok.
 *   Notify::autoreply($id)         the optional transactional auto-reply (form_types).
 *   Notify::newsletterConfirm()    the double opt-in email with /newsletter/confirm/{token}/.
 *   Notify::owners($subject, $txt) an alert to every active owner (used by W6 on a paused campaign).
 *   Notify::test($type)            Admin > Inbox "Send a test notification" (W7).
 */
defined('ICM') or exit;

final class Notify
{
    public const FALLBACK_TO = 'contact@rielbuild.ca';

    public static function submission(int $id): bool
    {
        $sub = Db::one('SELECT * FROM form_submissions WHERE id = ?', [$id]);
        if (!$sub) return false;
        $type = (string)$sub['form_type'];
        $ft = Db::one('SELECT * FROM form_types WHERE type_key = ?', [$type]) ?? [];
        $payload = json_decode((string)$sub['payload_json'], true) ?: [];
        $files = Db::all('SELECT original_name, bytes FROM form_files WHERE submission_id = ? ORDER BY id', [$id]);

        $label = (string)(($ft['label'] ?? '') ?: (Forms::def($type)['label'] ?? ucfirst($type)));
        $subject = self::subjectFor($type, $payload, $sub, $label);
        $html = self::render('notification', [
            'title'    => $subject,
            'label'    => $label,
            'rows'     => Forms::displayRows($type, $payload),
            'files'    => $files,
            'adminUrl' => url('/admin/inbox/view?id=' . $id),
            'received' => Time::long((string)$sub['created_at'], true),
            'source'   => (string)($sub['source_path'] ?? ''),
            'replyTo'  => filter_var((string)$sub['email'], FILTER_VALIDATE_EMAIL) ? (string)$sub['email'] : '',
        ]);
        $r = Mailer::send([
            'to'         => self::recipients((string)($ft['recipients'] ?? '')),
            'subject'    => $subject,
            'html'       => $html,
            'reply_to'   => (string)$sub['email'],
            'reply_name' => (string)$sub['name'],
            'stream'     => 'transactional',
            'priority'   => 'high',
            'meta'       => ['notified_submission' => $id],
        ]);
        Db::update('form_submissions', ['notified_ok' => $r['ok'] ? 1 : 0, 'updated_at' => Time::nowUtc()], 'id = ?', [$id]);
        if (!$r['ok']) Log::write('forms', 'notification ' . ($r['deferred'] ? 'deferred' : 'FAILED') . " for #$id ($type): " . $r['info']);
        return $r['ok'];
    }

    /** The transactional auto-reply, when form_types.send_autoreply is on. Never marketing. */
    public static function autoreply(int $id): bool
    {
        $sub = Db::one('SELECT * FROM form_submissions WHERE id = ?', [$id]);
        if (!$sub || !filter_var((string)$sub['email'], FILTER_VALIDATE_EMAIL)) return false;
        $ft = Db::one('SELECT * FROM form_types WHERE type_key = ?', [(string)$sub['form_type']]);
        if (!$ft || (int)$ft['send_autoreply'] !== 1) return false;
        $subject = trim((string)$ft['autoreply_subject']);
        $body = trim((string)$ft['autoreply_body']);
        if ($subject === '' || $body === '') return false;
        $r = Mailer::send([
            'to'       => [[(string)$sub['email'], (string)$sub['name']]],
            'subject'  => $subject,
            'html'     => self::render('message', ['title' => $subject, 'paragraphs' => self::paragraphs($body)]),
            'text'     => $body . "\n\n" . self::identity(),
            'reply_to' => self::recipients((string)$ft['recipients'])[0] ?? '',
            'stream'   => 'transactional',
            'priority' => 'low',
        ]);
        return $r['ok'];
    }

    /**
     * The double opt-in email (spec 5.2.3 step 4): transactional, no promotion,
     * one link to /newsletter/confirm/{token}/ (the token is stored hashed and
     * expires 7 days after confirm_sent_at).
     */
    public static function newsletterConfirm(string $email, ?string $firstName, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new InvalidArgumentException('Bad confirm token');
        $link = url('/newsletter/confirm/' . $token . '/');
        $subject = 'Confirm your ' . siteName() . ' news subscription';
        return Mailer::send([
            'to'       => [[$email, (string)$firstName]],
            'subject'  => $subject,
            'html'     => self::render('confirmation', ['title' => $subject, 'first' => trim((string)$firstName), 'link' => $link]),
            'stream'   => 'confirm',
            'priority' => 'low',
        ]);
    }

    /** A plain alert to every active owner (falls back to email.main). */
    public static function owners(string $subject, string $text): bool
    {
        $to = [];
        try {
            $to = Db::column("SELECT email FROM admin_users WHERE role IN ('owner', 'master') AND is_active = 1 ORDER BY id");
        } catch (Throwable) {
        }
        if (!$to) $to = self::recipients('');
        $r = Mailer::send([
            'to'       => $to,
            'subject'  => $subject,
            'html'     => self::render('message', ['title' => $subject, 'paragraphs' => self::paragraphs($text)]),
            'text'     => $text,
            'stream'   => 'transactional',
            'priority' => 'high',
        ]);
        return $r['ok'];
    }

    /** Admin "Send a test notification" for a form type: goes to its recipients, stream test. */
    public static function test(string $type): array
    {
        $ft = Db::one('SELECT * FROM form_types WHERE type_key = ?', [$type]) ?? [];
        $label = (string)(($ft['label'] ?? '') ?: (Forms::def($type)['label'] ?? ucfirst($type)));
        $subject = 'Test notification: ' . $label;
        return Mailer::send([
            'to'       => self::recipients((string)($ft['recipients'] ?? '')),
            'subject'  => $subject,
            'html'     => self::render('notification', [
                'title' => $subject, 'label' => $label, 'rows' => [['Test', 'This is a test notification from the website.']],
                'files' => [], 'adminUrl' => url('/admin/inbox'), 'received' => Time::long(Time::nowUtc(), true),
                'source' => '', 'replyTo' => '',
            ]),
            'stream'   => 'test',
            'priority' => 'high',
        ]);
    }

    /**
     * Notification addresses: form_types.recipients (comma, semicolon or
     * whitespace separated), else the email.main setting, else info@icmtennis.com.
     * @return list<string>
     */
    public static function recipients(string $list): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $list) ?: [] as $a) {
            $a = trim($a);
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) $out[strtolower($a)] = $a;
        }
        if (!$out) {
            $main = site('email.main', self::FALLBACK_TO);
            $out[] = filter_var($main, FILTER_VALIDATE_EMAIL) ? $main : self::FALLBACK_TO;
        }
        return array_values($out);
    }

    /** "ICM Tennis, {address}, 647-787-0704, info@icmtennis.com" (address left out while empty). */
    public static function identity(): string
    {
        return Forms::identLine();
    }

    /** Render app/views/email/{name}.php to a string. */
    public static function render(string $name, array $vars = []): string
    {
        if (!preg_match('/^[a-z_-]+$/', $name)) throw new InvalidArgumentException('Bad email template');
        $file = APP_DIR . '/views/email/' . $name . '.php';
        if (!is_file($file)) throw new RuntimeException('Missing email template: ' . $name);
        $vars += ['site' => View::site(), 'identity' => self::identity(), 'baseUrl' => url('/')];
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                include $__file;
            })($file, $vars);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    // -----------------------------------------------------------------

    private static function subjectFor(string $type, array $payload, array $sub, string $label): string
    {
        $def = Forms::def($type);
        if ($def && isset($def['notify_subject']) && is_callable($def['notify_subject'])) {
            try {
                $s = trim((string)($def['notify_subject'])($payload, $sub));
                if ($s !== '') return mb_substr($s, 0, 190);
            } catch (Throwable $e) {
                Log::exception($e, 'notify subject');
            }
        }
        $who = trim((string)(($sub['name'] ?? '') ?: ($sub['email'] ?? '')));
        return mb_substr($label . ($who !== '' ? ': ' . $who : ''), 0, 190);
    }

    /** @return list<string> */
    private static function paragraphs(string $text): array
    {
        $parts = preg_split("/\n\s*\n/", str_replace(["\r\n", "\r"], "\n", trim($text))) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
    }
}
