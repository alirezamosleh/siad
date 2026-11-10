# frozen_string_literal: true

module Api
  module V1
    class RecordingsController < ApiController
      skip_before_action :ensure_authenticated, only: :recording_url

      # Background container (recordings-sync) handles scheduled sync; avoid blocking web requests
      before_action :find_recording, only: %i[update update_visibility recording_url]
      before_action only: %i[destroy] do
        ensure_authorized('ManageRecordings', record_id: params[:id])
      end
      before_action only: %i[update update_visibility] do
        ensure_authorized(%w[ManageRecordings SharedRoom], record_id: params[:id])
      end
      before_action :authorize_recording_url, only: :recording_url

      def index
        sort_config = config_sorting(allowed_columns: %w[name length visibility])
        pagy, recordings = pagy(accessible_recordings.order(sort_config, recorded_at: :desc).search(params[:search]), items: 5)
        render_data data: recordings, meta: pagy_metadata(pagy), status: :ok
      end

      def update
        new_name = recording_params[:name]
        return render_error errors: [Rails.configuration.custom_error_msgs[:missing_params]] if new_name.blank?

        BigBlueButtonApi.new(provider: current_provider).update_recordings record_id: @recording.record_id, meta_hash: { meta_name: new_name }
        @recording.update! name: new_name
        render_data data: @recording, status: :ok
      end

      def destroy
        Recording.destroy_by(record_id: params[:id])
        render_data status: :ok
      end

      def update_visibility
        new_visibility = params[:visibility].to_s
        allowed_visibilities = JSON.parse(RolePermission.joins(:permission)
          .find_by(role_id: current_user.role_id, permission: { name: 'AccessToVisibilities' }).value)
        return render_error status: :forbidden unless allowed_visibilities.include?(new_visibility)

        protected_visibilities = [Recording::VISIBILITIES[:protected], Recording::VISIBILITIES[:public_protected]]
        return render_error status: :forbidden if protected_visibilities.include?(new_visibility) && !@recording.protectable

        # Persist in Greenlight first — JoinIN often accepts publish/meta calls without
        # round-tripping gl-listed, and sync must not wipe the dashboard choice.
        @recording.update!(visibility: new_visibility)

        begin
          BigBlueButtonApi.new(provider: current_provider)
                          .update_recording_visibility(record_id: @recording.record_id, visibility: new_visibility)
        rescue StandardError => e
          Rails.logger.warn(
            "JoinIN update_recording_visibility failed for #{@recording.record_id}: #{e.class}: #{e.message}"
          )
        end

        render_data status: :ok
      end

      def recordings_count
        render_data data: accessible_recordings.count, status: :ok
      end

      def recording_url
        record_format = params[:recording_format]
        urls = if [Recording::VISIBILITIES[:protected], Recording::VISIBILITIES[:public_protected]].include? @recording.visibility
                 recording = BigBlueButtonApi.new(provider: current_provider).get_recording(record_id: @recording.record_id)
                 formats = recording[:playback][:format]
                 formats = [formats] unless formats.is_a? Array
                 if record_format.present?
                   found_format = formats.find { |format| format[:type] == record_format }
                   return render_error status: :not_found unless found_format
                   found_format[:url]
                 else
                   formats.pluck(:url)
                 end
               elsif record_format.present?
                 found_format = @recording.formats.find_by(recording_type: record_format)
                 return render_error status: :not_found unless found_format
                 found_format[:url]
               else
                 @recording.formats.pluck(:url)
               end
        render_data data: urls, status: :ok
      end

      private

      def sync_recordings
        RecordingCatalogSync.new(user: current_user, provider: current_provider).call
      rescue StandardError => e
        Rails.logger.warn("Recordings dashboard sync failed: #{e.class}: #{e.message}")
      end

      def accessible_recordings
        owned_rooms = current_user.rooms.select(:id)
        shared_rooms = current_user.shared_rooms.select(:id)
        Recording.where(room_id: owned_rooms)
                 .or(Recording.where(room_id: shared_rooms))
                 .joins(room: :user)
                 .where(users: { provider: current_provider })
      end

      def recording_params
        params.require(:recording).permit(:name)
      end

      def find_recording
        @recording = Recording.find_by! record_id: params[:id]
      end

      def authorize_recording_url
        return if viewer_access_code_valid_for_recording?

        ensure_authorized(%w[ManageRecordings SharedRoom PublicRecordings], record_id: params[:id])
      end

      def viewer_access_code_valid_for_recording?
        code = params[:access_code].to_s
        return false if code.blank?

        room = @recording.room
        settings = RoomSettingsGetter.new(
          room_id: room.id,
          provider: current_provider,
          current_user:,
          show_codes: true,
          settings: ['glViewerAccessCode']
        ).call

        ActiveSupport::SecurityUtils.secure_compare(settings['glViewerAccessCode'].to_s, code)
      end
    end
  end
end
