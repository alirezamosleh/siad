<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Scheduled tasks for local_morvaridsync.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_morvaridsync\\task\\sync_task',
        'blocking' => 0,
        'minute' => '15',
        'hour' => '2',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];
