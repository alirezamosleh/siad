# frozen_string_literal: true

require 'bigbluebutton_api'
require 'cgi'
require 'digest/sha1'
require 'json'
require 'net/http'
require 'uri'
require 'rexml/document'
require 'rexml/xpath'

# JoinIN / custom BBB gateways often do not honor join `role=` (Greenlight default).
# They require the real meeting password from create/getMeetingInfo.
class BigBlueButtonApi
  def initialize(provider:)
    @provider = provider
    @endpoint, @secret = retrieve_credentials
  end

  def bbb_server
    @bbb_server ||= BigBlueButton::BigBlueButtonApi.new(
      @endpoint,
      @secret,
      '1.8'
    )
  end

  def start_meeting(room:, options: {}, presentation_url: nil)
    if presentation_url.present?
      modules = BigBlueButton::BigBlueButtonModules.new
      modules.add_presentation(:url, presentation_url)
      bbb_server.create_meeting(room.name, room.meeting_id, options, modules)
    else
      bbb_server.create_meeting(room.name, room.meeting_id, options)
    end
  end

  def join_meeting(room:, role:, user_id:, name: nil, avatar_url: nil)
    password = join_password_for(room:, role:)

    bbb_server.join_meeting_url(
      room.meeting_id,
      name,
      password,
      {
        userID: user_id,
        avatarURL: avatar_url,
        createTime: room.last_session&.to_datetime&.strftime('%Q')
      }.compact
    )
  end

  def meeting_running?(room:)
    bbb_server.is_meeting_running?(room.meeting_id)
  end

  def active_meetings
    bbb_server.get_meetings[:meetings]
  end

  def get_meeting_info(meeting_id:)
    bbb_server.get_meeting_info(meeting_id, nil)
  end

  def get_recording(record_id:)
    get_recordings(record_ids: record_id)[:recordings].first
  end

  def get_recordings(meeting_ids: nil, record_ids: nil)
    get_recordings_for(meeting_id: meeting_ids, record_ids:)
  end

  def get_recordings_for(meeting_id: nil, record_ids: nil)
    params = { 'secret_key' => @secret, 'state' => 'any' }
    params['meetingID'] = meeting_id if meeting_id
    params['recordID'] = record_ids if record_ids
    query = params.map { |k, v| "#{CGI.escape(k)}=#{CGI.escape(v.to_s)}" }.join('&')
    checksum = Digest::SHA1.hexdigest("getRecordings#{query}#{@secret}")
    uri = URI.parse("#{@endpoint.sub(%r{/\z}, '')}/getRecordings?#{query}&checksum=#{checksum}")

    http = Net::HTTP.new(uri.host, uri.port)
    http.use_ssl = (uri.scheme == 'https')
    http.open_timeout = 10
    http.read_timeout = 20
    response = http.request(Net::HTTP::Get.new(uri))
    raise "JoinIN getRecordings HTTP #{response.code}" unless response.is_a?(Net::HTTPSuccess)

    { recordings: parse_recordings_body(response.body) }
  end

  # JoinIN proprietary API: XML wrapper with a JSON array inside <sessions>.
  # Session timestamps use started_at / ended_at (not BBB startTime).
  def get_sessions(meeting_id:)
    params = {
      'secret_key' => @secret,
      'meetingID' => meeting_id.to_s,
      'minSessDur' => '0',
      'summary' => '0'
    }
    query = params.map { |k, v| "#{CGI.escape(k)}=#{CGI.escape(v)}" }.join('&')
    checksum = Digest::SHA1.hexdigest("getSessions#{query}#{@secret}")
    uri = URI.parse("#{@endpoint.to_s.sub(%r{/\z}, '')}/getSessions?#{query}&checksum=#{checksum}")

    http = Net::HTTP.new(uri.host, uri.port)
    http.use_ssl = (uri.scheme == 'https')
    http.open_timeout = 10
    http.read_timeout = 20
    response = http.request(Net::HTTP::Get.new(uri))

    unless response.is_a?(Net::HTTPSuccess)
      Rails.logger.warn("JoinIN getSessions HTTP #{response.code} for meeting #{meeting_id}")
      return []
    end

    parse_joinin_sessions_body(response.body)
  rescue Net::OpenTimeout, Net::ReadTimeout, SocketError, Errno::ECONNREFUSED, URI::InvalidURIError => e
    Rails.logger.warn("JoinIN getSessions failed: #{e.class}: #{e.message}")
    []
  end

  def parse_joinin_sessions_body(body)
    text = body.to_s.strip
    return [] if text.blank?

    # Prefer XML envelope used by JoinIN: <sessions>[...json...]</sessions>
    if text.start_with?('<')
      require 'rexml/document'
      require 'rexml/xpath'
      doc = REXML::Document.new(text)
      returncode = REXML::XPath.first(doc, '//returncode')&.text
      if returncode.present? && returncode != 'SUCCESS'
        Rails.logger.warn("JoinIN getSessions returncode=#{returncode}")
        return []
      end
      sessions_node = REXML::XPath.first(doc, '//sessions')
      text = sessions_node&.text.to_s.strip
      return [] if text.blank?
    end

    payload = JSON.parse(text)
    case payload
    when Array then payload
    when Hash then payload['sessions'] || payload[:sessions] || []
    else []
    end
  rescue REXML::ParseException, JSON::ParserError => e
    Rails.logger.warn("JoinIN getSessions parse failed: #{e.message}")
    []
  end

  def parse_recordings_body(body)
    document = REXML::Document.new(body.to_s)
    returncode = REXML::XPath.first(document, '//returncode')&.text
    return [] if returncode.present? && returncode != 'SUCCESS'

    REXML::XPath.match(document, '//recording').filter_map do |node|
      fields = {}
      node.elements.each do |element|
        next if element.name == 'metadata'

        fields[element.name.to_sym] = element.text.to_s
      end

      metadata = {}
      node.elements.each('metadata/*') do |element|
        metadata[element.name.to_sym] = element.text.to_s
      end
      metadata[:name] = fields[:name]
      metadata[:meetingId] = fields[:meetingID]
      listed = metadata[:'gl-listed'] || metadata[:'meta_gl-listed'] || metadata[:gl_listed]
      metadata[:'gl-listed'] = joinin_boolean(listed) if listed.present?
      protected_value = metadata[:'gl-protected'] || metadata[:'meta_gl-protected']
      metadata[:'gl-protected'] = joinin_boolean(protected_value) if protected_value.present?

      url = metadata[:playrecurl].presence || metadata[:joinin_playrecurl].presence
      next if fields[:recordID].blank? || fields[:meetingID].blank? || url.blank?

      {
        recordID: fields[:recordID],
        meetingID: fields[:meetingID],
        name: fields[:name],
        participants: fields[:participants].to_i,
        published: %w[true 1 yes published].include?(fields[:published].to_s.downcase),
        protected: joinin_boolean(fields[:protected] || metadata[:'gl-protected']) == 'true',
        startTime: fields[:startTime].to_i,
        metadata: metadata,
        playback: {
          format: {
            type: 'presentation',
            url: url,
            length: (fields[:recording_duration].presence || fields[:duration]).to_i
          }
        }
      }
    end
  rescue REXML::ParseException => e
    Rails.logger.warn("JoinIN getRecordings parse failed: #{e.message}")
    []
  end

  def joinin_boolean(value)
    %w[true 1 yes published protected].include?(value.to_s.downcase) ? 'true' : 'false'
  end

  def delete_recordings(record_ids:)
    bbb_server.delete_recordings(record_ids)
  end

  def publish_recordings(record_ids:, publish:)
    joinin_request(
      operation: 'publishRecordings',
      params: { 'recordID' => record_ids.to_s, 'publish' => publish.to_s }
    )
  end

  def update_recording_visibility(record_id:, visibility:)
    new_visibility_params = visibility_params_of(visibility)
    publish_recordings(record_ids: record_id, publish: new_visibility_params[:publish])
    update_recordings(record_id:, meta_hash: new_visibility_params[:meta_hash])
  end

  def update_recordings(record_id:, meta_hash:)
    current = get_recording(record_id:)
    current = current.respond_to?(:with_indifferent_access) ? current.with_indifferent_access : current
    params = {
      'recordID' => record_id.to_s,
      'name' => meta_hash[:meta_name] || meta_hash['meta_name'] ||
        current.dig(:metadata, :name) || current[:name] || '',
      'comment' => meta_hash[:comment] || meta_hash['comment'] || '',
      'note' => meta_hash[:note] || meta_hash['note'] || ''
    }
    meta_hash.each do |key, value|
      key = key.to_s
      params[key.start_with?('meta_') ? key : "meta_#{key}"] = value.to_s
    end
    joinin_request(operation: 'updateRecordings', params:)
  end

  def decode_jwt(token)
    JWT.decode token, @secret, true, { algorithm: 'HS256' }
  end

  def encode_jwt(payload)
    JWT.encode payload, @secret, 'HS256'
  end

  private

  def joinin_request(operation:, params:)
    params = { 'secret_key' => @secret }.merge(params)
    query = params.map { |key, value| "#{CGI.escape(key)}=#{CGI.escape(value.to_s)}" }.join('&')
    checksum = Digest::SHA1.hexdigest("#{operation}#{query}#{@secret}")
    uri = URI.parse("#{@endpoint.to_s.sub(%r{/\z}, '')}/#{operation}?#{query}&checksum=#{checksum}")
    http = Net::HTTP.new(uri.host, uri.port)
    http.use_ssl = (uri.scheme == 'https')
    http.open_timeout = 10
    http.read_timeout = 20
    response = http.request(Net::HTTP::Get.new(uri))
    raise "JoinIN #{operation} HTTP #{response.code}" unless response.is_a?(Net::HTTPSuccess)

    response.body
  end

  def join_password_for(room:, role:)
    info = bbb_server.get_meeting_info(room.meeting_id, nil)
    if role.to_s.casecmp('moderator').zero?
      info[:moderatorPW] || info['moderatorPW']
    else
      info[:attendeePW] || info['attendeePW']
    end
  end

  def retrieve_credentials
    if @provider == 'greenlight'
      [Rails.configuration.bigbluebutton_endpoint, Rails.configuration.bigbluebutton_secret]
    else
      ProviderCredentials.new(provider: @provider).call
    end
  end

  def visibility_params_of(visibility)
    params_of = {
      Recording::VISIBILITIES[:unpublished] => { publish: false, meta_hash: { protect: false, 'meta_gl-listed': false } },
      Recording::VISIBILITIES[:published] => { publish: true, meta_hash: { protect: false, 'meta_gl-listed': false } },
      Recording::VISIBILITIES[:public] => { publish: true, meta_hash: { protect: false, 'meta_gl-listed': true } },
      Recording::VISIBILITIES[:protected] => { publish: true, meta_hash: { protect: true, 'meta_gl-listed': false } },
      Recording::VISIBILITIES[:public_protected] => { publish: true, meta_hash: { protect: true, 'meta_gl-listed': true } }
    }

    params_of[visibility.to_s]
  end
end
