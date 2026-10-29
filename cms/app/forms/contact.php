<?php
/**
 * The estimate request form (every "Book a free estimate" form on the site posts here: /forms/contact/).
 * Stored in Admin > Inbox, emailed to the recipients in Settings > Form recipients.
 */
defined('ICM') or exit;

return [
    'label'   => 'Estimate request',
    'button'  => 'Book my free estimate',
    'success' => static fn(array $v): string => site('form.thanks_text', 'A project lead will call you within one business day to book your visit.'),
    'rate'    => ['per_ip' => [6, 600], 'per_email' => [4, 3600]],
    'min_age' => 2,
    'store'   => [
        'name'    => 'name',
        'email'   => 'email',
        'phone'   => 'phone',
        'subject' => static function (array $v): string {
            foreach (Content::services() as $s) if ($s['slug'] === ($v['service'] ?? '')) return 'Estimate: ' . $s['name'];
            return ($v['service'] ?? '') === 'other' ? 'Estimate: something else' : 'Estimate request';
        },
    ],
    'fields'  => [
        ['key' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true, 'max' => 80],
        ['key' => 'phone', 'type' => 'tel', 'label' => 'Phone', 'required' => true, 'max' => 40],
        ['key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true, 'max' => 120, 'dns' => false],
        ['key' => 'service', 'type' => 'select', 'label' => 'Project', 'required' => true, 'prefill' => 'service',
         'options' => static function (): array {
             $o = [];
             foreach (Content::services() as $s) $o[$s['slug']] = $s['name'];
             return $o + ['other' => 'Something else'];
         }],
        ['key' => 'message', 'type' => 'textarea', 'label' => 'About the project', 'max' => 2000],
    ],
];
