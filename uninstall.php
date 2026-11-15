<?php
/**
 * JMO deliberately preserves its settings and all media on uninstall.
 *
 * @package Jocky\MediaOffloader
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Queue tables, settings, local media, and remote objects are preserved on uninstall.
