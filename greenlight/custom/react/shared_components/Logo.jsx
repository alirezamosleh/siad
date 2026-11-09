import React from 'react';
import Image from 'react-bootstrap/Image';
import PropTypes from 'prop-types';
import { useNavigate } from 'react-router-dom';
import useSiteSetting from '../../hooks/queries/site_settings/useSiteSetting';
import { useAuth } from '../../contexts/auth/AuthProvider';

// Hoda University: never show the upstream BigBlueButton logo.
// The `BrandingImage` site setting still points at /assets/bbb_logo-<digest>.png
// on a fresh install (see db/data/20220713143528_populate_site_settings.rb), and
// it is returned to Header / SignIn / Signup / RoomJoin / error pages alike.
// Run custom/set_brand_image.rb to fix it in the database; this fallback keeps
// the UI branded even before (or after a reset of) that setting.
const HODA_LOGO = '/hoda-logo.png';
const STOCK_BBB_LOGO = 'bbb_logo';

const resolveBrandingImage = (src) => {
  if (!src || src.includes(STOCK_BBB_LOGO)) return HODA_LOGO;

  return src;
};

export default function Logo({ size }) {
  const { isLoading, data: brandingImage } = useSiteSetting('BrandingImage');
  const navigate = useNavigate();
  const currentUser = useAuth();

  const sizeClass = size === 'small'
    ? 'small-logo'
    : 'logo cursor-pointer position-absolute bottom-0 mx-auto start-0 end-0 text-center';
  const sizeWrapperClass = !size ? 'logo-wrapper position-relative d-block mx-auto' : undefined;

  if (isLoading) return <div className={sizeWrapperClass} />;

  const goHome = () => {
    // Header uses Navbar.Brand Link — small logo must NOT steal clicks
    if (size === 'small') return;
    if (currentUser?.signed_in && currentUser?.permissions?.CreateRoom === 'true') {
      navigate('/rooms');
    } else if (currentUser?.signed_in) {
      navigate('/home');
    } else {
      navigate('/');
    }
  };

  return (
    <div className={sizeWrapperClass}>
      <Image
        src={resolveBrandingImage(brandingImage)}
        className={sizeClass}
        alt="CompanyLogo"
        onClick={size === 'small' ? undefined : goHome}
      />
    </div>
  );
}

Logo.propTypes = {
  size: PropTypes.string,
};

Logo.defaultProps = {
  size: '',
};
