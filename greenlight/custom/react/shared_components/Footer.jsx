import React from 'react';
import { useTranslation } from 'react-i18next';
import { Container } from 'react-bootstrap';
import useEnv from '../../hooks/queries/env/useEnv';
import useSiteSetting from '../../hooks/queries/site_settings/useSiteSetting';
import { useAuth } from '../../contexts/auth/AuthProvider';

export default function Footer() {
  const { t } = useTranslation();
  const { data: env } = useEnv();
  const { data: links } = useSiteSetting(['Terms', 'PrivacyPolicy', 'AccessibilityStatement']);
  const currentUser = useAuth();
  const isAdmin = currentUser?.role?.name === 'Administrator' || currentUser?.role?.name === 'SuperAdmin';

  return (
    <footer id="footer" className="footer text-center">
      <Container id="footer-container" className="py-3">
        <span className="brandline">دانشگاه هدی · پرتال تخصصی برگزاری وبینار و کلاس آنلاین</span>
        { isAdmin && (
          <span className="verline ltr text-muted">
            {env?.VERSION_TAG}
            {' '}
            (Custom Hoda Theme Edition)
          </span>
        )}
        { links?.Terms
          && (
            <a className="ps-3" href={links?.Terms} target="_blank" rel="noreferrer">
              { t('admin.site_settings.administration.terms') }
            </a>
          )}
        { links?.PrivacyPolicy
          && (
            <a className="ps-3" href={links?.PrivacyPolicy} target="_blank" rel="noreferrer">
              { t('admin.site_settings.administration.privacy_policy') }
            </a>
          )}
        { links?.AccessibilityStatement
          && (
            <a className="ps-3" href={links?.AccessibilityStatement} target="_blank" rel="noreferrer">
              { t('admin.site_settings.administration.accessibility_statement') }
            </a>
          )}
      </Container>
    </footer>
  );
}
