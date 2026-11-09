# frozen_string_literal: true

# SPA catch-all: always serve the HTML shell, even if Accept is application/json.
class ComponentsController < ApplicationController
  def index
    return render_public_rooms if request.path == '/api/v1/public_rooms.json'

    render template: 'components/index', formats: [:html], content_type: 'text/html'
  end

  private

  def render_public_rooms
    rooms = Room.includes(:user).with_provider(current_provider)
    search = params[:search].to_s.strip
    rooms = rooms.where('rooms.name ILIKE ?', "%#{Room.sanitize_sql_like(search)}%") if search.present?
    total_count = rooms.count
    rooms = rooms.order(online: :desc)
                 .order(Arel.sql('last_session DESC NULLS LAST'))
                 .order(created_at: :desc)

    render json: {
      data: rooms.map do |room|
        {
          name: room.name,
          friendly_id: room.friendly_id,
          online: room.online,
          participants: room.participants || 0,
          last_session: room.last_session
        }
      end,
      meta: { total_count: }
    }
  end
end
