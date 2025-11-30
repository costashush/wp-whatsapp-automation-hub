<?php
/**
 * Plugin Name: WP WhatsApp Automation Hub
 * Plugin URI: https://github.com/storz/wp-whatsapp-automation-hub
 * Description: WhatsApp Cloud API webhook + admin sender + settings + file-based logs — all in one automation hub for WordPress.
 * Version: 1.0.0
 * Author: STORZ
 * Author URI: https://storz.co.il
 * License: GPLv2 or later
 * Text Domain: wp-whatsapp-automation-hub
 */

if (!defined('ABSPATH')) exit;

/** ---------------------------
 * Utilities: options + logging
 * -------------------------- */
function wpwb_opt($key, $default = '') {
    return get_option('wpwb_' . $key, $default);
}
function wpwb_set($key, $value) {
    return update_option('wpwb_' . $key, $value);
}
function wpwb_log_path() {
    $up  = wp_upload_dir();
    $dir = trailingslashit($up['basedir']) . 'wpwb';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    return $dir . '/whatsapp-bot.log';
}
function wpwb_log($msg, $context = null) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($context !== null) {
        $line .= ' :: ' . (is_string($context)
            ? $context
            : wp_json_encode($context, JSON_UNESCAPED_SLASHES)
        );
    }
    $line .= PHP_EOL;

    // Write to file
    @file_put_contents(wpwb_log_path(), $line, FILE_APPEND | LOCK_EX);

    // Also mirror to debug log if enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[WPWB] ' . $line);
    }
}

/** --------------------------------
 * WhatsApp send (Cloud API, Text)
 * ------------------------------- */
function wpwb_send($to, $msg) {
    $token   = wpwb_opt('access_token');
    $phoneId = wpwb_opt('phone_number_id');

    if (!$token || !$phoneId) {
        wpwb_log('send aborted: missing credentials');
        return false;
    }

    $url = "https://graph.facebook.com/v20.0/{$phoneId}/messages";
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'   => $to,
        'type' => 'text',
        'text' => ['body' => $msg],
    ];

    $res = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode($payload),
        'timeout' => 20,
    ]);

    if (is_wp_error($res)) {
        wpwb_log('send error', $res->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    wpwb_log('send status ' . $code, $body);

    return ($code >= 200 && $code < 300);
}

/** ----------------------
 * REST: ping + webhook
 * --------------------- */
add_action('rest_api_init', function () {

    // Health check
    register_rest_route('whatsapp/v1', '/ping', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            return new WP_REST_Response(['pong' => true], 200);
        },
    ]);

    // Webhook
    register_rest_route('whatsapp/v1', '/webhook', [
        'methods'             => ['GET', 'POST'],
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $req) {

            // Verify webhook (GET)
            if ($req->get_method() === 'GET') {
                $mode      = $req->get_param('hub_mode')         ?: $req->get_param('hub.mode');
                $token     = $req->get_param('hub_verify_token') ?: $req->get_param('hub.verify_token');
                $challenge = $req->get_param('hub_challenge')     ?: $req->get_param('hub.challenge');

                $verify = wpwb_opt('verify_token');
                wpwb_log('verify GET', ['mode' => $mode, 'token' => $token, 'challenge' => $challenge]);

                if ($mode === 'subscribe' && $verify && hash_equals($verify, (string) $token) && $challenge !== null) {
                    $resp = new WP_REST_Response($challenge, 200);
                    $resp->set_headers(['Content-Type' => 'text/plain; charset=utf-8']);
                    return $resp;
                }
                return new WP_REST_Response('Forbidden', 403);
            }

            // Handle incoming message (POST)
            $raw = $req->get_body();
            wpwb_log('incoming POST raw', $raw);

            $body = json_decode($raw, true);
            if (!$body || empty($body['entry'][0]['changes'][0]['value'])) {
                wpwb_log('no value in POST');
                return new WP_REST_Response(['ok' => true], 200);
            }

            $val  = $body['entry'][0]['changes'][0]['value'];
            $msg  = $val['messages'][0] ?? null;
            $from = $msg['from'] ?? null;
            $text = $msg['text']['body'] ?? '';
            $name = $val['contacts'][0]['profile']['name'] ?? 'there';

            if (!$from) {
                wpwb_log('no from in message');
                return new WP_REST_Response(['ok' => true], 200);
            }

            $reply = "Hi $name 👋 You said: " . ($text ?: '(no text)');
            $sent  = wpwb_send($from, $reply);

            // Log concise entry
            wpwb_log('inbound->reply', ['from' => $from, 'text' => $text, 'sent' => $sent]);

            return new WP_REST_Response(['sent' => (bool) $sent], 200);
        },
    ]);

});

/** -----------------------------
 * Admin page (Send / Settings / Logs)
 * ---------------------------- */
add_action('admin_menu', function () {
    add_menu_page(
        'WP WhatsApp Automation Hub',
        'WhatsApp Automation',
        'manage_options',
        'wpwb',
        'wpwb_admin_page',
        'dashicons-format-chat'
    );
});

