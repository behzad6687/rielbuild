<?php
/** The base schema (Schema::tables()): every table, then every index. Safe to re-run. */
defined('ICM') or exit;

return ['up' => static fn(): array => Schema::install()];
