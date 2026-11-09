import React, { useEffect, useState } from 'react';
import {
  Col, Row,
} from 'react-bootstrap';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useQuery } from 'react-query';
import PropTypes from 'prop-types';
import {
  ArrowLeftIcon,
  Cog8ToothIcon,
  ComputerDesktopIcon,
  VideoCameraIcon,
  ClipboardDocumentCheckIcon,
} from '@heroicons/react/24/outline';
import { toast } from 'react-toastify';
import { useAuth } from '../../contexts/auth/AuthProvider';
import axios from '../../helpers/Axios';
import { localizeDateTimeString } from '../../helpers/DateTimeHelper';
import HomepageFeatureCard from './HomepageFeatureCard';
import useRoomConfigValue from '../../hooks/queries/rooms/useRoomConfigValue';
import useEnv from '../../hooks/queries/env/useEnv';

function HeroPanel({
  rooms, isLoading, language, search, setSearch, totalCount,
}) {
  const { t } = useTranslation();

  return (
    <aside className="hoda-hero-panel">
      <div className="hoda-hero-panel-inner">
        <div className="hoda-panel-top">
          <div className="hoda-panel-dots">
            <span />
            <span />
            <span />
          </div>
          <span className="hoda-panel-badge">{t('homepage.live_classes')}</span>
        </div>
        {(totalCount > 3 || search) && (
          <div className="hoda-panel-search">
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder={t('homepage.search_classes')}
              aria-label={t('homepage.search_classes')}
            />
          </div>
        )}
        <div className="hoda-panel-list">
          {isLoading && <div className="hoda-panel-empty">{t('loading')}</div>}
          {!isLoading && rooms.length === 0 && (
            <div className="hoda-panel-empty">{t('homepage.no_classes')}</div>
          )}
          {!isLoading && rooms.map((room, index) => (
            <Link
              className="hoda-panel-item"
              key={room.friendly_id}
              to={`/rooms/${room.friendly_id}/join`}
              aria-label={`${room.name} - ${room.online ? t('homepage.class_online') : t('homepage.class_offline')}`}
            >
              <div className="hoda-panel-item-main">
                <div className={`hoda-panel-icon ${index % 2 === 0 ? 'amber' : 'violet'}`} aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                    <path d="M4 19h16M8 19V9m4 10V5m4 14v-6" />
                  </svg>
                </div>
                <div>
                  <div className="hoda-panel-title">{room.name}</div>
                  <div className="hoda-panel-sub">
                    {room.last_session
                      ? localizeDateTimeString(room.last_session, language)
                      : t('homepage.class_no_session')}
                  </div>
                </div>
              </div>
              <span className={`hoda-status ${room.online ? 'live' : 'soon'}`}>
                {room.online ? t('homepage.class_online') : t('homepage.class_offline')}
              </span>
            </Link>
          ))}
        </div>
      </div>
    </aside>
  );
}

HeroPanel.propTypes = {
  rooms: PropTypes.arrayOf(PropTypes.shape({
    name: PropTypes.string.isRequired,
    friendly_id: PropTypes.string.isRequired,
    online: PropTypes.bool,
    last_session: PropTypes.string,
  })).isRequired,
  isLoading: PropTypes.bool.isRequired,
  language: PropTypes.string.isRequired,
  search: PropTypes.string.isRequired,
  setSearch: PropTypes.func.isRequired,
  totalCount: PropTypes.number.isRequired,
};

