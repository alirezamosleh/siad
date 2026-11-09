# frozen_string_literal: true

module Authorizable
  extend ActiveSupport::Concern

  def ensure_valid_request
    return if Rails.env.development? || valid_api_request?

    # Invalid API-shaped requests must still get the HTML SPA, not a JSON template miss.
    render template: 'components/index', formats: [:html], content_type: 'text/html'
  end

  def ensure_authenticated
    render_error status: :unauthorized unless current_user
  end

  def ensure_unauthenticated
    render_error status: :unauthorized if current_user
  end

  def ensure_authorized(permission_names, user_id: nil, friendly_id: nil, record_id: nil)
    render_error status: :forbidden unless PermissionsChecker.new(
      current_user:,
      permission_names:,
      user_id:,
      friendly_id:,
      record_id:,
      current_provider:
    ).call
  end

  def ensure_super_admin
    render_error status: :forbidden unless current_user.super_admin?
  end

  private

  def valid_api_request?
    request.format == :json && request.headers['Accept']&.include?('application/json')
  end
end
