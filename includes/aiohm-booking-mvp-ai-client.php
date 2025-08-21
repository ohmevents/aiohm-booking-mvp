<?php
/**
 * AI client for AIOHM Booking multi-provider API requests
 * Handles OpenAI, Google Gemini, and ShareAI integrations for booking assistance
 */
if (!defined('ABSPATH')) exit;

class AIOHM_BOOKING_MVP_AI_Client {

    private $settings;
    private $openai_api_key;
    private $gemini_api_key;
    private $shareai_api_key;

    public function __construct($settings = null) {
        if ($settings === null) {
            $this->settings = aiohm_booking_mvp_opts();
        } else {
            $this->settings = $settings;
        }
        $this->openai_api_key = $this->settings['openai_api_key'] ?? '';
        $this->gemini_api_key = $this->settings['gemini_api_key'] ?? '';
        $this->shareai_api_key = $this->settings['shareai_api_key'] ?? '';
    }

    /**
     * Check if API key is properly configured for the given provider
     * @param string $provider The AI provider (openai, gemini, shareai)
     * @return bool True if API key is configured
     */
    public function is_api_key_configured($provider) {
        switch ($provider) {
            case 'openai':
                return !empty($this->openai_api_key);
            case 'gemini':
                return !empty($this->gemini_api_key);
            case 'shareai':
                return !empty($this->shareai_api_key);
            default:
                return false;
        }
    }

    /**
     * Check rate limit for API calls with IP-based fallback
     * @param string $provider The AI provider
     * @return bool True if within rate limit
     */
    private function check_rate_limit($provider) {
        $user_id = get_current_user_id();
        $user_ip = $this->get_client_ip();
        $max_requests = 50; // Conservative limit for booking plugin

        $user_key = "aiohm_booking_mvp_rate_limit_{$provider}_user_{$user_id}";
        $user_count = get_transient($user_key);

        $ip_key = "aiohm_booking_mvp_rate_limit_{$provider}_ip_" . md5($user_ip);
        $ip_count = get_transient($ip_key);

        if ($user_count === false) {
            set_transient($user_key, 1, HOUR_IN_SECONDS);
            $user_count = 1;
        }

        if ($ip_count === false) {
            set_transient($ip_key, 1, HOUR_IN_SECONDS);
            $ip_count = 1;
        }

        if ($user_count >= $max_requests || $ip_count >= $max_requests) {
            return false;
        }

        set_transient($user_key, $user_count + 1, HOUR_IN_SECONDS);
        set_transient($ip_key, $ip_count + 1, HOUR_IN_SECONDS);

        return true;
    }

    /**
     * Get client IP address securely
     * @return string Client IP address
     */
    private function get_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '127.0.0.1';

