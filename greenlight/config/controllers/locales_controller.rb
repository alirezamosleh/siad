# frozen_string_literal: true

# Hoda patch: serve locale JSON from app/assets/locales directly.
# Upstream production redirects to fingerprinted /assets/<locale>-<digest>.json,
# so mounting custom fa.json / fa_IR.json under app/assets/locales had no effect.

module Api
  module V1
    class LocalesController < ApiController
      skip_before_action :ensure_authenticated, only: :show
      skip_before_action :ensure_valid_request, only: :show

      # GET /api/v1/locales
      def index
        language_with_name = Rails.cache.fetch('v3/locales/list', expires_in: 24.hours) do
          language_hash = {}

          languages = Dir.entries(Rails.root.join('app/assets/locales')).select { |file_name| file_name.ends_with?('.json') }
          language_list = I18n::Language::Mapping.language_mapping_list

          languages.each do |lang|
            language = lang.split('.').first.tr('_', '-')
            native_name = language_list.dig(language, 'nativeName')

            language_hash[language] = native_name if native_name.present?
          end

          language_hash
        end

        render_data data: language_with_name, status: :ok
      end

      # GET /api/v1/locales/:name
      def show
        language = params[:name].to_s.tr('-', '_')
        locale_dir = Rails.root.join('app/assets/locales')
        candidates = Dir.entries(locale_dir).select { |f| f.end_with?('.json') && f.start_with?(language) }

        # Prefer exact file (fa.json) over prefix match (fa_IR.json) when locale is "fa"
        exact = candidates.find { |f| f == "#{language}.json" }
        final_language = (exact || candidates.min)&.delete_suffix('.json')
        raise StandardError, 'locale missing' if final_language.blank?

        path = locale_dir.join("#{final_language}.json")
        render file: path, content_type: 'application/json', layout: false
      rescue StandardError
        head :not_acceptable
      end
    end
  end
end
