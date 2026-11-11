<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Upgrade script for local_morvaridsync.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade local_morvaridsync.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_morvaridsync_upgrade($oldversion) {
    return true;
}
