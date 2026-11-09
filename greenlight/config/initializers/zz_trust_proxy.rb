# frozen_string_literal: true

# Nginx terminates TLS and forwards X-Forwarded-Proto / Host.
# Trust the docker network proxy so force_ssl + secure cookies work correctly.
Rails.application.config.force_ssl = true
Rails.application.config.ssl_options = {
  redirect: { exclude: ->(request) { request.path.start_with?('/health') } },
  hsts: { expires: 1.year, subdomains: false }
}

# Rails 7+: treat requests as HTTPS when the proxy says so.
if Rails.application.config.respond_to?(:assume_ssl=)
  Rails.application.config.assume_ssl = true
end