export default function HomePage() {
  const { t } = useTranslation();
  const currentUser = useAuth();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const error = searchParams.get('error');
  const success = searchParams.get('success');
  const { data: recordValue } = useRoomConfigValue('record');
  const { data: env } = useEnv();
  const [classSearch, setClassSearch] = useState('');
  const {
    data: roomsResponse = {}, isLoading: roomsLoading,
  } = useQuery(
    ['publicRooms', classSearch],
    () => axios.get('/public_rooms.json', { params: { search: classSearch } }).then((response) => response.data),
    {
      retry: 1,
      staleTime: Infinity,
      cacheTime: Infinity,
      refetchOnMount: false,
      refetchOnWindowFocus: false,
      refetchOnReconnect: false,
    },
  );
  const rooms = roomsResponse.data || [];
  const totalCount = roomsResponse.meta?.total_count || 0;

  useEffect(
    () => {
      if (!currentUser.stateChanging && currentUser.signed_in && currentUser.permissions.CreateRoom === 'true') {
        navigate('/rooms');
      } else if (!currentUser.stateChanging && currentUser.signed_in && currentUser.permissions.CreateRoom === 'false') {
        navigate('/home');
      }
    },
    [currentUser.signed_in],
  );

  useEffect(() => {
    switch (success) {
      case 'LogoutSuccessful':
        toast.success(t('toast.success.session.signed_out'));
        break;
      default:
    }
    if (success) { setSearchParams(searchParams.delete('success')); }
  }, [success]);

  useEffect(() => {
    switch (error) {
      case 'InviteInvalid':
        toast.error(t('toast.error.users.invalid_invite'));
        break;
      case 'SignupError':
        toast.error(t('toast.error.users.signup_error'));
        break;
      case 'BannedUser':
        toast.error(t('toast.error.users.banned'));
        break;
      default:
    }
    if (error) { setSearchParams(searchParams.delete('error')); }
  }, [error]);

  useEffect(
    () => {
      const inviteToken = searchParams.get('inviteToken');
      if (!env) return;

      if (inviteToken && env?.EXTERNAL_AUTH) {
        const signInForm = document.querySelector('form[action="/auth/openid_connect"]');
        signInForm.submit();
      } else if (inviteToken && !env?.EXTERNAL_AUTH) {
        document.querySelectorAll('.btn').forEach((button) => {
          if (button.textContent === 'Sign Up' || button.textContent === 'ثبت‌نام') {
            button.click();
          }
        });
      }
    },
    [searchParams, env],
  );

  return (
    <>
      <div className="hoda-hero-layout">
        <div className="hoda-hero-copy">
          <div id="homepage-hero">
            <span className="hoda-eyebrow">
              <span className="dot" />
              {t('homepage.eyebrow')}
            </span>
            <h1>
              {t('homepage.welcome_prefix')}
              {' '}
              <span className="accent">{t('homepage.welcome_accent')}</span>
              {' '}
              {t('homepage.welcome_suffix')}
            </h1>
            <p>{t('homepage.bigbluebutton_description')}</p>

            <div className="cta-row">
              <Link to="/signin" className="brand-cta">
                {t('homepage.learn_more')}
                <ArrowLeftIcon className="hi-s" />
              </Link>
              <Link to="/signin" className="brand-cta-ghost">
                {t('homepage.secondary_cta')}
              </Link>
            </div>

            <div className="stats-row">
              <div>
                <div className="stat-val">{t('homepage.stat1_value')}</div>
                <div className="stat-label">{t('homepage.stat1_label')}</div>
              </div>
              <div>
                <div className="stat-val accent">{t('homepage.stat2_value')}</div>
                <div className="stat-label">{t('homepage.stat2_label')}</div>
              </div>
              <div>
                <div className="stat-val">{t('homepage.stat3_value')}</div>
                <div className="stat-label">{t('homepage.stat3_label')}</div>
              </div>
            </div>
          </div>
        </div>
        <HeroPanel
          rooms={rooms}
          isLoading={roomsLoading}
          language={currentUser?.language || 'fa'}
          search={classSearch}
          setSearch={setClassSearch}
          totalCount={totalCount}
        />
      </div>

      <div className="homepage-features-wrap">
        <span className="homepage-features-label">{t('homepage.explore_features')}</span>
        <h3 className="homepage-features-title">{t('homepage.features_heading')}</h3>
        <Row>
          <Col className="mb-3" sm={6} lg={3}>
            <HomepageFeatureCard
              title={t('homepage.meeting_title')}
              description={t('homepage.meeting_description')}
              icon={<ComputerDesktopIcon className="hi-s" />}
            />
          </Col>
          { (recordValue !== 'false') && (
            <Col className="mb-3" sm={6} lg={3}>
              <HomepageFeatureCard
                title={t('homepage.recording_title')}
                description={t('homepage.recording_description')}
                icon={<VideoCameraIcon className="hi-s" />}
              />
            </Col>
          )}
          <Col className="mb-3" sm={6} lg={3}>
            <HomepageFeatureCard
              title={t('homepage.settings_title')}
              description={t('homepage.settings_description')}
              icon={<Cog8ToothIcon className="hi-s" />}
            />
          </Col>
          <Col className="mb-3" sm={6} lg={3}>
            <HomepageFeatureCard
              title={t('homepage.and_more_title')}
              description={t('homepage.and_more_description')}
              icon={<ClipboardDocumentCheckIcon className="hi-s" />}
            />
          </Col>
        </Row>
      </div>
    </>
  );
}
