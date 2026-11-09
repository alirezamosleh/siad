# frozen_string_literal: true

# JoinIN does not reliably deliver Greenlight's recording_ready / meeting_ended callbacks.
# Reconcile last_session + recordings from JoinIN whenever rooms/recordings are listed.
class RecordingCatalogSync
  def initialize(user:, provider:, recordings: true)
    @user = user
    @provider = provider
    @sync_recordings = recordings
  end

  def call
    accessible_rooms.find_each do |room|
      sync(room)
    end
  end

  def sync(room)
    sync_room(room)
  rescue StandardError => e
    Rails.logger.warn("Recording sync failed for room #{room.id}: #{e.class}: #{e.message}")
  end

  private

  def accessible_rooms
    owned_rooms = @user.rooms.select(:id)
    shared_rooms = @user.shared_rooms.select(:id)

    Room.with_provider(@provider)
        .where(id: owned_rooms)
        .or(Room.with_provider(@provider).where(id: shared_rooms))
  end

  def sync_room(room)
    api = BigBlueButtonApi.new(provider: @provider)

    sync_last_session(room, api.get_sessions(meeting_id: room.meeting_id))

    return unless @sync_recordings

    response = api.get_recordings(meeting_ids: room.meeting_id)
    recordings = response[:recordings] || response['recordings'] || []
    recordings = [recordings] unless recordings.is_a?(Array)

    recordings.each do |recording|
      next if recording.blank? || (recording.respond_to?(:empty?) && recording.empty?)

      payload = deep_indifferent(recording)
      RecordingCreator.new(recording: payload).call
    rescue ActiveRecord::RecordInvalid, ActiveRecord::RecordNotFound, KeyError, TypeError, NoMethodError => e
      Rails.logger.warn("Invalid recording payload for room #{room.id}: #{e.message}")
    end

    latest_recorded_at = room.recordings.maximum(:recorded_at)
    return unless latest_recorded_at.present? && (room.last_session.blank? || latest_recorded_at > room.last_session)

    room.update_columns(last_session: latest_recorded_at, online: false)
  end

  def sync_last_session(room, sessions)
    sessions = Array(sessions)
    latest_session = sessions.filter_map { |session| extract_session_time(session) }.max
    return unless latest_session.present? && (room.last_session.blank? || latest_session > room.last_session)

    room.update_columns(last_session: latest_session, online: false)
  end

  def extract_session_time(session)
    session = deep_indifferent(session)
    value = session['started_at'] || session['startTime'] || session['startedAt'] ||
            session['start_time'] || session['created_at'] || session['createTime']
    parse_time(value)
  end

  def parse_time(value)
    return nil if value.blank?

    if value.to_s.match?(/\A\d+\z/)
      n = value.to_i
      return Time.zone.at(n > 10_000_000_000 ? n / 1000.0 : n)
    end

    Time.zone.parse(value.to_s)
  rescue ArgumentError, TypeError
    nil
  end

  def deep_indifferent(obj)
    case obj
    when ActiveSupport::HashWithIndifferentAccess
      obj
    when Hash
      ActiveSupport::HashWithIndifferentAccess.new(obj.transform_values { |v| deep_indifferent(v) })
    when Array
      obj.map { |v| deep_indifferent(v) }
    else
      obj
    end
  end
end
