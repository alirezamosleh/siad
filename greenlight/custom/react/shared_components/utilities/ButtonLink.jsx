import { Link } from 'react-router-dom';
import Button from 'react-bootstrap/Button';
import React from 'react';
import PropTypes from 'prop-types';

export default function ButtonLink(props) {
  const {
    to, className, variant, children,
  } = props;

  return (
    <Button
      as={Link}
      to={to}
      className={className}
      variant={variant}
    >
      {children}
    </Button>
  );
}

ButtonLink.defaultProps = {
  className: '',
  variant: 'primary',
};

ButtonLink.propTypes = {
  to: PropTypes.string.isRequired,
  className: PropTypes.string,
  variant: PropTypes.string,
  children: PropTypes.node.isRequired,
};
