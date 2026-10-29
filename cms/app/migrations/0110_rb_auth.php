<?php
/** Sign-in hardening: session epochs (sign out everywhere), remember-token rotation grace, 2FA recovery codes. */
defined('ICM') or exit;

return ['up' => static function (): void {
    Schema::addColumn('admin_users', 'session_epoch int NOT NULL DEFAULT 0');
    Schema::addColumn('admin_remember_tokens', 'prev_validator_hash char(64) NULL');
    Schema::addColumn('admin_remember_tokens', 'rotated_at dt NULL');
    Schema::createTable('admin_recovery_codes', [
        'cols'   => ['id pk', 'user_id uint NOT NULL', 'code_hash char(64) NOT NULL', 'used_at dt NULL', 'created_at dt NOT NULL'],
        'fk'     => [['user_id', 'admin_users', 'id', 'CASCADE']],
        'index'  => ['idx_recovery_user' => ['user_id']],
    ]);
}];
