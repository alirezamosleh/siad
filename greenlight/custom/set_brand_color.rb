color = ENV.fetch('BRAND_PRIMARY', '#a628aa')
setting = Setting.find_by(name: 'PrimaryColor')
abort('PrimaryColor setting not found') unless setting
ss = SiteSetting.find_or_initialize_by(setting_id: setting.id, provider: 'greenlight')
ss.value = color
ss.save!
puts "OK PrimaryColor=#{ss.value}"
