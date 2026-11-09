# frozen_string_literal: true

# Allow login from localhost AND 127.0.0.1 (and LAN IPs) without Origin CSRF mismatch.
Rails.application.config.action_controller.forgery_protection_origin_check = false
