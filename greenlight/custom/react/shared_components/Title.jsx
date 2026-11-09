import React from 'react';
import PropTypes from 'prop-types';
import { Helmet } from 'react-helmet';

export default function Title({ children: title, lang, dir }) {
  // Prefer props from App (after LocaleHelper fix). Fall back to fa/rtl.
  const htmlLang = lang || 'fa-IR';
  const htmlDir = dir || 'rtl';
  return (
    <Helmet htmlAttributes={{ lang: htmlLang, dir: htmlDir }}>
      <title>{title}</title>
      <meta property="og:title" content={title} />
    </Helmet>
  );
}

Title.propTypes = {
  children: PropTypes.node.isRequired,
  lang: PropTypes.string,
  dir: PropTypes.string,
};

Title.defaultProps = {
  lang: 'fa-IR',
  dir: 'rtl',
};
