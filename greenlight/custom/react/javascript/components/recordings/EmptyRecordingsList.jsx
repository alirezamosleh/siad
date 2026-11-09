import React from 'react';
import { Card } from 'react-bootstrap';
import { useTranslation } from 'react-i18next';
import { VideoCameraIcon } from '@heroicons/react/24/outline';

export default function EmptyRecordingsList() {
  const { t } = useTranslation();

  return (
    <div id="recordings-list-empty" className="hoda-recordings-empty">
      <Card className="hoda-recordings-empty-card border-0">
        <Card.Body>
          <div className="hoda-recordings-empty-icon" aria-hidden="true">
            <VideoCameraIcon />
          </div>
          <Card.Title as="h3">{t('recording.recordings_list_empty')}</Card.Title>
          <Card.Text>{t('recording.recordings_list_empty_description')}</Card.Text>
          <p className="hoda-recordings-sync-hint mb-0">{t('recording.sync_hint')}</p>
        </Card.Body>
      </Card>
    </div>
  );
}
