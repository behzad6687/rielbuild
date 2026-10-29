<?php
/**
 * The public form engine (spec 5.1). Owner: W4.
 *
 * One definition per form in app/forms/{type}.php (contract in _dev/CONTRACTS.md
 * section 12). This class renders them (Forms::render), answers
 * GET /forms/token/{type}/ (Forms::token) and POST /forms/{type}/ (Forms::handle).
 *
 * Request flow for POST /forms/{type}/:
 *   body over post_max_size -> 413 "That file is too large (limit N MB)."
 *   unknown or disabled form -> 404; cross-origin Origin/Referer -> 400
 *   per-IP rate limit -> 429 (values re-rendered)
 *   stateless token: forged -> 400, young or expired -> 422 "Please press Send again"
 *   (values re-rendered with a fresh token); a filled honeypot is stored as spam
 *   and answered with the normal success
 *   validation -> 422 with field errors; per-email rate limit -> 429; files
 *   spam heuristics (score 60 and up -> status spam, no email, normal success)
 *   optional lock + capacity check (ranking sign-up) inside Db::tx() -> 409 / 503
 *   insert form_submissions, then form_files, then the after hook, then the
 *   staff notification (notified_ok), then the optional auto-reply
 *   JSON: {ok, message, id, type, analytics}; classic: 303 to {source}?sent={type}&r={receipt}#form
 * Errors on a classic POST re-render the source page (a published pages.path,
 * a /tennis-news/{slug}/ post or /waitlist/) with the old values and the status.
 */
defined('ICM') or exit;

final class Forms
{
    /** The seven stored forms (route R58). The certificate lookup is W3's and not here. */
    public const TYPES = ['contact'];
    public const MAX_AGE = 43200;   // 12 hours

    // Viewer copy (design brief 10.10, 11.15; spec 5.1, 5.2.9). No em dashes, Canadian spelling.
    public const MSG_SUMMARY  = 'That did not send. Please check the highlighted fields and try again.';
    public const MSG_AGAIN    = 'Please press Send again';
    public const MSG_RATE     = 'Too many tries. Please wait a few minutes or call us.';
    public const MSG_BUSY     = 'Busy, please try again.';
    public const MSG_FULL     = 'That session is full. Choose another date or join the waitlist.';
    public const MSG_EMAIL    = 'That email does not look right. Please check it.';
    public const MSG_PHONE    = 'Please enter a phone number with at least 10 digits.';
    public const MSG_PHONE_REQUIRED = 'Please add a phone number so we can call you back.';
    public const MSG_REQUIRED = 'Please fill in this field.';
    public const MSG_CHOOSE   = 'Please choose one.';
    public const MSG_TICK     = 'Please tick this box.';
    public const MSG_DATE     = 'Please enter a real date.';

    public const PRIVACY_DEFAULT = 'We use these details only to answer you.';
    public const PRIVACY_PATH = '/privacy-policy/';
    public const CONSENT_LABEL = 'Yes, send me news by email. I can unsubscribe at any time.';
    /** Allowed consent_source values posted by the newsletter form (_origin). */
    public const CONSENT_SOURCES = ['web_footer', 'web_home', 'web_post'];

