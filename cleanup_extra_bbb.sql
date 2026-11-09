DELETE FROM mdl_bigbluebuttonbn_recordings 
WHERE status = 0 
  AND timecreated < UNIX_TIMESTAMP(NOW() - INTERVAL 30 DAY);
DELETE FROM mdl_bigbluebuttonbn_recordings
WHERE id NOT IN (
    SELECT min_id FROM (
        SELECT MIN(id) as min_id
        FROM mdl_bigbluebuttonbn_recordings
        GROUP BY recordingid
    ) AS keep_records
)
AND recordingid IN (
    SELECT recordingid FROM (
        SELECT recordingid
        FROM mdl_bigbluebuttonbn_recordings
        GROUP BY recordingid
        HAVING COUNT(*) > 1
    ) AS dupes
);

