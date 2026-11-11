<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Mapping store between Morvarid and Moodle.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_morvaridsync\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * Persistent map helper.
 */
class mapper {

    public const TYPE_TERM = 'term';
    public const TYPE_FACULTY = 'faculty';
    public const TYPE_COURSE = 'course';
    public const TYPE_USER_STUDENT = 'user_student';
    public const TYPE_USER_TEACHER = 'user_teacher';

    /**
     * Get Moodle ID for external entity.
     *
     * @param string $type
     * @param string $externalid
     * @return int|null
     */
    public static function get_moodle_id(string $type, string $externalid): ?int {
        global $DB;
        $externalid = (string)$externalid;
        $id = $DB->get_field('local_morvaridsync_map', 'moodleid', [
            'entitytype' => $type,
            'externalid' => $externalid,
        ]);
        return $id !== false ? (int)$id : null;
    }

    /**
     * Upsert mapping.
     *
     * @param string $type
     * @param string $externalid
     * @param int $moodleid
     * @param string|null $termcode
     * @param array|null $payload
     */
    public static function set(string $type, string $externalid, int $moodleid, ?string $termcode = null, ?array $payload = null): void {
        global $DB;

        $externalid = (string)$externalid;
        $now = time();
        $existing = $DB->get_record('local_morvaridsync_map', [
            'entitytype' => $type,
            'externalid' => $externalid,
        ]);

        if ($existing) {
            $existing->moodleid = $moodleid;
            $existing->termcode = $termcode;
            $existing->payload = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $existing->payload;
            $existing->timemodified = $now;
            $DB->update_record('local_morvaridsync_map', $existing);
            return;
        }

        $record = (object)[
            'entitytype' => $type,
            'externalid' => $externalid,
            'moodleid' => $moodleid,
            'termcode' => $termcode,
            'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('local_morvaridsync_map', $record);
    }
}
