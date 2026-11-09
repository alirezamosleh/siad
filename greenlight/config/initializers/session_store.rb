# frozen_string_literal: true

# Secure cookies when served over HTTPS (nginx terminates TLS).
Rails.application.config.session_store :cookie_store,
                                        key: '_greenlight-3_0_session',
                                        secure: true,
                                        same_site: :lax,
                                        path: ENV.fetch('RELATIVE_URL_ROOT', '/')
