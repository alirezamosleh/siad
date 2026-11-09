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

import i18next from 'i18next';

/**
 * Greenlight locales use underscores (fa_IR, ko_KR).
 * i18next.dir() only splits on "-", so i18next.dir("fa_IR") wrongly returns "ltr".
 * Normalize to BCP-47 before asking i18next.
 */
export function normalizeLocale(locale) {
  if (!locale || typeof locale !== 'string') return 'fa-IR';
  return locale.replace(/_/g, '-');
}

export function getDocumentDir(locale) {
  return i18next.dir(normalizeLocale(locale));
}

export function getDocumentLang(locale) {
  return normalizeLocale(locale);
}
