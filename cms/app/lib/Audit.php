<?php
/**
 * The audit trail (spec 7.2, 4.1.3): sign-ins, sign-outs, failures and every
 * admin change land in audit_log. Secrets never do: any detail key that looks
 * like a password, token, key or hash is replaced before it is stored.
 */
defined('ICM') or exit;

final class Audit
{
    /** Detail keys whose values are never stored. */
    private const SECRET_KEY = '/(pass(word)?|secret|token|api_key|validator|code_hash|pass_hash|dkim_private|totp)/i';

    /**
     * One entry. $action: create|update|delete|publish|unpublish|send|login|logout|export|import|status ...
     * (at most 24 characters); $entity is usually the table name. $detail is stored as JSON,
     * typically Audit::diff() output ({"field":[old,new]}).
     */
    public static function log(?int $userId, string $action, string $entity, ?string $entityId, string $summary, array $detail = []): void
    {
        try {
            $action = substr((string)preg_replace('/[^a-z0-9_.-]/', '', strtolower($action)), 0, 24) ?: 'change';
            Db::insert('audit_log', [
                'user_id'     => $userId ?: null,
                'action'      => $action,
                'entity'      => mb_substr($entity, 0, 40),
                'entity_id'   => $entityId === null || $entityId === '' ? null : mb_substr($entityId, 0, 64),
                'summary'     => mb_substr(Sanitizer::text($summary), 0, 255),
                'detail_json' => $detail ? (string)json_encode(self::redact($detail), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'ip_hash'     => PHP_SAPI === 'cli' ? null : ipHash(),
                'created_at'  => Time::nowUtc(),
            ]);
        } catch (Throwable $e) {
            // The change itself already happened; never break the request over the trail.
            Log::exception($e, 'audit ' . $entity);
        }
    }

    /**
     * Changed fields between two rows: ['field' => [old, new]]. Timestamps and the
     * *_by columns are skipped; NULL and '' count as the same; long values are cut.
     * $only limits the comparison to those keys.
     */
    public static function diff(array $old, array $new, ?array $only = null): array
    {
        $out = [];
        foreach ($new as $k => $v) {
            $k = (string)$k;
            if ($only !== null && !in_array($k, $only, true)) continue;
            if (in_array($k, ['updated_at', 'created_at', 'updated_by', 'created_by'], true)) continue;
            $o = $old[$k] ?? null;
            if (self::scalar($o) === self::scalar($v)) continue;
            $out[$k] = [self::short($o), self::short($v)];
        }
        return $out;
    }

    /** Replace secret values anywhere in a detail array. */
    public static function redact(array $detail): array
    {
        $out = [];
        foreach ($detail as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEY, $k)) {
                $out[$k] = is_array($v) && array_is_list($v) ? array_map(fn($x) => $x === null || $x === '' ? '' : '[hidden]', $v) : '[hidden]';
            } elseif (is_array($v)) {
                $out[$k] = self::redact($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** The latest entries, optionally filtered by user_id, entity, entity_id or action. */
    public static function recent(array $filter = [], int $limit = 50): array
    {
        $where = [];
        $args = [];
        foreach (['user_id', 'entity', 'entity_id', 'action'] as $col) {
            if (!array_key_exists($col, $filter) || $filter[$col] === null || $filter[$col] === '') continue;
            if (is_array($filter[$col])) {
                $where[] = $col . ' IN (' . Db::in($filter[$col]) . ')';
                array_push($args, ...array_values($filter[$col]));
            } else {
                $where[] = $col . ' = ?';
                $args[] = $filter[$col];
            }
        }
        $args[] = max(1, min(500, $limit));
        return Db::all(
            'SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN admin_users u ON u.id = a.user_id'
            . ($where ? ' WHERE ' . implode(' AND ', array_map(fn($w) => 'a.' . $w, $where)) : '')
            . ' ORDER BY a.id DESC LIMIT ?',
            $args
        );
    }

    /** The last sign-ins of one user (spec 4.2.14: the last 20 logins per user). */
    public static function logins(int $userId, int $limit = 20): array
    {
        return self::recent(['user_id' => $userId, 'action' => ['login', 'login_fail', 'logout']], $limit);
    }

    private static function scalar(mixed $v): string
    {
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_array($v)) return (string)json_encode($v);
        return (string)$v;
    }

    private static function short(mixed $v): mixed
    {
        if (is_string($v) && mb_strlen($v) > 200) return mb_substr($v, 0, 200) . '...';
        return $v;
    }
}