    private const COLUMN_MAX = ['name' => 160, 'email' => 191, 'phone' => 40, 'subject' => 200];
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com',
        'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mailnesia.com', 'mintemail.com', 'spamgourmet.com',
        'emailondeck.com', 'mohmal.com', 'tempail.com',
    ];

    private static array $defs = [];
    private static array $fieldCache = [];
    private static array $state = [];        // type => [values, errors, message, fid]
    private static array $rendered = [];     // type => true once rendered on this response
    private static array $instances = [];    // type => render count on this response
    private static bool $anchorUsed = false;
    private static ?string $sourceOverride = null;
    private static array $memo = [];

    // =================================================================
    // Definitions
    // =================================================================

    /** The definition of a stored form type, or null. */
    public static function def(string $type): ?array
    {
        if (!in_array($type, self::TYPES, true)) return null;
        if (!array_key_exists($type, self::$defs)) {
            $file = APP_DIR . '/forms/' . $type . '.php';
            $d = is_file($file) ? require $file : null;
            if (is_array($d)) {
                $d['type'] = $type;
                $d['fields'] = array_values((array)($d['fields'] ?? []));
            }
            self::$defs[$type] = is_array($d) ? $d : null;
        }
        return self::$defs[$type];
    }

    /**
     * The resolved fields of a form: callables for label, hint and options are
     * evaluated, unavailable fields are dropped, options are normalised to
     * [value, label, disabled, data, group], and each field carries its step number.
     */
    public static function fields(string $type): array
    {
        if (isset(self::$fieldCache[$type])) return self::$fieldCache[$type];
        $def = self::def($type);
        if (!$def) return [];
        $out = [];
        $step = 0;
        foreach ($def['fields'] as $f) {
            if (($f['available'] ?? null) instanceof Closure && !($f['available'])()) continue;
            $f['type'] = (string)($f['type'] ?? 'text');
            if ($f['type'] === 'fieldset') {
                $step++;
                $f['key'] = (string)($f['key'] ?? ('step' . $step));
            }
            $f['key'] = (string)$f['key'];
            foreach (['label', 'hint', 'placeholder'] as $x) {
                $val = $f[$x] ?? '';
                $f[$x] = $val instanceof Closure ? (string)$val() : (string)$val;
            }
            $f['step'] = $step;
            if (in_array($f['type'], ['select', 'radio', 'checkboxes'], true)) {
                $o = $f['options'] ?? [];
                $f['options'] = self::normOptions($o instanceof Closure ? $o() : $o);
            }
            $f['messages'] = (array)($f['messages'] ?? []);
            $out[] = $f;
        }
        return self::$fieldCache[$type] = $out;
    }

    /** form_types.is_enabled for the type (true when the row does not exist yet). */
    public static function enabled(string $type): bool
    {
        $key = 'enabled.' . $type;
        if (!array_key_exists($key, self::$memo)) {
            try {
                $v = Db::value('SELECT is_enabled FROM form_types WHERE type_key = ?', [$type]);
                self::$memo[$key] = $v === null ? true : (int)$v === 1;
            } catch (Throwable) {
                self::$memo[$key] = false;
            }
        }
        return self::$memo[$key];
    }

    /** Insert the form_types row when it is missing (the FK needs it; seeds normally create it). */
    public static function ensureType(string $type, ?array $def = null): void
    {
        $key = 'ensured.' . $type;
        if (!empty(self::$memo[$key])) return;
        $def ??= self::def($type);
        Db::insertOrIgnore('form_types', [
            'type_key'       => $type,
            'label'          => mb_substr((string)($def['label'] ?? ucfirst($type)), 0, 80),
            'is_enabled'     => 1,
            'retention_days' => (int)($def['retention_days'] ?? 730),
            'updated_at'     => Time::nowUtc(),
        ]);
        self::$memo[$key] = true;
    }

    /** Forget cached definitions and render state (tests, long CLI runs). */
    public static function reset(): void
    {
        self::$defs = self::$fieldCache = self::$state = self::$memo = [];
        self::$sourceOverride = null;
        self::resetRender();
    }

    // =================================================================
    // Rendering
    // =================================================================

    /**
     * The form's HTML, or '' when the form is disabled or unavailable (for
     * example the newsletter while org.mailing_address is empty).
     * $prefill: [field key or its prefill query name => value].
     * $opts: hide (list of field keys), source (newsletter consent_source:
     *        web_home | web_footer | web_post), variant ('band' ...), button (label),
     *        privacy (line, '' for none).
     */
    public static function render(string $type, array $prefill = [], array $opts = []): string
    {
        try {
            $def = self::def($type);
            if (!$def || !self::enabled($type) || !self::available($def)) return '';
            $fid = self::$instances[$type] = (self::$instances[$type] ?? 0) + 1;
            $state = (self::$state[$type]['fid'] ?? null) === $fid ? self::$state[$type] : null;
            $sent = $state ? null : self::sentState($type, $fid);
            self::$rendered[$type] = true;

            $anchor = 'form-' . str_replace('_', '-', $type) . ($fid > 1 ? '-' . $fid : '');
            if (($state || $sent !== null) && !self::$anchorUsed) {
                $anchor = 'form';
                self::$anchorUsed = true;
            }
            $hide = array_map('strval', (array)($opts['hide'] ?? []));
            $fields = array_values(array_filter(self::fields($type), fn($f) => !in_array($f['key'], $hide, true)));
            $values = $state['values'] ?? self::initialValues($def, $fields, $prefill);
            $origin = in_array($opts['source'] ?? '', self::CONSENT_SOURCES, true) ? (string)$opts['source'] : '';

            return self::part('form', [
                'type'    => $type,
                'def'     => $def,
                'fields'  => $fields,
                'values'  => $values,
                'errors'  => (array)($state['errors'] ?? []),
                'message' => (string)($state['message'] ?? ''),
                'sent'    => $sent,
                'fid'     => $fid,
                'anchor'  => $anchor,
                'idp'     => 'f-' . str_replace('_', '-', $type) . ($fid > 1 ? $fid : '') . '-',
                'token'   => Csrf::publicToken($type),
                'source'  => self::currentPath(),
                'origin'  => $origin,
                'opts'    => $opts,
                'privacy' => self::privacyHtml($def, $opts),
                'button'  => (string)($opts['button'] ?? ($def['button'] ?? 'Send')),
            ]);
        } catch (Throwable $e) {
            Log::exception($e, 'Forms::render ' . $type);
            return '';
        }
    }

    /**
     * The newsletter band (design brief 10.10) for the home page, post footers
     * and the site footer: kicker, H2 and the one-line form. Returns '' (no gap)
     * while the form is closed by the CASL gate. $opts['source']: web_home |
     * web_footer | web_post (stored as consent_source).
     */
    public static function newsletterBand(array $opts = []): string
    {
        $form = self::render('newsletter', [], [
            'hide'    => ['first_name'],
            'source'  => $opts['source'] ?? 'web_home',
            'variant' => 'band',
        ]);
        if ($form === '') return '';
        return self::part('newsletter-band', ['form' => $form, 'opts' => $opts]);
    }

    /** True once a form of $type was rendered in this response. */
    public static function wasRendered(string $type): bool
    {
        return !empty(self::$rendered[$type]);
    }

    /** Render app/views/partials/forms/{name}.php to a string (used by the partials too). */
    public static function part(string $name, array $vars = []): string
    {
        if (!preg_match('/^[a-z-]+$/', $name)) throw new InvalidArgumentException('Bad form partial');
        $file = APP_DIR . '/views/partials/forms/' . $name . '.php';
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

    /**
     * Rows [label, value] for notifications, the admin inbox and CSV exports:
     * the display snapshot stored with the submission, else rebuilt from the definition.
     * @return list<array{0:string,1:string}>
     */
    public static function displayRows(string $type, array $payload): array
    {
        if (isset($payload['_display']) && is_array($payload['_display'])) {
            return array_values(array_filter(array_map(
                fn($r) => is_array($r) && count($r) >= 2 ? [(string)$r[0], (string)$r[1]] : null,
                $payload['_display']
            )));
        }
        return self::display(self::fields($type), $payload);
    }

    /** @return list<array{0:string,1:string}> */
    public static function display(array $fields, array $values, array $files = []): array
    {
        $rows = [];
        foreach ($fields as $f) {
            $k = $f['key'];
            $t = $f['type'];
            if ($t === 'fieldset') continue;
            $label = trim((string)($f['display'] ?? $f['label']));
            if ($label === '') $label = ucfirst(str_replace('_', ' ', $k));
            if ($t === 'file') {
                if (isset($files[$k]['original_name'])) $rows[] = [$label, (string)$files[$k]['original_name']];
                elseif (is_string($values[$k] ?? null) && $values[$k] !== '') $rows[] = [$label, $values[$k]];
                continue;
            }
            $val = $values[$k] ?? '';
            if ($t === 'checkbox') {
                if ($val === '1') $rows[] = [$label, 'Yes'];
                continue;
            }
            if (is_array($val)) {
                $val = implode(', ', array_map(fn($x) => self::optionLabel($f, (string)$x), $val));
            } elseif (in_array($t, ['select', 'radio'], true)) {
                $val = self::optionLabel($f, (string)$val);
            }
            $val = (string)$val;
            if (trim($val) !== '') $rows[] = [$label, $val];
        }
        return $rows;
    }

    // =================================================================
    // Endpoints
    // =================================================================

    /** GET /forms/token/{type}/: a fresh token as JSON, Cache-Control: no-store. */
    public static function token(string $type): void
    {
        if (!self::def($type)) Router::notFound(false);
        respondJson(['ok' => true, 'type' => $type, 'token' => Csrf::publicToken($type)]);
    }

    /** POST /forms/{type}/ */
    public static function handle(string $type): void
    {
        $json = self::wantsJson();
        $def = self::def($type);

        if (Upload::requestTooLarge()) {
            self::respondError($type, 413, 'too_large', Upload::tooLargeMessage(), [], [], '/', 1, $json);
        }
        if (!$def || !self::enabled($type) || !self::available($def)) Router::notFound(false);
        if (!Csrf::sameOrigin()) {
            if ($json) respondJson(['ok' => false, 'error' => 'origin', 'message' => self::MSG_AGAIN], 400);
            self::plain(400, "Bad request\n");
        }

        $fid = max(1, min(50, (int)($_POST['_fid'] ?? 1)));
        $source = self::sourcePath((string)($_POST['_src'] ?? ''));
        $fields = self::fields($type);
        $raw = self::collect($fields);

        if (isset($_POST['_step']) && !empty($def['steps'])) self::stepCheck($type, $def, $fields, $raw);

        [$ipMax, $ipWin] = self::rate($def, 'per_ip') ?? [5, 600];
        if (!RateLimit::hit('form:' . $type, clientIp(), $ipMax, $ipWin)) {
            self::respondError($type, 429, 'rate', self::MSG_RATE, $raw, [], $source, $fid, $json);
        }

        $t = $_POST['_t'] ?? null;
        $tokenState = Csrf::checkPublic(is_string($t) ? $t : null, $type, (int)($def['min_age'] ?? Csrf::MIN_AGE_DEFAULT), self::MAX_AGE);
        if ($tokenState === 'forged') {
            self::respondError($type, 400, 'forged', self::MSG_AGAIN, $raw, [], $source, $fid, $json);
        }

        $ctx = self::prepare($def, $raw, $source);

        // Honeypot: stored as spam, no email, the normal success (no signal to bots).
        if (trim((string)($_POST['website'] ?? '')) !== '') {
            $success = self::successText($def, $raw, $ctx);
            $row = self::row($type, $def, $fields, $raw, $ctx, [], $source, 'spam', 100, 'honeypot', '', $success);
            self::ensureType($type, $def);
            $id = Db::insert('form_submissions', $row);
            self::succeed($type, $id, $success, $source, $fid, $json, []);
        }

        if ($tokenState !== 'ok') {
            self::respondError($type, 422, 'token', self::MSG_AGAIN, $raw, [], $source, $fid, $json);
        }

        [$values, $errors] = self::validate($fields, $raw, $ctx);
        if (!$errors && ($def['rules'] ?? null) instanceof Closure) {
            $errors = array_filter((array)($def['rules'])($values, $ctx), fn($m) => is_string($m) && $m !== '');
        }
        if ($errors) self::respondError($type, 422, 'invalid', self::MSG_SUMMARY, $values, $errors, $source, $fid, $json);

        $email = self::storedEmail($def, $values, $ctx);
        $perEmail = self::rate($def, 'per_email');
        if ($email !== '' && $perEmail && !RateLimit::hit('form:' . $type . ':email', self::normEmail($email), $perEmail[0], $perEmail[1])) {
            self::respondError($type, 429, 'rate', self::MSG_RATE, $values, [], $source, $fid, $json);
        }

        $files = [];
        foreach ($fields as $f) {
            if ($f['type'] !== 'file') continue;
            $r = Upload::document((array)($_FILES[$f['key']] ?? []), [
                'max_bytes' => (int)($f['max_bytes'] ?? 5 * Upload::MB),
                'types'     => (array)($f['types'] ?? ['pdf', 'doc', 'docx']),
                'dir'       => (string)($f['dir'] ?? 'uploads'),
            ]);
            if (!empty($r['none'])) {
                if (self::isRequired($f, $values, $ctx)) $errors[$f['key']] = (string)($f['messages']['required'] ?? self::MSG_REQUIRED);
                continue;
            }
            if (!$r['ok']) {
                $errors[$f['key']] = (string)$r['error'];
                continue;
            }
            $files[$f['key']] = $r;
            $values[$f['key']] = $r['original_name'];
        }
        if ($errors) {
            self::dropFiles($files);
            self::respondError($type, 422, 'invalid', self::MSG_SUMMARY, $values, $errors, $source, $fid, $json);
        }

        [$score, $reason, $bodyHash] = self::spamCheck($type, $fields, $values, $email);
        $status = $score >= 60 ? 'spam' : 'new';
        $success = self::successText($def, $values, $ctx);
        $row = self::row($type, $def, $fields, $values, $ctx, $files, $source, $status, $score, $reason, $bodyHash, $success);

        $lock = null;
        if ($status !== 'spam' && ($def['lock'] ?? null) instanceof Closure) {
            $name = (string)($def['lock'])($values, $ctx);
            if ($name !== '') {
                $lock = Db::lock($name, 5);
                if (!$lock) {
                    self::dropFiles($files);
                    self::respondError($type, 503, 'busy', self::MSG_BUSY, $values, [], $source, $fid, $json);
                }
            }
        }
        try {
            self::ensureType($type, $def);
            $res = Db::tx(function () use ($def, $values, $ctx, $row, $files, $status): array {
                if ($status !== 'spam' && ($def['check'] ?? null) instanceof Closure) {
                    $blocked = ($def['check'])($values, $ctx);
                    if (is_array($blocked) && $blocked) return ['blocked' => $blocked];
                }
                $id = Db::insert('form_submissions', $row);
                foreach ($files as $f) {
                    Db::insert('form_files', [
                        'submission_id' => $id,
                        'stored_path'   => $f['path'],
                        'original_name' => mb_substr((string)$f['original_name'], 0, 200),
                        'mime'          => $f['mime'],
                        'bytes'         => (int)$f['bytes'],
                        'sha256'        => $f['sha256'],
                        'created_at'    => Time::nowUtc(),
                    ]);
                }
                return ['id' => $id];
            });
        } catch (Throwable $e) {
            self::dropFiles($files);
            $lock?->release();
            throw $e;
        }
        $lock?->release();

        if (isset($res['blocked'])) {
            self::dropFiles($files);
            $b = array_values($res['blocked']) + [409, 'full', self::MSG_FULL, null];
            [$code, $err, $msg, $field] = $b;
            self::respondError($type, (int)$code, (string)$err, (string)$msg, $values, $field ? [(string)$field => (string)$msg] : [], $source, $fid, $json);
        }
        $id = (int)$res['id'];

        if ($status !== 'spam') {
            if (($def['after'] ?? null) instanceof Closure) {
                try {
                    ($def['after'])($id, $values, $ctx);
                } catch (Throwable $e) {
                    Log::exception($e, "form after hook $type #$id");
                }
            }
            if (($def['notify'] ?? true) !== false) {
                try {
                    Notify::submission($id);
                } catch (Throwable $e) {
                    Log::exception($e, "form notification $type #$id");
                }
            }
            try {
                Notify::autoreply($id);
            } catch (Throwable $e) {
                Log::exception($e, "form autoreply $type #$id");
            }
        }
        self::succeed($type, $id, $success, $source, $fid, $json, self::analytics($type, $ctx));
    }

    // =================================================================
    // Newsletter (spec 5.2.3, 5.2.4) and CASL consent
    // =================================================================

    /** The CASL gate: the newsletter form (and the waitlist checkbox) render only with a mailing address. */
    public static function newsletterOpen(): bool
    {
        return trim(site('org.mailing_address')) !== '';
    }

    /** "ICM Tennis, {mailing address}, 647-787-0704, info@icmtennis.com" (empty parts left out). */
    public static function identLine(): string
    {
        $phone = site('phone.main', '+1 647-787-0704');
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
        if (strlen($d) === 10) $phone = substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6);
        $parts = [siteName(), trim(site('org.mailing_address')), $phone, site('email.main')];
        return implode(', ', array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
    }

    /**
     * The consent wording the person sees: the checkbox label (setting
     * newsletter.consent_label, default the design-brief line) and the
     * identification line. 'wording' is what consent_texts stores.
     */
    public static function consent(): array
    {
        $label = trim(site('newsletter.consent_label', self::CONSENT_LABEL)) ?: self::CONSENT_LABEL;
        $ident = self::identLine();
        return ['label' => $label, 'ident' => $ident, 'wording' => $label . "\n" . $ident];
    }

    /**
     * The consent_texts id of the wording shown right now. When the wording or
     * any identity value in it changed, a new row web-signup-{YYYY-MM-DD}
     * (then -2, -3 ...) is created, so every consent points at the exact text.
     */
    public static function consentTextId(): int
    {
        $w = self::consent()['wording'];
        $id = (int)Db::value('SELECT id FROM consent_texts WHERE wording = ? ORDER BY id DESC LIMIT 1', [$w], 0);
        if ($id > 0) return $id;
        $base = 'web-signup-' . Time::today();
        $v = $base;
        $n = 2;
        while (Db::value('SELECT 1 FROM consent_texts WHERE version = ?', [$v])) $v = $base . '-' . $n++;
        return Db::insert('consent_texts', ['version' => $v, 'wording' => $w, 'form_url' => url('/'), 'created_at' => Time::nowUtc()]);
    }

    public static function normEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Double opt-in (spec 5.2.3). Returns what happened:
     *   'sent'       confirmation email sent (new, unsubscribed, expired, review, or pending over 24 h)
     *   'pending'    pending and a confirmation went out in the last 24 hours: nothing sent
     *   'subscribed' already subscribed: left alone, nothing sent
     *   'suppressed' hard bounce or complaint on file: stored as pending, no email
     *   'held'       bounced or complained subscriber row: left alone, no email
     * The caller always shows the same success message.
     */
    public static function optIn(string $email, ?string $firstName, string $source, string $page): string
    {
        $norm = self::normEmail($email);
        if (!filter_var($norm, FILTER_VALIDATE_EMAIL)) return 'held';
        $now = Time::nowUtc();
        $ip = mb_substr(clientIp(), 0, 45);
        $ua = mb_substr(Sanitizer::text($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;
        $first = trim((string)$firstName) !== '' ? mb_substr(trim((string)$firstName), 0, 100) : null;

        $sub = Db::one('SELECT * FROM newsletter_subscribers WHERE email_norm = ?', [$norm]);
        if ($sub && $sub['status'] === 'subscribed') return 'subscribed';
        if ($sub && in_array($sub['status'], ['bounced', 'complained'], true)) return 'held';
        if ($sub && $sub['status'] === 'pending' && !empty($sub['confirm_sent_at']) && $sub['confirm_sent_at'] > Time::utcAgo(86400)) {
            return 'pending';
        }
        $reason = (string)Db::value('SELECT reason FROM suppressions WHERE email_norm = ?', [$norm], '');
        $hard = in_array($reason, ['hard_bounce', 'complaint', 'mailchimp_cleaned'], true);

        $consentId = self::consentTextId();
        $token = bin2hex(random_bytes(32));
        $data = [
            'email'              => mb_substr(trim($email), 0, 191),
            'first_name'         => $first ?? ($sub['first_name'] ?? null),
            'status'             => 'pending',
            'consent_type'       => 'express',
            'consent_at'         => $now,
            'consent_expires_at' => null,
            'consent_source'     => mb_substr($source, 0, 40),
            'consent_text_id'    => $consentId,
            'consent_ip'         => $ip,
            'consent_user_agent' => $ua,
            'consent_page'       => mb_substr($page, 0, 191),
            'confirm_token_hash' => hash('sha256', $token),
            'confirm_sent_at'    => $hard ? null : $now,
            'updated_at'         => $now,
        ];
        $wasOut = $sub && in_array($sub['status'], ['unsubscribed', 'expired', 'review'], true);
        Db::tx(function () use ($sub, $data, $norm, $now, $ip, $ua, $source, $page, $consentId, $wasOut): void {
            if ($sub) {
                Db::update('newsletter_subscribers', $data, 'id = ?', [(int)$sub['id']]);
                $sid = (int)$sub['id'];
            } else {
                $sid = Db::insert('newsletter_subscribers', $data + [
                    'email_norm'  => $norm,
                    'unsub_token' => bin2hex(random_bytes(32)),
                    'created_at'  => $now,
                ]);
            }
            $detail = json_encode(['consent_text_id' => $consentId, 'page' => $page], JSON_UNESCAPED_SLASHES);
            if ($wasOut) {
                Db::insert('consent_log', ['subscriber_id' => $sid, 'email_norm' => $norm, 'event' => 'resubscribe', 'at' => $now, 'ip' => $ip, 'user_agent' => $ua, 'source' => $source, 'detail_json' => $detail]);
            }
            Db::insert('consent_log', ['subscriber_id' => $sid, 'email_norm' => $norm, 'event' => 'optin', 'at' => $now, 'ip' => $ip, 'user_agent' => $ua, 'source' => $source, 'detail_json' => $detail]);
        });
        if ($hard) return 'suppressed';
        Notify::newsletterConfirm(trim($email), $first, $token);
        return 'sent';
    }

    /**
     * The unsubscribe form (spec 5.2.4): the subscriber, if any, becomes
     * unsubscribed (unsub_method form); the address is suppressed; the request
     * is logged. The same whether or not the address was on the list.
     */
    public static function unsubscribeEmail(string $email, string $method = 'form'): void
    {
        $norm = self::normEmail($email);
        if ($norm === '') return;
        $now = Time::nowUtc();
        $ip = mb_substr(clientIp(), 0, 45);
        $ua = mb_substr(Sanitizer::text($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;
        Db::tx(function () use ($norm, $now, $ip, $ua, $method): void {
            $sub = Db::one('SELECT id, status FROM newsletter_subscribers WHERE email_norm = ?', [$norm]);
            if ($sub && $sub['status'] !== 'unsubscribed') {
                Db::update('newsletter_subscribers', [
                    'status' => 'unsubscribed', 'unsubscribed_at' => $now, 'unsub_method' => mb_substr($method, 0, 10),
                    'confirm_token_hash' => null, 'updated_at' => $now,
                ], 'id = ?', [(int)$sub['id']]);
            }
            Db::insertOrIgnore('suppressions', ['email_norm' => $norm, 'reason' => 'unsubscribed', 'note' => 'Unsubscribe form', 'created_at' => $now]);
            Db::insert('consent_log', [
                'subscriber_id' => $sub ? (int)$sub['id'] : null, 'email_norm' => $norm, 'event' => 'unsubscribe',
                'at' => $now, 'ip' => $ip, 'user_agent' => $ua, 'source' => $method,
            ]);
        });
    }

    /** Merge values into a stored submission's payload _meta (after hooks record outcomes there). */
    public static function noteMeta(int $id, array $meta): void
    {
        $raw = Db::value('SELECT payload_json FROM form_submissions WHERE id = ?', [$id]);
        if ($raw === null) return;
        $p = json_decode((string)$raw, true) ?: [];
        $p['_meta'] = array_merge((array)($p['_meta'] ?? []), $meta);
        Db::update('form_submissions', ['payload_json' => (string)json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)], 'id = ?', [$id]);
    }

    // =================================================================
    // Helpers used by definitions and templates
    // =================================================================

    /**
     * source_path is never trusted (spec 5.1): only a site-relative path that is
     * a published pages.path, a published /tennis-news/{slug}/ post or /waitlist/.
     * Anything else becomes '/'.
     */
    public static function sourcePath(string $p): string
    {
        $p = trim($p);
        if ($p === '' || $p[0] !== '/' || str_starts_with($p, '//') || strlen($p) > 191) return '/';
        if (!preg_match('#^/[a-z0-9/_%.-]*$#', $p)) return '/';
        if ($p === '/') return $p;
        try {
            if (preg_match('#^/blog/([a-z0-9%][a-z0-9%_.-]*)/$#', $p, $m)) {
                $ok = Db::value("SELECT 1 FROM posts WHERE slug = ? AND status = 'published' AND published_at <= ?", [$m[1], Time::nowUtc()]);
                return $ok ? $p : '/';
            }
            return Db::value("SELECT 1 FROM pages WHERE path = ? AND status = 'published'", [$p]) ? $p : '/';
        } catch (Throwable) {
            return '/';
        }
    }

    /** Email domain has MX, A or AAAA records; skipped (true) when DNS itself fails, so it never blocks. */
    public static function emailDomainOk(string $email): bool
    {
        if (!Db::settingBool('forms.email_dns_check', true) || !function_exists('checkdnsrr')) return true;
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : strtolower(substr($email, $at + 1));
        if ($domain === '') return false;
        $key = 'dns.' . $domain;
        if (array_key_exists($key, self::$memo)) return self::$memo[$key];
        $ascii = function_exists('idn_to_ascii') ? (idn_to_ascii($domain) ?: $domain) : $domain;
        $ok = @checkdnsrr($ascii . '.', 'MX') || @checkdnsrr($ascii . '.', 'A') || @checkdnsrr($ascii . '.', 'AAAA');
        if (!$ok) {
            self::$memo['dns.works'] ??= (@checkdnsrr('rielbuild.ca.', 'A') || @checkdnsrr('google.com.', 'A'));
            if (!self::$memo['dns.works']) $ok = true;
        }
        return self::$memo[$key] = $ok;
    }

    public static function isDisposable(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) return false;
        $domain = strtolower(substr($email, $at + 1));
        if (!isset(self::$memo['disposable'])) {
            $list = self::DISPOSABLE;
            $file = APP_DIR . '/seed/disposable-domains.txt';
            if (is_file($file)) {
                foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = strtolower(trim($line));
                    if ($line !== '' && $line[0] !== '#') $list[] = $line;
                }
            }
            self::$memo['disposable'] = array_fill_keys($list, true);
        }
        $parts = explode('.', $domain);
        while (count($parts) >= 2) {
            if (isset(self::$memo['disposable'][implode('.', $parts)])) return true;
            array_shift($parts);
        }
        return false;
    }

    public static function optionLabel(array $f, string $value): string
    {
        foreach ((array)($f['options'] ?? []) as $o) {
            if (is_array($o) && ($o['value'] ?? null) === $value) return (string)$o['label'];
        }
        return $value;
    }

    // =================================================================
    // Internals
    // =================================================================

    private static function available(array $def): bool
    {
        return !(($def['available'] ?? null) instanceof Closure) || (bool)($def['available'])();
    }

    private static function resetRender(): void
    {
        self::$instances = [];
        self::$rendered = [];
        self::$anchorUsed = false;
    }

    private static function currentPath(): string
    {
        if (self::$sourceOverride !== null) return self::$sourceOverride;
        $p = class_exists('Router', false) ? Router::path() : '/';
        return $p !== '' ? $p : '/';
    }

    private static function wantsJson(): bool
    {
        return str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    /** @return array{0:int,1:int}|null */
    private static function rate(array $def, string $key): ?array
    {
        $r = $def['rate'][$key] ?? null;
        return is_array($r) && count($r) === 2 ? [max(1, (int)$r[0]), max(1, (int)$r[1])] : null;
    }

    private static function normOptions(mixed $o): array
    {
        if (!is_array($o)) return [];
        $out = [];
        $isList = array_is_list($o);
        foreach ($o as $k => $v) {
            if (is_array($v) && !array_key_exists('label', $v)) {        // an optgroup: label => options
                $inner = array_is_list($v);
                foreach ($v as $k2 => $v2) $out[] = self::opt($inner && !is_array($v2) ? $v2 : $k2, $v2, (string)$k);
                continue;
            }
            $out[] = self::opt($isList && !is_array($v) ? $v : $k, $v, null);
        }
        return $out;
    }

    private static function opt(mixed $value, mixed $v, ?string $group): array
    {
        if (is_array($v)) {
            return [
                'value' => (string)($v['value'] ?? $value), 'label' => (string)$v['label'], 'disabled' => !empty($v['disabled']),
                'data' => (array)($v['data'] ?? []), 'group' => $group,
            ];
        }
        return ['value' => (string)$value, 'label' => (string)$v, 'disabled' => false, 'data' => [], 'group' => $group];
    }

    private static function optionAllowed(array $f, string $value): bool
    {
        foreach ($f['options'] as $o) {
            if ($o['value'] === $value) return !$o['disabled'] || !empty($f['allow_disabled']);
        }
        return false;
    }

    private static function isRequired(array $f, array $values, array $ctx): bool
    {
        $r = $f['required'] ?? false;
        return $r instanceof Closure ? (bool)$r($values, $ctx) : (bool)$r;
    }

    /** Initial values: defaults, then query-string prefill, then the caller's $prefill, then the definition's prefill hook. */
    private static function initialValues(array $def, array $fields, array $prefill): array
    {
        $v = [];
        foreach ($fields as $f) {
            $k = $f['key'];
            $t = $f['type'];
            if (in_array($t, ['fieldset', 'file'], true)) continue;
            $d = $f['default'] ?? ($t === 'checkboxes' ? [] : '');
            $val = $d instanceof Closure ? $d() : $d;
            $q = (string)($f['prefill'] ?? '');
            if ($q !== '' && isset($_GET[$q]) && is_string($_GET[$q])) $val = Sanitizer::text($_GET[$q], false, 120);
            if ($q !== '' && array_key_exists($q, $prefill)) $val = $prefill[$q];
            if (array_key_exists($k, $prefill)) $val = $prefill[$k];
            $v[$k] = is_array($val) ? array_map('strval', $val) : (string)$val;
        }
        if (($def['prefill'] ?? null) instanceof Closure) $v = (array)($def['prefill'])($v) + $v;
        foreach ($fields as $f) {
            if (in_array($f['type'], ['select', 'radio'], true) && ($v[$f['key']] ?? '') !== '' && !self::optionAllowed($f, (string)$v[$f['key']])) {
                $v[$f['key']] = '';
            }
        }
        return $v;
    }

    /** Posted values, sanitised but not validated (control characters stripped, lengths capped). */
    private static function collect(array $fields): array
    {
        $v = [];
        foreach ($fields as $f) {
            $k = $f['key'];
            $t = $f['type'];
            if (in_array($t, ['fieldset', 'file'], true)) continue;
            $raw = $_POST[$k] ?? null;
            $cap = max(1, (int)($f['max'] ?? ($t === 'textarea' ? 4000 : 300))) + 100;
            switch ($t) {
                case 'checkbox':
                    $v[$k] = (is_string($raw) && $raw !== '' && $raw !== '0') ? '1' : '';
                    break;
                case 'checkboxes':
                    $items = is_array($raw) ? array_slice(array_filter($raw, 'is_string'), 0, 50) : [];
                    $v[$k] = array_values(array_unique(array_map(fn($x) => Sanitizer::text($x, false, 200), $items)));
                    break;
                case 'textarea':
                    $v[$k] = Sanitizer::text($raw, true, $cap);
                    break;
                default:
                    $v[$k] = Sanitizer::text($raw, false, $cap);
            }
        }
        return $v;
    }

    private static function prepare(array $def, array $raw, string $source): array
    {
        $origin = (string)($_POST['_origin'] ?? '');
        $ctx = ['_source' => $source, '_origin' => in_array($origin, self::CONSENT_SOURCES, true) ? $origin : ''];
        if (($def['prepare'] ?? null) instanceof Closure) {
            try {
                $ctx = (array)($def['prepare'])($raw) + $ctx;
            } catch (Throwable $e) {
                Log::exception($e, 'form prepare ' . ($def['type'] ?? '?'));
            }
        }
        return $ctx;
    }

    /**
     * Validate every field (or one step). Returns [values, errors].
     * Hidden context fields that fail their check are cleared silently.
     */
    private static function validate(array $fields, array $v, array $ctx, ?int $onlyStep = null): array
    {
        $errors = [];
        foreach ($fields as $f) {
            $k = $f['key'];
            $t = $f['type'];
            if ($t === 'fieldset' || $t === 'file') continue;
            if ($onlyStep !== null && (int)$f['step'] !== $onlyStep) continue;
            $val = $v[$k] ?? ($t === 'checkboxes' ? [] : '');

            if ($t === 'hidden_ctx') {
                if ($val !== '' && ($f['validate'] ?? null) instanceof Closure) {
                    $nv = ($f['validate'])((string)$val, $v);
                    $v[$k] = is_string($nv) ? $nv : '';
                }
                continue;
            }

            $empty = is_array($val) ? !$val : $val === '';
            if ($empty) {
                if (self::isRequired($f, $v, $ctx)) $errors[$k] = (string)($f['messages']['required'] ?? self::requiredMessage($t));
                continue;
            }
            $bad = (string)($f['messages']['invalid'] ?? '');
            $max = isset($f['max']) ? (int)$f['max'] : 0;
            $min = isset($f['min']) ? (int)$f['min'] : 0;

            switch ($t) {
                case 'email':
                    if (mb_strlen($val) > min(191, $max ?: 191) || !filter_var($val, FILTER_VALIDATE_EMAIL)
                        || (($f['dns'] ?? true) && !self::emailDomainOk($val))) {
                        $errors[$k] = $bad ?: self::MSG_EMAIL;
                    }
                    break;
                case 'tel':
                    $d = preg_replace('/\D+/', '', $val) ?? '';
                    if (strlen($d) < 10 || strlen($d) > 15 || mb_strlen($val) > 40) $errors[$k] = $bad ?: self::MSG_PHONE;
                    break;
                case 'select':
                case 'radio':
                    if (!self::optionAllowed($f, (string)$val)) $errors[$k] = $bad ?: self::MSG_CHOOSE;
                    break;
                case 'checkboxes':
                    foreach ($val as $x) {
                        if (!self::optionAllowed($f, (string)$x)) {
                            $errors[$k] = $bad ?: self::MSG_CHOOSE;
                            break;
                        }
                    }
                    break;
                case 'checkbox':
                    break;
                case 'date':
                    $okDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
                    if (!$okDate || (!empty($f['past']) && $val >= Time::today()) || $val < '1900-01-01') $errors[$k] = $bad ?: self::MSG_DATE;
                    break;
                case 'number':
                    $lo = (int)($f['min_value'] ?? PHP_INT_MIN);
                    $hi = (int)($f['max_value'] ?? PHP_INT_MAX);
                    if (!preg_match('/^\d{1,9}$/', $val) || (int)$val < $lo || (int)$val > $hi) {
                        $errors[$k] = $bad ?: (isset($f['min_value'], $f['max_value'])
                            ? 'Please enter a number from ' . $f['min_value'] . ' to ' . $f['max_value'] . '.'
                            : 'Please enter a number.');
                    }
                    break;
                default:   // text, textarea
                    if ($max > 0 && mb_strlen($val) > $max) {
                        $errors[$k] = 'Please keep this to ' . $max . ' characters or fewer.';
                    } elseif ($min > 0 && mb_strlen($val) < $min) {
                        $errors[$k] = 'Please add at least ' . $min . ' characters.';
                    } elseif (!empty($f['pattern']) && !preg_match('/^(?:' . str_replace('/', '\/', (string)$f['pattern']) . ')$/u', $val)) {
                        $errors[$k] = $bad ?: self::MSG_REQUIRED;
                    }
            }
        }
        return [$v, $errors];
    }

    private static function requiredMessage(string $type): string
    {
        return match ($type) {
            'email'                        => self::MSG_EMAIL,
            'tel'                          => self::MSG_PHONE_REQUIRED,
            'checkbox'                     => self::MSG_TICK,
            'select', 'radio', 'checkboxes' => self::MSG_CHOOSE,
            default                        => self::MSG_REQUIRED,
        };
    }

    /** The email column value (valid addresses only), for the per-email rate limit and Reply-To. */
    private static function storedEmail(array $def, array $values, array $ctx): string
    {
        $spec = $def['store']['email'] ?? null;
        $val = $spec instanceof Closure ? $spec($values, $ctx) : (is_string($spec) ? ($values[$spec] ?? '') : '');
        $val = trim((string)$val);
        return filter_var($val, FILTER_VALIDATE_EMAIL) ? $val : '';
    }

    /**
     * Spam heuristics (spec 5.1), a score 0 to 100: more than 3 URLs in the free
     * text (60), a message that is only a URL (60), the same text body 3 times in
     * an hour from different IPs (60), a disposable email domain (40).
     * @return array{0:int,1:string,2:string} score, reason, body hash
     */
    private static function spamCheck(string $type, array $fields, array $values, string $email): array
    {
        $score = 0;
        $why = [];
        $free = '';
        $body = '';
        foreach ($fields as $f) {
            $val = $values[$f['key']] ?? null;
            if (!is_string($val) || $val === '') continue;
            if (in_array($f['type'], ['text', 'textarea'], true)) $free .= ' ' . $val;
            if ($f['type'] === 'textarea') {
                $body .= "\n" . $val;
                if (preg_match('#^(https?://|www\.)\S+$#i', trim($val)) && !in_array('link only', $why, true)) {
                    $score += 60;
                    $why[] = 'link only';
                }
            }
        }
        if (preg_match_all('#(https?://|www\.)\S+#i', $free) > 3) {
            $score += 60;
            $why[] = 'links';
        }
        if ($email !== '' && self::isDisposable($email)) {
            $score += 40;
            $why[] = 'disposable email';
        }
        $norm = trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($body)));
        $hash = mb_strlen($norm) >= 20 ? substr(hash('sha256', $norm), 0, 32) : '';
        if ($hash !== '') {
            try {
                $ips = Db::column(
                    'SELECT ip_hash FROM form_submissions WHERE form_type = ? AND created_at >= ? AND payload_json LIKE ?' . Db::LIKE_ESCAPE,
                    [$type, Time::utcAgo(3600), '%' . Db::like('"_bh":"' . $hash . '"') . '%']
                );
                $distinct = array_unique(array_filter(array_merge($ips, [ipHash()])));
                if (count($ips) >= 2 && count($distinct) >= 2) {
                    $score += 60;
                    $why[] = 'repeated text';
                }
            } catch (Throwable $e) {
                Log::exception($e, 'spam check');
            }
        }
        return [min(100, $score), implode(', ', $why), $hash];
    }

    /** The form_submissions row. */
    private static function row(string $type, array $def, array $fields, array $values, array $ctx, array $files, string $source, string $status, int $score, string $reason, string $bodyHash, string $success): array
    {
        $store = (array)($def['store'] ?? []);
        $col = static function (string $c) use ($store, $values, $ctx): mixed {
            $s = $store[$c] ?? null;
            if ($s === null) return null;
            $val = $s instanceof Closure ? $s($values, $ctx) : ($values[(string)$s] ?? null);
            if (is_array($val)) $val = implode(', ', $val);
            return $val === '' ? null : $val;
        };
        $str = static fn(mixed $v, int $max): ?string => ($v === null || trim((string)$v) === '') ? null : mb_substr(trim((string)$v), 0, $max);

        $email = $str($col('email'), self::COLUMN_MAX['email']);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = null;
        $phone = $col('phone');
        $phone = ($phone !== null && strlen((string)preg_replace('/\D+/', '', (string)$phone)) >= 10) ? e164((string)$phone) : null;
        $int = static fn(mixed $v): ?int => ($v === null || (int)$v <= 0) ? null : (int)$v;
        $date = $col('event_date');

        $payload = [];
        foreach ($values as $k => $val) {
            if (!str_starts_with((string)$k, '_')) $payload[$k] = $val;
        }
        $payload['_display'] = self::display($fields, $values, $files);
        $payload['_meta'] = ['success' => $success];
        if ($bodyHash !== '') $payload['_bh'] = $bodyHash;

        return [
            'form_type'         => $type,
            'status'            => $status,
            'name'              => $str($col('name'), self::COLUMN_MAX['name']),
            'email'             => $email,
            'phone'             => $phone !== null ? mb_substr($phone, 0, self::COLUMN_MAX['phone']) : null,
            'subject'           => $str($col('subject'), self::COLUMN_MAX['subject']),
            'location_id'       => $int($col('location_id')),
            'course_id'         => $int($col('course_id')),
            'ranking_series_id' => $int($col('ranking_series_id')),
            'event_date'        => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            'payload_json'      => (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'spam_score'        => max(0, min(100, $score)),
            'spam_reason'       => $reason !== '' ? mb_substr($reason, 0, 120) : null,
            'source_path'       => mb_substr($source, 0, 191),
            'ip_hash'           => ($def['store_ip'] ?? true) === false ? null : ipHash(),
            'user_agent'        => mb_substr(Sanitizer::text($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            'notified_ok'       => null,
            'created_at'        => Time::nowUtc(),
        ];
    }

    private static function successText(array $def, array $values, array $ctx): string
    {
        $s = $def['success'] ?? 'Thank you.';
        try {
            $s = $s instanceof Closure ? (string)$s($values, $ctx) : (string)$s;
        } catch (Throwable $e) {
            Log::exception($e, 'form success text');
            $s = (string)($def['success_fallback'] ?? 'Thank you.');
        }
        return $s;
    }

    private static function analytics(string $type, array $ctx): array
    {
        return array_filter([
            'form_type'   => $type,
            'course_code' => $ctx['course']['code'] ?? null,
            'location'    => $ctx['location']['slug'] ?? null,
        ], fn($v) => $v !== null && $v !== '');
    }

    private static function dropFiles(array $files): void
    {
        foreach ($files as $f) Upload::remove((string)($f['path'] ?? ''));
    }

    private static function receiptSig(int $id, string $type, int $fid): string
    {
        return substr(hash_hmac('sha256', 'receipt|' . $type . '|' . $id . '|' . $fid, (string)cfg('app_secret')), 0, 20);
    }

    private static function receipt(int $id, string $type, int $fid): string
    {
        return $id . '.' . $fid . '.' . self::receiptSig($id, $type, $fid);
    }

    /** The success message for a valid ?sent={type}&r={receipt} on this form instance, else null. */
    private static function sentState(string $type, int $fid): ?string
    {
        if (($_GET['sent'] ?? '') !== $type) return null;
        $r = $_GET['r'] ?? '';
        if (!is_string($r) || !preg_match('/^(\d{1,10})\.(\d{1,2})\.([a-f0-9]{20})$/', $r, $m)) return null;
        if ((int)$m[2] !== $fid || !hash_equals(self::receiptSig((int)$m[1], $type, (int)$m[2]), $m[3])) return null;
        $row = Db::one('SELECT payload_json, created_at FROM form_submissions WHERE id = ? AND form_type = ?', [(int)$m[1], $type]);
        if (!$row || (string)$row['created_at'] < Time::utcAgo(7200)) return null;
        $p = json_decode((string)$row['payload_json'], true);
        $msg = is_array($p) ? (string)($p['_meta']['success'] ?? '') : '';
        return $msg !== '' ? $msg : (string)(self::def($type)['success_fallback'] ?? 'Thank you.');
    }

    private static function privacyHtml(array $def, array $opts): string
    {
        $text = array_key_exists('privacy', $opts) ? $opts['privacy'] : ($def['privacy'] ?? self::PRIVACY_DEFAULT);
        if ($text === null || $text === false || trim((string)$text) === '') return '';
        $html = e((string)$text);
        if (!array_key_exists('privacy.published', self::$memo)) {
            try {
                self::$memo['privacy.published'] = (bool)Db::value("SELECT 1 FROM pages WHERE path = ? AND status = 'published'", [self::PRIVACY_PATH]);
            } catch (Throwable) {
                self::$memo['privacy.published'] = false;
            }
        }
        if (self::$memo['privacy.published']) $html .= ' See our <a href="' . e(self::PRIVACY_PATH) . '">privacy policy</a>.';
        return $html;
    }

    /** Volunteer step check: POST with _step=N validates one step as JSON and stores nothing. */
    private static function stepCheck(string $type, array $def, array $fields, array $raw): never
    {
        $step = max(1, (int)$_POST['_step']);
        $t = $_POST['_t'] ?? null;
        if (Csrf::checkPublic(is_string($t) ? $t : null, $type, 0, self::MAX_AGE) === 'forged') {
            respondJson(['ok' => false, 'error' => 'forged', 'message' => self::MSG_AGAIN, 'token' => Csrf::publicToken($type)], 400);
        }
        if (!RateLimit::hit('form:' . $type . ':step', clientIp(), 120, 600)) {
            respondJson(['ok' => false, 'error' => 'rate', 'message' => self::MSG_RATE], 429, ['Retry-After: ' . RateLimit::retryAfter()]);
        }
        $ctx = self::prepare($def, $raw, '/');
        [, $errors] = self::validate($fields, $raw, $ctx, $step);
        if ($errors) respondJson(['ok' => false, 'error' => 'invalid', 'message' => self::MSG_SUMMARY, 'errors' => $errors, 'step' => $step], 422);
        respondJson(['ok' => true, 'step' => $step]);
    }

    private static function succeed(string $type, int $id, string $message, string $source, int $fid, bool $json, array $analytics): never
    {
        if ($json) respondJson(['ok' => true, 'message' => $message, 'id' => $id, 'type' => $type, 'analytics' => $analytics ?: ['form_type' => $type]]);
        Headers::noStore();
        redirect(url($source) . '?sent=' . rawurlencode($type) . '&r=' . self::receipt($id, $type, $fid) . '#form', 303);
    }

    /**
     * An error answer. JSON: {ok:false, error, message, errors?, token}. Classic:
     * the source page re-rendered with the values, the errors and a fresh token.
     */
    private static function respondError(string $type, int $code, string $error, string $message, array $values, array $errors, string $source, int $fid, bool $json): never
    {
        $headers = $code === 429 ? ['Retry-After: ' . RateLimit::retryAfter()] : ($code === 503 ? ['Retry-After: 5'] : []);
        if ($json) {
            $out = ['ok' => false, 'error' => $error, 'message' => $message];
            if ($errors) $out['errors'] = $errors;
            if (self::def($type)) {
                try {
                    $out['token'] = Csrf::publicToken($type);
                } catch (Throwable) {
                }
            }
            respondJson($out, $code, $headers);
        }
        foreach ($headers as $h) header($h);
        self::$state[$type] = ['values' => $values, 'errors' => $errors, 'message' => $message, 'fid' => $fid];
        self::renderPage($type, $source, $code);
        exit;
    }

    /**
     * Re-render the page the form was posted from, with the form's state. When
     * that page does not render this form, a small standalone page does instead.
     */
    private static function renderPage(string $type, string $source, int $status): void
    {
        self::resetRender();
        self::$sourceOverride = $source;
        View::setRequestPath($source);
        $html = null;
        if (self::def($type)) {
            $level = ob_get_level();
            ob_start();
            try {
                http_response_code($status);
                if (preg_match('#^/blog/([a-z0-9%][a-z0-9%_.-]*)/$#', $source, $m)) {
                    $post = Db::one("SELECT * FROM posts WHERE slug = ? AND status = 'published' AND published_at <= ? LIMIT 1", [$m[1], Time::nowUtc()]);
                    if ($post) (new View())->render('pages/post', ['post' => $post, 'route' => 'post', 'params' => [$m[1]]]);
                } else {
                    $page = Db::one("SELECT * FROM pages WHERE path = ? AND status = 'published' LIMIT 1", [$source]);
                    if ($page) View::page($page);
                }
                $out = '';
                while (ob_get_level() > $level) $out = (string)ob_get_clean() . $out;
                if (!empty(self::$rendered[$type])) $html = $out;
            } catch (Throwable $e) {
                while (ob_get_level() > $level) ob_end_clean();
                Log::exception($e, 'form re-render ' . $source);
            }
        }
        if ($html !== null) {
            echo $html;
            return;
        }
        self::resetRender();
        http_response_code($status);
        (new View())->render('partials/forms/standalone', [
            'route'       => 'form',
            'formType'    => $type,
            'formDef'     => self::def($type),
            'formMessage' => (string)(self::$state[$type]['message'] ?? ''),
        ]);
    }

    private static function plain(int $code, string $text): never
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $text;
        exit;
    }
}
