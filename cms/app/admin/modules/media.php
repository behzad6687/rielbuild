<?php
/**
 * Media library (spec 4.2.13). Owner: W7.
 *   media               the grid with filters (real photo, AI, no alt text, unused, PDFs)
 *   media/edit          alt, caption, credit, flags, focal point, where it is used
 *   media/delete        refused while the item is in use
 *   api/media/upload    one file per request (spec 2.5), JSON
 *   api/media/list      the picker (image fields, the editor's Image button), JSON
 *   api/media/item      one item, JSON
 *   api/media/update    alt, caption or focal point from an image field, JSON
 */
defined('ICM') or exit;

$load = static function (int $id): array {
    $m = $id > 0 ? Db::one('SELECT * FROM media WHERE id = ?', [$id]) : null;
    if (!$m) admin_redirect('media', 'That item no longer exists.', 'bad');
    return $m;
};

return [
    'nav'    => ['group' => 'Content', 'label' => 'Media', 'path' => 'media', 'perm' => 'media.edit', 'sort' => 90],
    'routes' => [
        'media' => ['methods' => ['GET'], 'handler' => function (array $req) {
            $f = [
                'q'      => Sanitizer::text($req['query']['q'] ?? '', false, 80),
                'filter' => (string)($req['query']['filter'] ?? ''),
                'kind'   => (string)($req['query']['kind'] ?? ''),
                'page'   => (int)($req['query']['page'] ?? 1),
            ];
            if (!in_array($f['filter'], ['', 'real', 'ai', 'noalt', 'unused'], true)) $f['filter'] = '';
            admin_render('media/list', ['title' => 'Media', 'f' => $f, 'pg' => Media::listPage($f), 'used' => Media::usedIds(), 'limit' => Media::uploadLimitMb()]);
        }],

        'media/edit' => ['methods' => ['GET', 'POST'], 'handler' => function (array $req) use ($load) {
            $m = $load((int)($req['query']['id'] ?? $req['post']['id'] ?? 0));
            $errors = [];
            if ($req['method'] === 'POST') {
                if ($stale = admin_stale((string)$m['updated_at'], (string)($req['post']['updated_at'] ?? ''))) {
                    $errors['_form'] = $stale;
                } else {
                    try {
                        Media::update((int)$m['id'], [
                            'alt'             => $req['post']['alt'] ?? '',
                            'caption'         => $req['post']['caption'] ?? '',
                            'credit'          => $req['post']['credit'] ?? '',
                            'is_real_photo'   => $req['post']['is_real_photo'] ?? '0',
                            'is_ai_generated' => $req['post']['is_ai_generated'] ?? '0',
                            'focal_x'         => $req['post']['focal_x'] ?? $m['focal_x'],
                            'focal_y'         => $req['post']['focal_y'] ?? $m['focal_y'],
                        ], Auth::id());
                        admin_redirect('media/edit?id=' . (int)$m['id'], 'Saved.');
                    } catch (InvalidArgumentException $e) {
                        $errors['_form'] = $e->getMessage();
                    }
                }
                http_response_code(422);
                $m = array_replace($m, array_intersect_key($req['post'], array_flip(['alt', 'caption', 'credit'])));
            }
            admin_render('media/edit', ['title' => 'Media item', 'm' => $m, 'errors' => $errors, 'uses' => Media::usage((int)$m['id'])]);
        }],

        'media/delete' => ['methods' => ['POST'], 'handler' => function (array $req) use ($load) {
            $m = $load((int)($req['post']['id'] ?? 0));
            $uses = Media::delete((int)$m['id'], Auth::id());
            if ($uses) {
                $where = implode(', ', array_map(fn($u) => $u['label'] . ' "' . $u['title'] . '"', array_slice($uses, 0, 3)));
                admin_redirect('media/edit?id=' . (int)$m['id'], 'This item is in use (' . $where . (count($uses) > 3 ? ' and more' : '') . '), so it was not deleted.', 'bad');
            }
            admin_redirect('media', 'Deleted.');
        }],

        'api/media/upload' => ['methods' => ['POST'], 'handler' => function (array $req) {
            $file = $req['files']['file'] ?? null;
            if (!is_array($file) || is_array($file['name'] ?? null)) admin_json(['ok' => false, 'error' => 'Send one file per request.'], 422);
            $name = strtolower((string)($file['name'] ?? ''));
            try {
                if (str_ends_with($name, '.pdf')) {
                    $m = Media::uploadPdf($file, ['title' => $req['post']['title'] ?? null], Auth::id());
                } elseif (str_ends_with($name, '.mp4') || str_ends_with($name, '.m4v')) {
                    // round 2 (WP-A): the "video" kind, MP4 only, stored as uploaded (Pathways > Certificate video picks one)
                    $m = Media::uploadVideo($file, ['title' => $req['post']['title'] ?? null, 'alt' => $req['post']['alt'] ?? null], Auth::id());
                } else {
                    $m = Media::uploadImage($file, [
                        'alt'             => $req['post']['alt'] ?? null,
                        'is_real_photo'   => $req['post']['is_real_photo'] ?? null,
                        'is_ai_generated' => $req['post']['is_ai_generated'] ?? null,
                    ], Auth::id());
                }
            } catch (InvalidArgumentException $e) {
                admin_json(['ok' => false, 'error' => $e->getMessage()], 422);
            } catch (Throwable $e) {
                Log::exception($e, 'media upload');
                admin_json(['ok' => false, 'error' => 'The upload failed on the server. Please try again.'], 500);
            }
            admin_json(['ok' => true, 'media' => Media::toJson($m)]);
        }],

        'api/media/list' => ['methods' => ['GET'], 'handler' => function (array $req) {
            $pg = Media::listPage([
                'q'      => Sanitizer::text($req['query']['q'] ?? '', false, 80),
                'kind'   => in_array($req['query']['kind'] ?? 'image', ['image', 'pdf'], true) ? $req['query']['kind'] ?? 'image' : 'image',
                'filter' => '',
                'page'   => (int)($req['query']['page'] ?? 1),
            ]);
            admin_json(['ok' => true, 'items' => array_map([Media::class, 'toJson'], $pg['rows']), 'page' => $pg['page'], 'pages' => $pg['pages'], 'total' => $pg['total']]);
        }],

        'api/media/item' => ['methods' => ['GET'], 'handler' => function (array $req) {
            $m = Db::one('SELECT * FROM media WHERE id = ?', [(int)($req['query']['id'] ?? 0)]);
            if (!$m) admin_json(['ok' => false, 'error' => 'Not found'], 404);
            admin_json(['ok' => true, 'media' => Media::toJson($m)]);
        }],

        'api/media/update' => ['methods' => ['POST'], 'handler' => function (array $req) {
            $in = $req['json'] ?? $req['post'];
            $id = (int)($in['id'] ?? 0);
            $fields = array_intersect_key((array)$in, array_flip(['alt', 'caption', 'credit', 'focal_x', 'focal_y']));
            try {
                $m = Media::update($id, $fields, Auth::id());
            } catch (InvalidArgumentException $e) {
                admin_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            admin_json(['ok' => true, 'media' => Media::toJson($m)]);
        }],
    ],
];
