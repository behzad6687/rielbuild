<?php
/**
 * Admin > Site > Access control (v3, 2026-10-06; master only). The master decides which admin sections each
 * other role may use: owner (the main admin), manager, coach and editor. A role can only lose sections its
 * permissions give it; the master always sees everything and is invisible to the others (Users).
 * Stored in the setting access.hidden as {role: [permission, ...]} (Auth::hiddenFor). The router checks CSRF.
 *   access        GET   the grid
 *   access/save   POST  show[role][permission] checkboxes
 */
defined('ICM') or exit;

/** Every permission with a readable name: the admin menu's own label where one exists. */
$labels = static function (): array {
    $out = [];
    foreach ((array)($GLOBALS['ICM_ADMIN']['nav'] ?? []) as $n) {
        $p = (string)($n['perm'] ?? '');
        if ($p !== '' && isset(Auth::MATRIX[$p])) $out[$p][] = (string)$n['label'];
    }
    $named = [];
    foreach (Auth::MATRIX as $perm => $_) {
        if ($perm === 'access.manage') continue;
        $named[$perm] = isset($out[$perm]) ? implode(', ', array_unique($out[$perm])) : ucfirst(str_replace(['.', '_'], [': ', ' '], $perm));
    }
    return $named;
};

return [
    'nav' => ['group' => 'Site', 'label' => 'Access control', 'path' => 'access', 'perm' => 'access.manage', 'sort' => 5],
    'routes' => [
        'access' => ['perm' => 'access.manage', 'methods' => ['GET'], 'handler' => static function (array $req) use ($labels): never {
            $roles = array_diff_key(Auth::ROLES, [Auth::MASTER => 1]);
            $hidden = [];
            foreach ($roles as $r => $_) $hidden[$r] = Auth::hiddenFor($r);
            admin_render('access/index', ['title' => 'Access control', 'perms' => $labels(), 'roles' => $roles, 'hidden' => $hidden]);
        }],

        'access/save' => ['perm' => 'access.manage', 'methods' => ['POST'], 'handler' => static function (array $req) use ($labels): never {
            $show = is_array($req['post']['show'] ?? null) ? $req['post']['show'] : [];
            $map = [];
            foreach (array_diff_key(Auth::ROLES, [Auth::MASTER => 1]) as $role => $_) {
                foreach (array_keys($labels()) as $perm) {
                    if (!in_array($role, Auth::MATRIX[$perm], true)) continue;      // the role never had it
                    if (empty($show[$role][$perm])) $map[$role][] = $perm;          // unticked: hidden
                }
            }
            $before = (string)site('access.hidden', '');
            Db::setSetting('access.hidden', $map ? (string)json_encode($map) : '', AdminKit::uid($req));
            Db::flushSettings();
            admin_audit('update', 'settings', 'access.hidden', 'Access control saved', ['before' => $before, 'after' => $map]);
            admin_redirect('access', 'Access saved. The changes apply on each person\'s next page.');
        }],
    ],
];
