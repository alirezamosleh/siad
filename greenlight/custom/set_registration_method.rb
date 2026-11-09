# frozen_string_literal: true

# Disable public registration. Greenlight's "invite" mode hides the sign-up
# action and rejects registrations unless an administrator provides an invite.
#
# Usage:
#   sudo docker cp custom/set_registration_method.rb gl-nginx-app:/tmp/
#   sudo docker exec gl-nginx-app bundle exec rails runner /tmp/set_registration_method.rb

setting = Setting.find_by(name: 'RegistrationMethod')
abort('RegistrationMethod setting not found') unless setting

updated_settings = 0
SiteSetting.where(setting_id: setting.id).find_each do |site_setting|
  next if site_setting.value == 'invite'

  site_setting.update!(value: 'invite')
  updated_settings += 1
end

# Ensure the default Greenlight provider is covered on a fresh database.
site_setting = SiteSetting.find_or_initialize_by(
  setting_id: setting.id,
  provider: 'greenlight'
)
unless site_setting.persisted? && site_setting.value == 'invite'
  site_setting.update!(value: 'invite')
  updated_settings += 1
end

puts "OK RegistrationMethod=invite site_settings_updated=#{updated_settings}"
