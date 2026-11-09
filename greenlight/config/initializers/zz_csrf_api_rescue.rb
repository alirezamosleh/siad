# frozen_string_literal: true

# Turn HTML 422 "Unprocessable Content" CSRF failures into JSON for /api/v1/*
# so the SPA can show a toast / reload instead of Firefox's site-error page.
Rails.application.config.to_prepare do
  Api::V1::ApiController.class_eval do
    rescue_from ActionController::InvalidAuthenticityToken do |_exception|
      render json: { errors: ['InvalidAuthenticityToken'] }, status: :unprocessable_entity
    end
  end
rescue NameError
  # Api controller not loaded yet during some rake tasks
end

Rails.application.config.action_controller.forgery_protection_origin_check = false
