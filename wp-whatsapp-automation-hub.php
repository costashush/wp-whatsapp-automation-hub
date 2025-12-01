<?php
/**
 * Plugin Name: WP WhatsApp Automation Hub
 * Plugin URI: https://github.com/costashush/wp-whatsapp-automation-hub
 * Description: WhatsApp Cloud API webhook + admin sender + settings + file-based logs — all in one automation hub for WordPress.
 * Version: 1.3.0
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
 * Admin menu (Send / Settings / Logs)
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

/** ---------------
 * Log tail helper
 * -------------- */
function wpwb_read_log_tail($lines = 300) {
    $file = wpwb_log_path();
    if (!file_exists($file)) {
        return '';
    }
    $content = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($content)) {
        return '';
    }
    $slice = array_slice($content, -abs(intval($lines)));
    return implode("\n", $slice);
}

/** -----------------------
 * Dashboard Widget
 * ---------------------- */
add_action('wp_dashboard_setup', 'wpwb_add_dashboard_widget');

function wpwb_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'wpwb_dashboard_widget',
        'WP WhatsApp Automation Hub',
        'wpwb_dashboard_widget_display'
    );
}

function wpwb_dashboard_widget_display() {
    if (!current_user_can('manage_options')) {
        echo '<p>No permission.</p>';
        return;
    }

    $widget_enabled = (int) wpwb_opt('widget_send_enabled', 1);

    // Minimal log (last 5 lines)
    $tail = wpwb_read_log_tail(40);
    $last_lines = '';
    if ($tail) {
        $lines = explode("\n", trim($tail));
        $last_lines = implode("\n", array_slice($lines, -5));
    }
    ?>
    <div style="font-size:12px;">

        <strong>Quick WhatsApp Send</strong>

        <?php if (!$widget_enabled): ?>
            <p style="font-size:11px;color:#b00;margin-top:4px;">
                Sending from widget is disabled in settings.
            </p>
        <?php else: ?>
            <div style="margin-top:6px; margin-bottom:8px;">
                <input type="text"
                       id="wpwb_widget_phone"
                       placeholder="Phone (9725XXXXXXXX)"
                       style="width:100%;padding:5px 7px;margin-bottom:4px;font-size:11px;">
                <textarea id="wpwb_widget_message"
                          placeholder="Message..."
                          style="width:100%;padding:5px 7px;min-height:60px;font-size:11px;"></textarea>
                <button type="button"
                        id="wpwb_widget_send_btn"
                        style="margin-top:4px;padding:4px 10px;font-size:11px;border-radius:4px;border:none;background:#25d366;color:#fff;cursor:pointer;">
                    Send
                </button>
                <div id="wpwb_widget_status" style="margin-top:4px;font-size:11px;"></div>
            </div>
        <?php endif; ?>

        <strong>Recent Log Lines</strong>
        <?php if (empty($last_lines)): ?>
            <p style="font-size:11px;color:#777;margin-top:4px;">No logs yet.</p>
        <?php else: ?>
            <pre style="margin-top:4px;font-size:11px;background:#fafafa;border:1px solid #eee;border-radius:4px;padding:4px 6px;max-height:130px;overflow:auto;"><?php echo esc_textarea($last_lines); ?></pre>
        <?php endif; ?>

        <p style="margin-top:4px;font-size:10px;color:#777;">
            Full UI and complete logs in <strong>WhatsApp Automation</strong> menu.
        </p>
    </div>

    <?php if ($widget_enabled): ?>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        var btn    = document.getElementById("wpwb_widget_send_btn");
        var status = document.getElementById("wpwb_widget_status");

        if (btn && typeof ajaxurl !== "undefined") {
            btn.addEventListener("click", function () {
                var phone = document.getElementById("wpwb_widget_phone").value;
                var msg   = document.getElementById("wpwb_widget_message").value;

                if (!phone || !msg) {
                    status.innerHTML = "Please fill phone and message.";
                    status.style.color = "#d93025";
                    return;
                }

                status.innerHTML = "Sending...";
                status.style.color = "#555";

                var formData = new FormData();
                formData.append("action", "wpwb_widget_send");
                formData.append("phone", phone);
                formData.append("message", msg);

                fetch(ajaxurl, {
                    method: "POST",
                    body: formData
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        status.innerHTML = data.data || "Message sent.";
                        status.style.color = "#2daa4a";
                    } else {
                        status.innerHTML = data.data || "Error sending message.";
                        status.style.color = "#d93025";
                    }
                })
                .catch(function () {
                    status.innerHTML = "Connection error.";
                    status.style.color = "#d93025";
                });
            });
        }
    });
    </script>
    <?php endif;
}

