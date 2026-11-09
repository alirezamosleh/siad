import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import useRecordings from '../../hooks/queries/recordings/useRecordings';
import RecordingsList from './RecordingsList';

export default function UserRecordings() {
  const { t } = useTranslation();
  const [page, setPage] = useState();
  const [searchInput, setSearchInput] = useState();
  const { isLoading, data: recordings } = useRecordings(searchInput, page);
  const count = recordings?.meta?.count ?? recordings?.data?.length;

  return (
    <div id="user-recordings" className="hoda-recordings-panel">
      <div className="hoda-recordings-head">
        <p className="hoda-recordings-sub mb-0">{t('recording.sync_hint')}</p>
        {typeof count === 'number' && !isLoading && (
          <span className="hoda-recordings-count">
            {count}
            {' '}
            {t('recording.recording')}
          </span>
        )}
      </div>
      <RecordingsList
        recordings={recordings}
        isLoading={isLoading}
        setPage={setPage}
        setSearchInput={setSearchInput}
        searchInput={searchInput}
        numPlaceholders={5}
      />
    </div>
  );
}
