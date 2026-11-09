#!/bin/bash

echo "=== Cleanup started at $(date) ==="

sudo docker exec -i moodle_mariadb mariadb -h mariadb -u alirezabb -pSEDO3482389D4 siad --skip-ssl < /home/elearn/siad/cleanup_extra_bbb.sql

if [ $? -eq 0 ]; then
    echo "Cleanup completed successfully at $(date)"
else
    echo "Cleanup failed at $(date)"
fi

echo ""

