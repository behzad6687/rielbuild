<?php
/** Scheduled blog posts whose time has come go live. */
defined('ICM') or exit;
return static fn(array $ctx): string => Content::publishDue() . ' published';
