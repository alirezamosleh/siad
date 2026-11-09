# frozen_string_literal: true

# Set DefaultRecordingVisibility to Unpublished for all providers,
# and mark existing recordings Unpublished so they stay hidden until
# an admin/user explicitly chooses Public / Protected / Published.
#
# Usage:
#   sudo docker cp custom/set_default_recording_visibility.rb gl-direct-app:/tmp/
#   sudo docker exec -it gl-direct-app bundle exec rails runner /tmp/set_default_recording_visibility.rb

visibility = ENV.fetch('DEFAULT_RECORDING_VISIBILITY', 'Unpublished')
unless Recording::VISIBILITIES.value?(visibility)
  abort("Invalid visibility=#{visibility.inspect}; expected one of #{Recording::VISIBILITIES.values}")
end

setting = Setting.find_by(name: 'DefaultRecordingVisibility')
abort('DefaultRecordingVisibility setting not found') unless setting

updated_settings = 0
SiteSetting.where(setting_id: setting.id).find_each do |ss|
  ss.update!(value: visibility)
  updated_settings += 1
end

# Ensure greenlight provider exists even on fresh DBs
ss = SiteSetting.find_or_initialize_by(setting_id: setting.id, provider: 'greenlight')
unless ss.persisted? && ss.value == visibility
  ss.value = visibility
  ss.save!
  updated_settings += 1 unless ss.previous_changes.empty?
end

updated_recordings = Recording.where.not(visibility: visibility).update_all(visibility: visibility)

puts "OK DefaultRecordingVisibility=#{visibility} site_settings=#{updated_settings} recordings=#{updated_recordings}"