/** -------------------------
 * AJAX: widget quick send
 * ------------------------ */
add_action('wp_ajax_wpwb_widget_send', 'wpwb_widget_send_ajax');

function wpwb_widget_send_ajax() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied.');
    }

    $widget_enabled = (int) wpwb_opt('widget_send_enabled', 1);
    if (!$widget_enabled) {
        wp_send_json_error('Widget sending is disabled in settings.');
    }

    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $msg   = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';

    if (!$phone || !$msg) {
        wp_send_json_error('Phone and message required.');
    }

    $ok = wpwb_send($phone, $msg);

    if ($ok) {
        wpwb_log('widget quick send ok', ['phone' => $phone]);
        wp_send_json_success('Message sent successfully ✅');
    } else {
        wpwb_log('widget quick send failed', ['phone' => $phone]);
        wp_send_json_error('Failed to send. Check settings & logs.');
    }
}

/** -----------------------------
 * Admin page (Send / Settings / Logs)
 * ---------------------------- */
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
                    ? '<div class="notice notice-success"><p>Message sent ✅</p></div>'
                    : '<div class="notice notice-error"><p>Failed to send ❌ — check credentials & logs.</p></div>';
            }

            // Settings tab
            if ($_POST['wpwb_action'] === 'save_settings') {
                wpwb_set('access_token', sanitize_text_field($_POST['access_token'] ?? ''));
                wpwb_set('phone_number_id', sanitize_text_field($_POST['phone_number_id'] ?? ''));
                wpwb_set('verify_token', sanitize_text_field($_POST['verify_token'] ?? ''));
                wpwb_set('widget_send_enabled', isset($_POST['widget_send_enabled']) ? 1 : 0);
                echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
            }

            // Logs tab actions
            if ($_POST['wpwb_action'] === 'clear_logs') {
                @unlink(wpwb_log_path());
                echo '<div class="notice notice-success"><p>Logs cleared.</p></div>';
            }
        }
    }

    // Fetch current values (for Settings tab)
    $access_token       = esc_attr(wpwb_opt('access_token'));
    $phone_number_id    = esc_attr(wpwb_opt('phone_number_id'));
    $verify_token       = esc_attr(wpwb_opt('verify_token'));
    $widget_send_enabled = (int) wpwb_opt('widget_send_enabled', 1);

    $webhook_url     = esc_html(rest_url('whatsapp/v1/webhook'));
    $ping_url        = esc_html(rest_url('whatsapp/v1/ping'));
    $log_file        = wpwb_log_path();
    $log_exists      = file_exists($log_file);
    $log_size        = $log_exists ? size_format(filesize($log_file)) : '0 B';
    $log_content     = $log_exists ? esc_textarea(wpwb_read_log_tail(300)) : '';

    ?>
    <div class="wrap wpwb-wrap">
        <h1 style="margin-bottom: 10px;">WP WhatsApp Automation Hub</h1>
        <p style="color:#666;margin-bottom:20px;">
            Webhook + sender + logging, all in one place. Use Meta Cloud API to automate your WhatsApp.
        </p>

        <style>
            .wpwb-tabs .nav-tab {
                padding: 8px 18px;
                font-size: 13px;
            }
            .wpwb-grid {
                display: grid;
                gap: 24px;
                grid-template-columns: 1.1fr 0.9fr;
            }
            @media (max-width: 1024px) {
                .wpwb-grid {
                    grid-template-columns: 1fr;
                }
            }
            .wpwb-card {
                background:#fff;
                border-radius:10px;
                padding:18px 20px;
                border:1px solid #e2e2e2;
                box-shadow:0 4px 10px rgba(0,0,0,0.03);
            }
            .wpwb-card h2 {
                margin:0 0 10px;
                font-size:16px;
                border-bottom:1px solid #eee;
                padding-bottom:6px;
            }
            .wpwb-field {
                margin-bottom:12px;
            }
            .wpwb-field label {
                display:block;
                font-size:13px;
                font-weight:500;
                margin-bottom:3px;
            }
            .wpwb-input,
            .wpwb-textarea {
                width:100%;
                border-radius:6px;
                border:1px solid #dcdcdc;
                padding:7px 9px;
                font-size:13px;
            }
            .wpwb-textarea {
                min-height:120px;
                resize:vertical;
            }
            .wpwb-logarea {
                width:100%;
                border-radius:6px;
                border:1px solid #dcdcdc;
                padding:6px 8px;
                font-family:Menlo,Consolas,monospace;
                font-size:11px;
                min-height:260px;
                resize:vertical;
                background:#fafafa;
                white-space:pre;
            }
            .wpwb-small {
                font-size:11px;
                color:#777;
            }
            .wpwb-badge {
                display:inline-block;
                padding:2px 6px;
                border-radius:999px;
                background:#f1f3f4;
                font-size:11px;
                margin-left:4px;
            }
            .wpwb-token-wrap {
                position:relative;
            }
            .wpwb-token-toggle {
                position:absolute;
                right:8px;
                top:50%;
                transform:translateY(-50%);
                cursor:pointer;
                font-size:11px;
                color:#555;
                background:#f1f1f1;
                padding:2px 6px;
                border-radius:4px;
            }
        </style>

        <div class="wpwb-tabs nav-tab-wrapper" style="margin-bottom:15px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpwb&tab=send')); ?>"
               class="nav-tab <?php echo $active_tab === 'send' ? 'nav-tab-active' : ''; ?>">Send</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpwb&tab=settings')); ?>"
               class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpwb&tab=logs')); ?>"
               class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">Logs</a>
        </div>

        <?php if ($active_tab === 'send'): ?>

            <div class="wpwb-grid">
                <div class="wpwb-card">
                    <h2>Send Test Message</h2>
                    <p class="wpwb-small" style="margin-bottom:12px;">
                        Use this form to send a manual message via WhatsApp Cloud API.
                    </p>
                    <form method="post">
                        <?php wp_nonce_field('wpwb_action_nonce'); ?>
                        <input type="hidden" name="wpwb_action" value="send"/>
                        <div class="wpwb-field">
                            <label>Phone (E.164 without +)</label>
                            <input type="text"
                                   name="wa_phone"
                                   class="wpwb-input"
                                   placeholder="9725XXXXXXXX"
                                   required>
                        </div>
                        <div class="wpwb-field">
                            <label>Message</label>
                            <textarea name="wa_message"
                                      class="wpwb-textarea"
                                      placeholder="Type your message..."
                                      required></textarea>
                        </div>
                        <?php submit_button('Send WhatsApp Message'); ?>
                    </form>
                </div>

                <div class="wpwb-card">
                    <h2>Webhook Info</h2>
                    <p class="wpwb-small">
                        Use this URL in your Meta App / WhatsApp configuration.
                    </p>
                    <p><strong>Webhook URL</strong><br>
                        <code><?php echo $webhook_url; ?></code></p>
                    <p><strong>Ping URL</strong><br>
                        <code><?php echo $ping_url; ?></code></p>
                    <p class="wpwb-small">
                        Make sure you subscribe to <code>messages</code> events on your phone number.
                    </p>
                </div>
            </div>

        <?php elseif ($active_tab === 'settings'): ?>

            <div class="wpwb-grid">
                <div class="wpwb-card">
                    <h2>Cloud API Credentials</h2>
                    <form method="post">
                        <?php wp_nonce_field('wpwb_action_nonce'); ?>
                        <input type="hidden" name="wpwb_action" value="save_settings"/>

                        <div class="wpwb-field wpwb-token-wrap">
                            <label>Access Token</label>
                            <input type="password"
                                   id="wpwb_token"
                                   name="access_token"
                                   value="<?php echo $access_token; ?>"
                                   class="wpwb-input"
                                   placeholder="EAAG...">
                            <span id="wpwb_token_toggle" class="wpwb-token-toggle">Show</span>
                            <p class="wpwb-small">Paste your long-lived token from Meta Developers.</p>
                        </div>

                        <div class="wpwb-field">
                            <label>Phone Number ID</label>
                            <input type="text"
                                   name="phone_number_id"
                                   value="<?php echo $phone_number_id; ?>"
                                   class="wpwb-input"
                                   placeholder="123456789012345">
                        </div>

                        <div class="wpwb-field">
                            <label>Verify Token</label>
                            <input type="text"
                                   name="verify_token"
                                   value="<?php echo $verify_token; ?>"
                                   class="wpwb-input"
                                   placeholder="your-verify-token">
                            <p class="wpwb-small">
                                This value must match the token you configure for verification in Meta.
                            </p>
                        </div>

                        <div class="wpwb-field">
                            <label>
                                <input type="checkbox"
                                       name="widget_send_enabled"
                                       value="1" <?php checked($widget_send_enabled, 1); ?>>
                                <span style="margin-left:4px;">Allow send from Dashboard widget</span>
                            </label>
                            <p class="wpwb-small">
                                Disable if you don’t want admins to send WhatsApp messages directly from the Dashboard widget.
                            </p>
                        </div>

                        <?php submit_button('Save Settings'); ?>
                    </form>
                </div>

                <div class="wpwb-card">
                    <h2>Webhook Callback URL</h2>
                    <p><strong>Callback URL</strong><br>
                        <code><?php echo $webhook_url; ?></code></p>
                    <p class="wpwb-small">
                        Use this when setting up your webhook in Meta. For verification, Meta will call this URL
                        with <code>hub.mode</code>, <code>hub.verify_token</code> and
                        <code>hub.challenge</code>.
                    </p>
                    <p class="wpwb-small">
                        After verification, incoming messages will trigger an automatic reply, which you can
                        customize in the code (<code>wpwb_send()</code> / webhook callback).
                    </p>
                </div>
            </div>

        <?php elseif ($active_tab === 'logs'): ?>

            <div class="wpwb-grid">
                <div class="wpwb-card" style="grid-column:1/-1;">
                    <h2>Logs</h2>
                    <p class="wpwb-small">
                        File: <code><?php echo esc_html($log_file); ?></code>
                        <span class="wpwb-badge"><?php echo esc_html($log_size); ?></span>
                    </p>
                    <form method="post" style="margin-bottom:10px;">
                        <?php wp_nonce_field('wpwb_action_nonce'); ?>
                        <input type="hidden" name="wpwb_action" value="clear_logs"/>
                        <?php submit_button('Clear Log', 'delete', '', false); ?>
                        <span class="wpwb-small" style="margin-left:8px;">Clears the log file on disk.</span>
                    </form>
                    <textarea readonly
                              class="wpwb-logarea"
                              rows="18"><?php echo $log_content; ?></textarea>
                    <p class="wpwb-small" style="margin-top:6px;">
                        Showing last ~300 lines from the file (newest at bottom). For full log, open the file via
                        FTP or file manager.
                    </p>
                </div>
            </div>

        <?php endif; ?>

        <script>
        document.addEventListener("DOMContentLoaded", function () {
            var tokenInput  = document.getElementById("wpwb_token");
            var tokenToggle = document.getElementById("wpwb_token_toggle");

            if (tokenInput && tokenToggle) {
                tokenToggle.addEventListener("click", function () {
                    if (tokenInput.type === "password") {
                        tokenInput.type = "text";
                        tokenToggle.textContent = "Hide";
                    } else {
                        tokenInput.type = "password";
                        tokenToggle.textContent = "Show";
                    }
                });
            }
        });
        </script>
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
