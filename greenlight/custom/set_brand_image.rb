# Point the `BrandingImage` site setting at the Hoda University logo.
#
# Why: Greenlight seeds this setting with image_path('bbb_logo.png')
# (db/data/20220713143528_populate_site_settings.rb), and the value is used by
# <Logo /> on every page that renders outside the customised navbar: sign-in,
# sign-up, join-room ("ورود به کلاس"), password reset, error pages, e-mail
# templates and the og:image meta tag.
#
# Usage (inside the container):
#   sudo docker cp custom/set_brand_image.rb gl-direct-app:/tmp/set_brand_image.rb
#   sudo docker exec -e BRAND_IMAGE='/hoda-logo.png' -it gl-direct-app \
#     bundle exec rails runner /tmp/set_brand_image.rb
#
# The image file itself is already mounted at /usr/src/app/public/hoda-logo.png
# (docker-compose.yml -> ./custom/assets/logo-hoda.png).
# Idempotent: safe to re-run at any time.

image_path = ENV.fetch('BRAND_IMAGE', '/hoda-logo.png')
setting = Setting.find_by(name: 'BrandingImage')
abort('BrandingImage setting not found') unless setting

site_settings = SiteSetting.where(setting_id: setting.id)
abort('No BrandingImage site settings found') if site_settings.empty?

site_settings.each do |site_setting|
  previous = site_setting.image.attached? ? "uploaded image (##{site_setting.image.id})" : site_setting.value

  # An uploaded image always wins over `value` (SettingGetter#transform_value).
  site_setting.image.purge if site_setting.image.attached?
  site_setting.value = image_path
  site_setting.save!

  puts "OK provider=#{site_setting.provider} #{previous.inspect} -> #{site_setting.reload.value.inspect}"
end

# Make sure the target file exists and is servable.
logo_file = Rails.root.join('public', image_path.delete_prefix('/'))
puts logo_file.exist? ? "OK logo file present: #{logo_file}" : "WARNING: missing #{logo_file}"

puts 'Done. Hard-refresh the browser (Ctrl+Shift+R).'