function wpwb_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'send';

    // Handle forms
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['wpwb_action']) && check_admin_referer('wpwb_action_nonce')) {

            // Send tab
            if ($_POST['wpwb_action'] === 'send') {
                $phone = sanitize_text_field($_POST['wa_phone'] ?? '');
                $text  = sanitize_textarea_field($_POST['wa_message'] ?? '');
                $ok    = ($phone && $text) ? wpwb_send($phone, $text) : false;
                echo $ok
                    ? '<div class="updated"><p>Message sent ✅</p></div>'
                    : '<div class="error"><p>Failed to send ❌ — check credentials & logs.</p></div>';
            }

            // Settings tab
            if ($_POST['wpwb_action'] === 'save_settings') {
                wpwb_set('access_token', sanitize_text_field($_POST['access_token'] ?? ''));
                wpwb_set('phone_number_id', sanitize_text_field($_POST['phone_number_id'] ?? ''));
                wpwb_set('verify_token', sanitize_text_field($_POST['verify_token'] ?? ''));
                echo '<div class="updated"><p>Settings saved.</p></div>';
            }

            // Logs tab actions
            if ($_POST['wpwb_action'] === 'clear_logs') {
                @unlink(wpwb_log_path());
                echo '<div class="updated"><p>Logs cleared.</p></div>';
            }
        }
    }

    // Fetch current values (for Settings tab)
    $access_token    = esc_attr(wpwb_opt('access_token'));
    $phone_number_id = esc_attr(wpwb_opt('phone_number_id'));
    $verify_token    = esc_attr(wpwb_opt('verify_token'));
    $webhook_url     = esc_html(rest_url('whatsapp/v1/webhook'));
    $ping_url        = esc_html(rest_url('whatsapp/v1/ping'));
    $log_file        = wpwb_log_path();
    $log_exists      = file_exists($log_file);
    $log_size        = $log_exists ? size_format(filesize($log_file)) : '0 B';
    $log_content     = $log_exists ? esc_textarea(@file_get_contents($log_file)) : '';

    ?>
    <div class="wrap">
        <h1>WP WhatsApp Automation Hub</h1>
        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpwb&tab=send')); ?>"
               class="nav-tab <?php echo $active_tab === 'send' ? 'nav-tab-active' : ''; ?>">Send</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpwb&tab=settings')); ?>"
               class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpwb&tab=logs')); ?>"
               class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">Logs</a>
        </h2>

        <?php if ($active_tab === 'send'): ?>
            <p>Webhook: <code><?php echo $webhook_url; ?></code> &middot;
               Ping: <code><?php echo $ping_url; ?></code></p>
            <form method="post">
                <?php wp_nonce_field('wpwb_action_nonce'); ?>
                <input type="hidden" name="wpwb_action" value="send"/>
                <table class="form-table">
                    <tr>
                        <th scope="row">Phone (E.164 w/o +)</th>
                        <td><input type="text" name="wa_phone"
                                   class="regular-text"
                                   placeholder="9725XXXXXXXX"
                                   required></td>
                    </tr>
                    <tr>
                        <th scope="row">Message</th>
                        <td><textarea name="wa_message"
                                      class="large-text code"
                                      rows="5"
                                      placeholder="Type your message..."
                                      required></textarea></td>
                    </tr>
                </table>
                <?php submit_button('Send WhatsApp Message'); ?>
            </form>

        <?php elseif ($active_tab === 'settings'): ?>
            <form method="post">
                <?php wp_nonce_field('wpwb_action_nonce'); ?>
                <input type="hidden" name="wpwb_action" value="save_settings"/>
                <table class="form-table">
                    <tr>
                        <th scope="row">Access Token</th>
                        <td><input type="text" name="access_token"
                                   value="<?php echo $access_token; ?>"
                                   class="regular-text"
                                   placeholder="EAAG..."></td>
                    </tr>
                    <tr>
                        <th scope="row">Phone Number ID</th>
                        <td><input type="text" name="phone_number_id"
                                   value="<?php echo $phone_number_id; ?>"
                                   class="regular-text"
                                   placeholder="123456789012345"></td>
                    </tr>
                    <tr>
                        <th scope="row">Verify Token</th>
                        <td><input type="text" name="verify_token"
                                   value="<?php echo $verify_token; ?>"
                                   class="regular-text"
                                   placeholder="your-verify-token"></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>
            <p><strong>Meta Webhook Callback URL:</strong>
                <code><?php echo $webhook_url; ?></code></p>
            <p>Make sure to subscribe to <code>messages</code> events for your phone number inside Meta.</p>

        <?php elseif ($active_tab === 'logs'): ?>
            <p><strong>Log file:</strong>
                <code><?php echo esc_html($log_file); ?></code>
                (<?php echo esc_html($log_size); ?>)
            </p>
            <form method="post" style="margin-bottom:12px;">
                <?php wp_nonce_field('wpwb_action_nonce'); ?>
                <input type="hidden" name="wpwb_action" value="clear_logs"/>
                <?php submit_button('Clear Log', 'delete'); ?>
            </form>
            <textarea readonly rows="20"
                      style="width:100%; font-family:monospace;"><?php echo $log_content; ?></textarea>
        <?php endif; ?>
    </div>
    <?php
}

/** -----------------
 * First-time defaults
 * ---------------- */
register_activation_hook(__FILE__, function () {
    // Create uploads dir/log file early
    $path = wpwb_log_path();
    if (!file_exists($path)) {
        @file_put_contents($path, '[' . date('Y-m-d H:i:s') . "] Log created\n");
    }
});
