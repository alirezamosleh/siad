<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Nightly Morvarid sync scheduled task.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_morvaridsync\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled sync task.
 */
class sync_task extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('task_sync', 'local_morvaridsync');
    }

    /**
     * Execute.
     */
    public function execute() {
        $orchestrator = new \local_morvaridsync\sync\orchestrator();
        $log = $orchestrator->run(null);
        mtrace('local_morvaridsync status=' . $log->status .
            ' term=' . $log->termcode .
            ' created=' . $log->createdcount .
            ' updated=' . $log->updatedcount .
            ' enrolled=' . $log->enrolledcount .
            ' errors=' . $log->errorcount);
    }
}