        return $ip;
    }

    /**
     * Test OpenAI API connection
     * @return array Result with success status and message
     */
    public function test_openai_api_connection() {
        if (empty($this->openai_api_key)) {
            return ['success' => false, 'error' => 'OpenAI API key is missing.'];
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openai_api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'user', 'content' => 'Test connection']
                ],
                'max_tokens' => 10
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => 'Connection failed: ' . $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message']];
        }

        return ['success' => true, 'message' => 'OpenAI connection successful!'];
    }

    /**
     * Test Gemini API connection
     * @return array Result with success status and message
     */
    public function test_gemini_api_connection() {
        if (empty($this->gemini_api_key)) {
            return ['success' => false, 'error' => 'Gemini API key is missing.'];
        }

        // Try a simpler endpoint first - list models
        $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $this->gemini_api_key;

        $response = wp_remote_get($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'AIOHM-Booking-Plugin/1.0'
            ],
            'timeout' => 15,
            'sslverify' => true,
            'httpversion' => '1.1'
        ]);

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            // Provide more specific error messages
            if (strpos($error_msg, 'cURL error 28') !== false) {
                return ['success' => false, 'error' => 'Connection timeout. Please check your internet connection or try again later.'];
            } elseif (strpos($error_msg, 'SSL') !== false) {
                return ['success' => false, 'error' => 'SSL connection failed. Please check server SSL configuration.'];
            }
            return ['success' => false, 'error' => 'Connection failed: ' . $error_msg];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code === 200) {
            $data = json_decode($body, true);
            if (isset($data['models']) && is_array($data['models'])) {
                return ['success' => true, 'message' => 'Gemini API connection successful! Found ' . count($data['models']) . ' available models.'];
            }
        }

        // Handle specific error codes
        if ($status_code === 400) {
            return ['success' => false, 'error' => 'Invalid API key format. Please check your Gemini API key.'];
        } elseif ($status_code === 403) {
            return ['success' => false, 'error' => 'API key is invalid or does not have permission to access Gemini API.'];
        } elseif ($status_code === 429) {
            return ['success' => false, 'error' => 'API quota exceeded. Please check your Gemini API usage limits.'];
        }

        // Try to parse error from response body
        $data = json_decode($body, true);
        if (isset($data['error'])) {
            return ['success' => false, 'error' => 'API Error: ' . ($data['error']['message'] ?? 'Unknown error')];
        }

        return ['success' => false, 'error' => 'Unexpected response from Gemini API (Status: ' . $status_code . ')'];
    }

    /**
     * Test ShareAI API connection
     * @return array Result with success status and message
     */
    public function test_shareai_api_connection() {
        if (empty($this->shareai_api_key)) {
            return ['success' => false, 'error' => 'ShareAI API key is missing.'];
        }

        // ShareAI LLM Server endpoint
        $response = wp_remote_post('https://api.shareai.app/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->shareai_api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'user', 'content' => 'Test connection']
                ],
                'max_tokens' => 10
            ]),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            $error_message = 'Connection failed: ' . $response->get_error_message();
            // Connection error - silent failure
            return ['success' => false, 'error' => $error_message];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Process API response

        $data = json_decode($body, true);

        if ($response_code !== 200) {
            $error_message = $data['error']['message'] ?? 'HTTP ' . $response_code;
            return ['success' => false, 'error' => $error_message];
        }

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message']];
        }

        return ['success' => true, 'message' => 'ShareAI connection successful!'];
    }

    /**
     * Generate AI assistance for booking inquiries
     * @param string $prompt The user's inquiry
     * @param string $provider The AI provider to use
     * @return array Result with AI response or error
     */
    public function generate_booking_assistance($prompt, $provider = 'shareai') {
        if (!$this->check_rate_limit($provider)) {
            return ['success' => false, 'error' => 'Rate limit exceeded. Please try again later.'];
        }

        if (!$this->is_api_key_configured($provider)) {
            return ['success' => false, 'error' => "API key not configured for {$provider}."];
        }

        // Enhanced prompt for booking context
        $system_prompt = "You are a helpful assistant for AIOHM Booking, a conscious business booking system. Help users with booking inquiries, room availability, pricing questions, and event information. Be warm, professional, and aligned with conscious business values.";
        $full_prompt = $system_prompt . "\n\nUser inquiry: " . $prompt;

        switch ($provider) {
            case 'openai':
                return $this->call_openai_api($full_prompt);
            case 'gemini':
                return $this->call_gemini_api($full_prompt);
            case 'shareai':
                return $this->call_shareai_api($full_prompt);
            default:
                return ['success' => false, 'error' => 'Unsupported AI provider.'];
        }
    }

    /**
     * Call OpenAI API
     */
    private function call_openai_api($prompt) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openai_api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => 500,
                'temperature' => 0.7
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message']];
        }

        if (isset($data['choices'][0]['message']['content'])) {
            return ['success' => true, 'response' => trim($data['choices'][0]['message']['content'])];
        }

        return ['success' => false, 'error' => 'No response from OpenAI.'];
    }

    /**
     * Call Gemini API
     */
    private function call_gemini_api($prompt) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $this->gemini_api_key;

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ]
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message']];
        }

        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return ['success' => true, 'response' => trim($data['candidates'][0]['content']['parts'][0]['text'])];
        }

        return ['success' => false, 'error' => 'No response from Gemini.'];
    }

    /**
     * Call ShareAI API
     */
    private function call_shareai_api($prompt) {
        $response = wp_remote_post('https://api.shareai.app/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->shareai_api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => 500,
                'temperature' => 0.7
            ]),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            $error_message = 'ShareAI API Error: ' . $response->get_error_message();
            // Error logged internally but not to prevent activation issues
            return ['success' => false, 'error' => $error_message];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message']];
        }

        if (isset($data['choices'][0]['message']['content'])) {
            return ['success' => true, 'response' => trim($data['choices'][0]['message']['content'])];
        }

        return ['success' => false, 'error' => 'No response from ShareAI.'];
    }
}