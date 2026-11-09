// BigBlueButton open source conferencing system - http://www.bigbluebutton.org/.
//
// Copyright (c) 2022 BigBlueButton Inc. and by respective authors (see below).
//
// This program is free software; you can redistribute it and/or modify it under the
// terms of the GNU Lesser General Public License as published by the Free Software
// Foundation; either version 3.0 of the License, or (at your option) any later
// version.
//
// Greenlight is distributed in the hope that it will be useful, but WITHOUT ANY
// WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
// PARTICULAR PURPOSE. See the GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License along
// with Greenlight; if not, see <http://www.gnu.org/licenses/>.

import React from 'react';
import Image from 'react-bootstrap/Image';
import PropTypes from 'prop-types';
import { useNavigate } from 'react-router-dom';
import useSiteSetting from '../../hooks/queries/site_settings/useSiteSetting';

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

  // Logo can be small or regular size
  const sizeClass = size === 'small'
    ? 'small-logo cursor-pointer'
    : 'logo cursor-pointer position-absolute bottom-0 mx-auto start-0 end-0 text-center';
  // Small Logo is used in Header only and does not require a wrapper
  const sizeWrapperClass = !size ? 'logo-wrapper position-relative d-block mx-auto' : undefined;

  if (isLoading) return <div className={sizeWrapperClass} />;

  return (
    <div className={sizeWrapperClass}>
      <Image
        src={resolveBrandingImage(brandingImage)}
        className={sizeClass}
        alt="CompanyLogo"
        onClick={() => { navigate('/'); }}
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
