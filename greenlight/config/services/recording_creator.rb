# BigBlueButton open source conferencing system - http://www.bigbluebutton.org/.
#
# Copyright (c) 2022 BigBlueButton Inc. and by respective authors (see below).
#
# This program is free software; you can redistribute it and/or modify it under the
# terms of the GNU Lesser General Public License as published by the Free Software
# Foundation; either version 3.0 of the License, or (at your option) any later
# version.
#
# Greenlight is distributed in the hope that it will be useful, but WITHOUT ANY
# WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
# PARTICULAR PURPOSE. See the GNU Lesser General Public License for more details.
#
# You should have received a copy of the GNU Lesser General Public License along
# with Greenlight; if not, see <http://www.gnu.org/licenses/>.

# frozen_string_literal: true

# JoinIN-aware RecordingCreator.
#
# Stock Greenlight recomputes visibility from BBB metadata (published / protected /
# meta_gl-listed) on every sync. JoinIN does not reliably persist Greenlight's
# meta_gl-listed (or protect) flags, so Public / Protected / Unpublished choices
# made in the dashboard were overwritten back to Published on the next sync.
#
# For existing rows, Greenlight remains the source of truth for visibility.
# JoinIN metadata is only used when creating a recording for the first time.
class RecordingCreator
  def initialize(recording:, first_creation: false)
    @recording = recording
    @first_creation = first_creation
  end

  def call
    meeting_id = @recording[:metadata][:meetingId] || @recording[:meetingID]
    room = Room.find_by(meeting_id:)
    raise ActiveRecord::RecordNotFound if room.nil?

    @provider = room.user.provider

    new_recording = Recording.find_or_initialize_by(record_id: @recording[:recordID])
    was_new = new_recording.new_record?

    # New recordings always start Unpublished (or whatever DefaultRecordingVisibility says).
    # JoinIN usually reports published=true, which must not auto-expose sessions.
    visibility = if was_new
                   default_recording_visibility
                 else
                   # Preserve dashboard-chosen visibility; JoinIN metadata is incomplete.
                   new_recording.visibility
                 end

    length = recording_length
    new_name = @recording[:metadata][:name] || @recording[:name]

    new_recording.room_id = room.id
    new_recording.name = new_name
    new_recording.visibility = visibility
    new_recording.participants = @recording[:participants]
    new_recording.length = length
    new_recording.protectable = @recording[:protected].present? || new_recording.protectable
    new_recording.recorded_at = parse_time(@recording[:startTime]) || Time.current
    new_recording.save!

    create_formats(new_recording:)

    return unless was_new || @first_creation

    begin
      BigBlueButtonApi.new(provider: @provider).update_recording_visibility(
        record_id: new_recording.record_id,
        visibility:
      )
    rescue StandardError => e
      Rails.logger.warn(
        "JoinIN update_recording_visibility failed for #{new_recording.record_id}: #{e.class}: #{e.message}"
      )
    end
  end

  private

  def default_recording_visibility
    SettingGetter.new(setting_name: 'DefaultRecordingVisibility', provider: @provider).call ||
      Recording::VISIBILITIES[:unpublished]
  end

  def recording_visibility
    list = @recording[:metadata][:'gl-listed'].to_s == 'true'
    protect = @recording[:protected].to_s == 'true'
    publish = @recording[:published].to_s == 'true'

    visibility = visibility_for(publish:, protect:, list:)

    return visibility unless visibility.nil?

    Recording::VISIBILITIES[:unpublished]
  end

  def recording_length
    length = 0
    video_formats = %w[presentation video]
    formats = @recording.dig(:playback, :format)
    return length if formats.blank?

    if formats.is_a?(Array)
      formats.each do |f|
        length = f[:length] if video_formats.include?(f[:type])
      end
    else
      length = formats[:length] || 0
    end
    length
  end

  # [FIX - DATA INTEGRITY]:
  # بررسی وجود URL در فرمت‌های ضبط جهت جلوگیری از ثبت رکوردهای فرمت بدون لینک (مثلاً ضبط‌هایی که پردازش آن‌ها هنوز تکمیل نشده است).
  def create_formats(new_recording:)
    formats = @recording.dig(:playback, :format)
    return if formats.blank?

    if formats.is_a?(Array)
      formats.each do |format|
        next if format.blank? || format[:url].blank?
        Format.find_or_create_by(recording_id: new_recording.id, recording_type: format[:type]).update(url: format[:url])
      end
    else
      return if formats[:url].blank?
      Format.find_or_create_by(recording_id: new_recording.id,
                               recording_type: formats[:type]).update(url: formats[:url])
    end
  end

  # [FIX - JOININ TIMESTAMP COMPATIBILITY]:
  # پشتیبانی از تایم‌استمپ‌های میلی‌ثانیه‌ای سرورهای BBB/JoinIN (تبدیل مقادیر بزرگتر از 10^10 به ثانیه) جهت جلوگیری از تاریخ‌های نامعتبر سال‌های دور.
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

  def visibility_for(publish:, protect:, list:)
    params_for = {
      { publish: false, protect: false, list: false } => Recording::VISIBILITIES[:unpublished],
      { publish: true, protect: false, list: false } => Recording::VISIBILITIES[:published],
      { publish: true, protect: false, list: true } => Recording::VISIBILITIES[:public],
      { publish: true, protect: true, list: false } => Recording::VISIBILITIES[:protected],
      { publish: true, protect: true, list: true } => Recording::VISIBILITIES[:public_protected]
    }

    params_for[{ publish:, protect:, list: }]
  end
end
