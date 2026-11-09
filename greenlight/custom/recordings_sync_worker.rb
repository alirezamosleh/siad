# frozen_string_literal: true

# Background reconciler for JoinIN (callbacks for recording_ready / meeting_ended are unreliable).
# Container: gl-direct-recordings-sync
#
# ENV:
#   RECORDINGS_SYNC_INTERVAL      seconds between cycles (default 120)
#   RECORDINGS_FULL_SYNC_EVERY    run full getRecordings every N cycles (default 5)
#                                 other cycles only refresh last_session via getSessions

Rails.logger = ActiveSupport::Logger.new($stdout)
Rails.logger.level = Logger::INFO
Rails.application.eager_load!

interval = ENV.fetch('RECORDINGS_SYNC_INTERVAL', '120').to_i
interval = 60 if interval < 30
full_every = ENV.fetch('RECORDINGS_FULL_SYNC_EVERY', '5').to_i
full_every = 1 if full_every < 1

Rails.logger.info("[recordings-sync] started interval=#{interval}s full_every=#{full_every}")

cycle = 0
loop do
  cycle += 1
  full = (cycle % full_every).zero?
  started = Process.clock_gettime(Process::CLOCK_MONOTONIC)
  rooms_synced = 0
  errors = 0

  Room.includes(:user).find_each do |room|
    user = room.user
    if user.nil?
      Rails.logger.warn("[recordings-sync] skip room=#{room.id} (no user)")
      next
    end

    begin
      RecordingCatalogSync.new(
        user: user,
        provider: user.provider,
        recordings: full
      ).sync(room)
      rooms_synced += 1
    rescue StandardError => e
      errors += 1
      Rails.logger.warn("[recordings-sync] room=#{room.id} #{e.class}: #{e.message}")
    end
  end

  elapsed = (Process.clock_gettime(Process::CLOCK_MONOTONIC) - started).round(1)
  Rails.logger.info(
    "[recordings-sync] cycle=#{cycle} mode=#{full ? 'full' : 'sessions'} " \
    "rooms=#{rooms_synced} errors=#{errors} elapsed=#{elapsed}s"
  )

  sleep interval
rescue StandardError => e
  Rails.logger.error("[recordings-sync] loop crash #{e.class}: #{e.message}")
  sleep interval
end
