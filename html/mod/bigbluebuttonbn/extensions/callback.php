<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Callback URL for the local_bbb_lap plugin.
 *
 * @package    local_bbb_lad
 * @copyright  2026, think modular
 * @author     think modular (stefan.weber@think-modular.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

require_once('../../config.php');

global $DB;

$instanceid = required_param('instanceid', PARAM_INT);
$secret = required_param('secret', PARAM_ALPHANUMEXT);
$record = $DB->get_record('bbbext_lad', ['bigbluebuttonbnid' => $instanceid]);

// Verify secret.
if (!$record || $record->secret !== $secret) {
    http_response_code(403);
    die('Unauthorized');
}

// Read raw POST body.
$raw = file_get_contents('php://input');

// Decode JSON payload.
$data = json_decode($raw, true);

// Optional: handle invalid JSON.
if (json_last_error() !== JSON_ERROR_NONE) {
    $data = 'Invalid JSON';
} else {
    // Stringify for storage.
    $data = json_encode($data);
}

// Log data.
$record = new stdClass();
$record->timestamp = time();
$record->data = $data;
$record->bigbluebuttonbnid = $instanceid;
$DB->insert_record('local_bbb_lad', $record);

echo "OK";
