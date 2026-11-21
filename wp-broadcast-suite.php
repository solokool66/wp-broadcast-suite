<?php
/**
 * Plugin Name: Enterprise Broadcast & Telemetry Suite
 * Plugin URI: https://github.com/solokool66/wp-broadcast-suite
 * Description: High-throughput Omni-Channel Broadcast Suite for WordPress — Dual Email (Amazon SES / SMTP) + SMS Gateway (Termii / HollaTags), Open/Click Engagement Telemetry, WP-Cron Scheduler, and Analytics Dashboard.
 * Version: 3.1
 * Author: Solomon Kingsley Eze
 * Author URI: https://linkedin.com/in/solokool
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ================================================================
// 1. DATABASE SETUP
// ================================================================
add_action('admin_init', 'tka_broadcast_check_tables');
function tka_broadcast_check_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Broadcast History
    $history_table = $wpdb->prefix . 'church_broadcast_history';
    $sql1 = "CREATE TABLE $history_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        broadcast_type varchar(10) DEFAULT 'email' NOT NULL,
        subject varchar(255) NOT NULL,
        message_content longtext,
        audience_label varchar(255) NOT NULL,
        date_sent datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        total_sent int(11) DEFAULT 0 NOT NULL,
        total_failed int(11) DEFAULT 0 NOT NULL,
        total_opens int(11) DEFAULT 0 NOT NULL,
        total_clicks int(11) DEFAULT 0 NOT NULL,
        failed_emails longtext,
        scheduled_at datetime DEFAULT NULL,
        status varchar(20) DEFAULT 'sent' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql1);

    // Templates
    $templates_table = $wpdb->prefix . 'church_broadcast_templates';
    $sql2 = "CREATE TABLE $templates_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        template_name varchar(255) NOT NULL,
        template_content longtext NOT NULL,
        date_created datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql2);

    // Address Book
    $contacts_table = $wpdb->prefix . 'church_broadcast_contacts';
    $sql3 = "CREATE TABLE $contacts_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        full_name varchar(255) DEFAULT '' NOT NULL,
        email varchar(255) DEFAULT '' NOT NULL,
        phone varchar(50) DEFAULT '' NOT NULL,
        group_name varchar(255) DEFAULT 'General',
        date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql3);
    // Add phone column or relax email constraint on existing installs
    $col = $wpdb->get_var("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$contacts_table' AND column_name = 'phone'");
    if (empty($col)) { 
        $wpdb->query("ALTER TABLE $contacts_table ADD phone varchar(50) DEFAULT '' NOT NULL AFTER email"); 
    } else {
        $wpdb->query("ALTER TABLE $contacts_table MODIFY email varchar(255) DEFAULT '' NOT NULL");
        $wpdb->query("ALTER TABLE $contacts_table MODIFY phone varchar(50) DEFAULT '' NOT NULL");
    }

    // Tracking Events
    $tracking_table = $wpdb->prefix . 'church_broadcast_tracking';
    $sql4 = "CREATE TABLE $tracking_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        broadcast_id mediumint(9) NOT NULL,
        event_type varchar(10) NOT NULL,
        recipient_email varchar(255) NOT NULL,
        url longtext,
        event_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY broadcast_id (broadcast_id),
        KEY event_type (event_type)
    ) $charset_collate;";
    dbDelta($sql4);

    // Scheduled Queue
    $queue_table = $wpdb->prefix . 'church_broadcast_queue';
    $sql5 = "CREATE TABLE $queue_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        broadcast_type varchar(10) DEFAULT 'email',
        subject varchar(255) NOT NULL,
        message_content longtext NOT NULL,
        audience_label varchar(255),
        recipients longtext NOT NULL,
        scheduled_at datetime NOT NULL,
        attachment_path varchar(500) DEFAULT '',
        attachment_url varchar(500) DEFAULT '',
        attachment_name varchar(255) DEFAULT '',
        status varchar(20) DEFAULT 'pending' NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql5);

    // Ensure history table has new columns for older installs
    $cols_to_add = [
        'broadcast_type' => "ALTER TABLE $history_table ADD broadcast_type varchar(10) DEFAULT 'email' AFTER id",
        'total_opens'    => "ALTER TABLE $history_table ADD total_opens int(11) DEFAULT 0 AFTER total_failed",
        'total_clicks'   => "ALTER TABLE $history_table ADD total_clicks int(11) DEFAULT 0 AFTER total_opens",
        'scheduled_at'   => "ALTER TABLE $history_table ADD scheduled_at datetime DEFAULT NULL AFTER total_clicks",
        'status'         => "ALTER TABLE $history_table ADD status varchar(20) DEFAULT 'sent' AFTER scheduled_at",
        'message_content'=> "ALTER TABLE $history_table ADD message_content longtext AFTER subject",
        'from_name'      => "ALTER TABLE $history_table ADD from_name varchar(255) DEFAULT '' AFTER audience_label",
        'from_email'     => "ALTER TABLE $history_table ADD from_email varchar(255) DEFAULT '' AFTER from_name",
    ];
    foreach ($cols_to_add as $col_name => $alter_sql) {
        $exists = $wpdb->get_var("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$history_table' AND column_name = '$col_name'");
        if (empty($exists)) { $wpdb->query($alter_sql); }
    }

    // Ensure queue table has sender profile columns
    $queue_cols = [
        'from_name'  => "ALTER TABLE $queue_table ADD from_name varchar(255) DEFAULT '' AFTER audience_label",
        'from_email' => "ALTER TABLE $queue_table ADD from_email varchar(255) DEFAULT '' AFTER from_name",
        'reply_to'   => "ALTER TABLE $queue_table ADD reply_to varchar(255) DEFAULT '' AFTER from_email",
        'logo_url'   => "ALTER TABLE $queue_table ADD logo_url varchar(500) DEFAULT '' AFTER reply_to",
    ];
    foreach ($queue_cols as $q_col => $q_sql) {
        $exists = $wpdb->get_var("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$queue_table' AND column_name = '$q_col'");
        if (empty($exists)) { $wpdb->query($q_sql); }
    }
}

// ================================================================
// 2. ADMIN MENU
// ================================================================
add_action('admin_menu', function() {
    add_menu_page('Broadcast Studio', 'Broadcast Studio', 'manage_options', 'church-broadcast', 'render_church_broadcast_studio', 'dashicons-email-alt', 7);
});

// ================================================================
// 3. OPEN/CLICK TRACKING ENDPOINT
// ================================================================
add_action('init', function() {
    if (isset($_GET['tka_track'])) {
        $type  = sanitize_text_field($_GET['tka_track']);
        $bid   = intval($_GET['bid'] ?? 0);
        $email = sanitize_email(base64_decode($_GET['em'] ?? ''));
        $raw_url = base64_decode($_GET['url'] ?? '');
        $url   = esc_url_raw(html_entity_decode($raw_url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($bid && $email) {
            global $wpdb;
            $tracking_table = $wpdb->prefix . 'church_broadcast_tracking';
            $history_table  = $wpdb->prefix . 'church_broadcast_history';

            // Deduplicate opens per recipient per broadcast
            $already = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $tracking_table WHERE broadcast_id=%d AND event_type=%s AND recipient_email=%s",
                $bid, $type, $email
            ));

            if (!$already) {
                $wpdb->insert($tracking_table, [
                    'broadcast_id'   => $bid,
                    'event_type'     => $type,
                    'recipient_email'=> $email,
                    'url'            => ($type === 'click') ? $url : '',
                    'event_time'     => current_time('mysql'),
                ]);
                if ($type === 'open')  $wpdb->query($wpdb->prepare("UPDATE $history_table SET total_opens  = total_opens  + 1 WHERE id = %d", $bid));
                if ($type === 'click') $wpdb->query($wpdb->prepare("UPDATE $history_table SET total_clicks = total_clicks + 1 WHERE id = %d", $bid));
            }
        }

        if ($type === 'open') {
            // Return a 1x1 transparent GIF
            header('Content-Type: image/gif');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }

        if ($type === 'click' && $url) {
            wp_redirect($url);
            exit;
        }
        exit;
    }
});

// ================================================================
// ================================================================
// 4. SCHEDULED SENDS — WP CRON & QUEUE ENGINE
// ================================================================
add_action('tka_process_broadcast_queue', 'tka_run_broadcast_queue');
function tka_run_broadcast_queue() {
    global $wpdb;
    $queue_table = $wpdb->prefix . 'church_broadcast_queue';
    $now = current_time('mysql');

    // Recover jobs stuck in 'running' for > 30 minutes due to server timeouts
    $wpdb->query("UPDATE $queue_table SET status='pending' WHERE status='running' AND scheduled_at <= DATE_SUB('$now', INTERVAL 30 MINUTE)");

    $jobs = $wpdb->get_results("SELECT id FROM $queue_table WHERE status='pending' AND scheduled_at <= '$now' LIMIT 3");
    foreach ($jobs as $job) {
        tka_execute_queue_job($job->id);
    }
}

function tka_execute_queue_job($job_id) {
    global $wpdb;
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    $queue_table   = $wpdb->prefix . 'church_broadcast_queue';
    $history_table = $wpdb->prefix . 'church_broadcast_history';

    $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $queue_table WHERE id = %d", $job_id));
    if (!$job || in_array($job->status, ['done', 'cancelled'])) return false;

    // Mark running
    $wpdb->update($queue_table, ['status' => 'running'], ['id' => $job->id]);

    $recipients = json_decode($job->recipients, true);
    if (empty($recipients)) {
        $wpdb->update($queue_table, ['status' => 'done'], ['id' => $job->id]);
        return false;
    }

    // Insert history record
    $wpdb->insert($history_table, [
        'broadcast_type' => $job->broadcast_type,
        'subject'        => $job->subject,
        'message_content'=> $job->message_content,
        'audience_label' => $job->audience_label . ' (Scheduled)',
        'from_name'      => !empty($job->from_name) ? $job->from_name : '',
        'from_email'     => !empty($job->from_email) ? $job->from_email : '',
        'date_sent'      => current_time('mysql'),
        'total_sent'     => 0,
        'total_failed'   => 0,
        'scheduled_at'   => $job->scheduled_at,
        'status'         => 'sent',
    ]);
    $broadcast_id = $wpdb->insert_id;

    if ($job->broadcast_type === 'sms') {
        $sent = 0; $failed = 0;
        foreach ($recipients as $p) {
            $result = tka_broadcast_send_sms($p['phone'] ?? '', $job->message_content, $p['name'] ?? 'Friend');
            if ($result) $sent++; else $failed++;
            usleep(100000); // 100ms pause
        }
        $wpdb->update($history_table, ['total_sent' => $sent, 'total_failed' => $failed], ['id' => $broadcast_id]);
    } else {
        // Email — batch send with pause to protect SMTP rate limits
        $batch_size = 25;
        $sent = 0; $failed = 0; $failed_emails = [];
        $chunks = array_chunk($recipients, $batch_size);
        $job_from_name  = !empty($job->from_name) ? $job->from_name : '';
        $job_from_email = !empty($job->from_email) ? $job->from_email : '';
        $job_reply_to   = !empty($job->reply_to) ? $job->reply_to : '';
        $job_logo_url   = !empty($job->logo_url) ? $job->logo_url : '';

        foreach ($chunks as $batch) {
            foreach ($batch as $p) {
                $result = tka_broadcast_send_email($p['email'], $p['name'], $job->subject, $job->message_content, $broadcast_id, $job->attachment_path, $job->attachment_url, $job->attachment_name, $job_from_name, $job_from_email, $job_reply_to, $job_logo_url);
                if ($result) {
                    $sent++;
                } else {
                    $failed++;
                    $failed_emails[] = "{$p['name']}, {$p['email']}";
                }
            }
            usleep(250000); // 0.25s pause between batches
        }
        $wpdb->update($history_table, ['total_sent' => $sent, 'total_failed' => $failed, 'failed_emails' => json_encode($failed_emails)], ['id' => $broadcast_id]);
    }

    $wpdb->update($queue_table, ['status' => 'done'], ['id' => $job->id]);
    return true;
}

// Register cron schedule
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['tka_every2min'])) {
        $schedules['tka_every2min'] = ['interval' => 120, 'display' => 'Every 2 Minutes'];
    }
    return $schedules;
});
if (!wp_next_scheduled('tka_process_broadcast_queue')) {
    wp_schedule_event(time(), 'tka_every2min', 'tka_process_broadcast_queue');
}

// ================================================================
// 5. CORE SEND HELPERS & SENDER PROFILES
// ================================================================
global $tka_active_from_email, $tka_active_from_name, $tka_active_reply_to;
$tka_active_from_email = '';
$tka_active_from_name  = '';
$tka_active_reply_to   = '';

function tka_get_sender_profiles() {
    $saved = get_option('tka_sender_profiles', []);
    if (!is_array($saved)) $saved = [];

    $elevate_email = get_option('tka_broadcast_from_email', 'broadcast@elevate.tkamedia.com');

    $defaults = [
        'main_church' => [
            'key'        => 'main_church',
            'label'      => "The King's Assembly (Direct support@thekingsassembly.org)",
            'short_name' => "TKA Community (Direct)",
            'from_name'  => 'TKA Community',
            'from_email' => 'support@thekingsassembly.org',
            'reply_to'   => 'support@thekingsassembly.org',
            'logo_url'   => 'https://thekingsassembly.org/wp-content/uploads/2023/07/tka-logo.png',
            'badge'      => 'Verified Sender',
        ],
        'main_church_relay' => [
            'key'        => 'main_church_relay',
            'label'      => "TKA Community (Elevate Relay)",
            'short_name' => "TKA Community (Relay)",
            'from_name'  => 'TKA Community',
            'from_email' => $elevate_email,
            'reply_to'   => 'support@thekingsassembly.org',
            'logo_url'   => 'https://thekingsassembly.org/wp-content/uploads/2023/07/tka-logo.png',
            'badge'      => 'Relay Fallback',
        ],
        'elevate' => [
            'key'        => 'elevate',
            'label'      => 'Elevate Church (Youth)',
            'short_name' => 'Elevate TKA',
            'from_name'  => get_option('tka_broadcast_from_name', 'Elevate TKA'),
            'from_email' => $elevate_email,
            'reply_to'   => get_option('tka_broadcast_reply_to', 'admin@example.com'),
            'logo_url'   => get_option('tka_broadcast_logo_url', 'https://elevate.tkamedia.com/wp-content/uploads/2026/01/ELEVATE-LOGO-1.png'),
            'badge'      => 'Youth Church Default',
        ]
    ];

    return array_replace_recursive($defaults, $saved);
}

function tka_broadcast_send_email(
    $to, 
    $name, 
    $subject, 
    $raw_content, 
    $broadcast_id = 0, 
    $attachment_path = '', 
    $attachment_url = '', 
    $attachment_name = 'File',
    $override_from_name = '',
    $override_from_email = '',
    $override_reply_to = '',
    $override_logo_url = ''
) {
    global $tka_active_from_email, $tka_active_from_name, $tka_active_reply_to;

    $church_name = !empty($override_from_name)  ? trim($override_from_name)  : get_option('tka_broadcast_from_name', 'Elevate TKA');
    $from_email  = !empty($override_from_email) ? trim($override_from_email) : get_option('tka_broadcast_from_email', 'broadcast@elevate.tkamedia.com');
    $reply_to    = !empty($override_reply_to)   ? trim($override_reply_to)   : get_option('tka_broadcast_reply_to', 'admin@example.com');
    $logo_url    = !empty($override_logo_url)   ? trim($override_logo_url)   : get_option('tka_broadcast_logo_url', 'https://elevate.tkamedia.com/wp-content/uploads/2026/01/ELEVATE-LOGO-1.png');
    $year        = date('Y');

    // Store active sender identity in globals for PHPMailer & wp_mail filters
    $tka_active_from_email = $from_email;
    $tka_active_from_name  = $church_name;
    $tka_active_reply_to   = $reply_to;

    if (!is_email($to)) return false;
    if (empty(trim($name))) $name = 'Friend';
    $first_name = strtok(trim($name), ' ');

    $encoded_email = base64_encode($to);
    $site_url      = home_url('/');

    // Personalize tags
    $content = str_ireplace(['[name]', '[Name]', '[NAME]'], esc_html($name), $raw_content);
    $content = str_ireplace(['[first_name]', '[First_Name]'], esc_html($first_name), $content);
    $content = str_ireplace(['[email]', '[Email]'], esc_html($to), $content);

    // Personalize tags in subject
    $subject = str_ireplace(['[name]', '[Name]', '[NAME]'], $name, $subject);
    $subject = str_ireplace(['[first_name]', '[First_Name]'], $first_name, $subject);
    $subject = str_ireplace(['[email]', '[Email]'], $to, $subject);

    // Click tracking — wrap <a href> links
    if ($broadcast_id) {
        $content = preg_replace_callback('/<a([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i', function($m) use ($broadcast_id, $encoded_email, $site_url) {
            $original_url = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (strpos($original_url, 'tka_track') !== false || strpos($original_url, 'mailto:') === 0 || strpos($original_url, 'tel:') === 0 || strpos($original_url, '#') === 0) {
                return $m[0];
            }
            $tracking_url = $site_url . '?tka_track=click&bid=' . $broadcast_id . '&em=' . $encoded_email . '&url=' . base64_encode($original_url);
            return '<a' . $m[1] . 'href="' . esc_url($tracking_url) . '"' . $m[3] . '>';
        }, $content);
    }

    // Attachment link section
    if (!empty($attachment_url)) {
        $content .= '<div style="margin-top:30px;padding:20px;background-color:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;text-align:center;">
            <p style="margin:0 0 15px 0;font-size:14px;color:#475569;">📎 <strong>Attached File:</strong> ' . esc_html($attachment_name) . '</p>
            <a href="' . esc_url($attachment_url) . '" target="_blank" style="background-color:#E61E26;color:#ffffff;padding:14px 28px;text-decoration:none;border-radius:8px;font-size:14px;font-weight:bold;display:inline-block;">View / Download</a>
        </div>';
    }

    // Open tracking pixel
    $open_pixel = '';
    if ($broadcast_id) {
        $pixel_url  = $site_url . '?tka_track=open&bid=' . $broadcast_id . '&em=' . $encoded_email;
        $open_pixel = '<img src="' . esc_url($pixel_url) . '" width="1" height="1" style="display:block;border:0;" alt="">';
    }

    // Header branding: image logo or branded typography fallback
    $header_brand = !empty($logo_url)
        ? "<img src='" . esc_url($logo_url) . "' alt='" . esc_attr($church_name) . "'>"
        : "<h2 style='color:#ffffff;margin:0;font-size:22px;letter-spacing:1px;font-weight:800;'>" . esc_html($church_name) . "</h2>";

    $footer_logo = !empty($logo_url) ? "<img src='" . esc_url($logo_url) . "' alt='Logo'>" : "";

    $message = "<!DOCTYPE html><html><head><style>
        body{margin:0;padding:0;background-color:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;}
        .wrapper{width:100%;padding:40px 0;}
        .main-table{background-color:#ffffff;margin:0 auto;width:100%;max-width:600px;border-spacing:0;color:#334155;border-radius:12px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.05);}
        .header{background-color:#000;padding:30px;text-align:center;}
        .header img{max-height:50px;width:auto;display:block;margin:0 auto;}
        .content{line-height:1.7;font-size:16px;color:#475569;padding:0;}
        .footer{background-color:#000;padding:30px;text-align:center;font-size:13px;color:#ffffff;}
        .footer img{max-height:30px;width:auto;opacity:0.9;margin-bottom:10px;display:block;margin-left:auto;margin-right:auto;}
        .footer a{color:#ffffff;text-decoration:underline;opacity:0.8;}
    </style></head><body>
    <center class='wrapper'>
        <table class='main-table'>
            <tr><td class='header'>" . $header_brand . "</td></tr>
            <tr><td class='content'>" . $content . "</td></tr>
            <tr><td class='footer'>
                " . $footer_logo . "
                &copy; $year $church_name. All rights reserved.<br>
                <span style='opacity:0.7;font-size:11px;margin-top:10px;display:block;'>This is an automated broadcast.</span>
                $open_pixel
            </td></tr>
        </table>
    </center>
    </body></html>";

    $attachments = [];
    if (!empty($attachment_path) && file_exists($attachment_path)) $attachments[] = $attachment_path;

    // Engine selection: Direct Amazon SES v2 REST API vs SMTP
    $engine = get_option('tka_email_engine', 'smtp');
    if ($engine === 'ses_api') {
        $api_res = tka_send_ses_api_email($to, $subject, $message, $from_email, $church_name, $reply_to);
        if (!$api_res['success']) {
            global $tka_last_engine_error, $tka_last_send_error;
            $tka_last_engine_error = $api_res['error'];
            $tka_last_send_error   = $api_res['error'];
            return false;
        }
        return true;
    }

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        "From: $church_name <$from_email>",
        "Reply-To: $church_name <$reply_to>",
    ];

    // Configure PHPMailer once if SMTP is enabled
    $smtp_host = get_option('tka_smtp_host', 'email-smtp.eu-north-1.amazonaws.com');
    if (!empty($smtp_host) && !has_action('phpmailer_init', 'tka_configure_phpmailer')) {
        add_action('phpmailer_init', 'tka_configure_phpmailer');
    }

    global $tka_last_send_error;
    $tka_last_send_error = '';

    $mail_catcher = function($wp_error) {
        global $tka_last_send_error;
        if (is_wp_error($wp_error)) {
            $msg = $wp_error->get_error_message();
            $err_data = $wp_error->get_error_data();
            if (!empty($err_data) && is_array($err_data) && !empty($err_data['errors'])) {
                $sub = implode('; ', (array)$err_data['errors']);
                if ($sub !== $msg) $msg .= ' (' . $sub . ')';
            }
            $tka_last_send_error = $msg;
        }
    };
    add_action('wp_mail_failed', $mail_catcher);

    $sent = wp_mail($to, $subject, $message, $headers, $attachments);

    remove_action('wp_mail_failed', $mail_catcher);

    if (!$sent && empty($tka_last_send_error)) {
        global $phpmailer;
        if (!empty($phpmailer->ErrorInfo)) {
            $tka_last_send_error = $phpmailer->ErrorInfo;
        }
    }

    return $sent;
}

/**
 * Direct Amazon SES v2 REST API (Hyper-Speed HTTPS sending via AWS SigV4)
 */
function tka_send_ses_api_email($to, $subject, $html_body, $from_email, $from_name = 'Elevate TKA', $reply_to = '') {
    $region     = get_option('tka_ses_region', 'eu-north-1');
    $access_key = get_option('tka_ses_access_key', get_option('tka_smtp_user', ''));
    $secret_key = get_option('tka_ses_secret_key', '');

    if (empty($access_key) || empty($secret_key)) {
        return ['success' => false, 'error' => 'AWS Access Key or Secret Key missing in Settings.'];
    }

    $endpoint = "https://email.{$region}.amazonaws.com/v2/email/outbound-emails";
    $host     = "email.{$region}.amazonaws.com";

    $from_header = !empty($from_name) ? "{$from_name} <{$from_email}>" : $from_email;

    $payload = [
        'FromEmailAddress' => $from_header,
        'Destination'      => [
            'ToAddresses' => [$to],
        ],
        'Content' => [
            'Simple' => [
                'Subject' => [
                    'Data'    => $subject,
                    'Charset' => 'UTF-8',
                ],
                'Body' => [
                    'Html' => [
                        'Data'    => $html_body,
                        'Charset' => 'UTF-8',
                    ],
                ],
            ],
        ],
    ];

    if (!empty($reply_to)) {
        $payload['ReplyToAddresses'] = [$reply_to];
    }

    $body = json_encode($payload);

    $now  = gmdate('Ymd\THis\Z');
    $date = gmdate('Ymd');

    $canonical_headers = "content-type:application/json\nhost:{$host}\nx-amz-date:{$now}\n";
    $signed_headers    = "content-type;host;x-amz-date";
    $payload_hash      = hash('sha256', $body);

    $canonical_req = "POST\n/v2/email/outbound-emails\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
    $algorithm     = "AWS4-HMAC-SHA256";
    $scope         = "{$date}/{$region}/ses/aws4_request";
    $str_to_sign   = "{$algorithm}\n{$now}\n{$scope}\n" . hash('sha256', $canonical_req);

    $kSecret  = "AWS4" . $secret_key;
    $kDate    = hash_hmac('sha256', $date, $kSecret, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 'ses', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $sig      = hash_hmac('sha256', $str_to_sign, $kSigning);

    $auth = "{$algorithm} Credential={$access_key}/{$scope}, SignedHeaders={$signed_headers}, Signature={$sig}";

    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Host'         => $host,
            'x-amz-date'   => $now,
            'Authorization'=> $auth,
        ],
        'body'    => $body,
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }

    $code     = wp_remote_retrieve_response_code($response);
    $res_body = wp_remote_retrieve_body($response);

    if ($code >= 200 && $code < 300) {
        return ['success' => true, 'response' => json_decode($res_body, true)];
    }

    $decoded = json_decode($res_body, true);
    $err_msg = $decoded['message'] ?? ($decoded['Message'] ?? "HTTP {$code}: " . substr($res_body, 0, 160));
    return ['success' => false, 'error' => $err_msg];
}

function tka_configure_phpmailer($phpmailer) {
    global $tka_active_from_email, $tka_active_from_name, $tka_active_reply_to;
    $host = get_option('tka_smtp_host', 'email-smtp.eu-north-1.amazonaws.com');
    if (empty($host)) return;
    $phpmailer->isSMTP();
    $phpmailer->Host       = $host;
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = intval(get_option('tka_smtp_port', 587));
    $phpmailer->Username   = get_option('tka_smtp_user', '');
    $phpmailer->Password   = get_option('tka_smtp_pass', '');
    $secure = get_option('tka_smtp_secure', 'tls');
    $phpmailer->SMTPSecure = !empty($secure) ? $secure : false;
    $phpmailer->Timeout    = 20;
    $phpmailer->CharSet    = 'UTF-8';
    $phpmailer->Encoding   = 'base64';
    $phpmailer->SMTPKeepAlive = true;

    $from_email = !empty($tka_active_from_email) ? $tka_active_from_email : get_option('tka_broadcast_from_email', 'broadcast@elevate.tkamedia.com');
    $from_name  = !empty($tka_active_from_name)  ? $tka_active_from_name  : get_option('tka_broadcast_from_name', 'Elevate TKA');
    $reply_to   = !empty($tka_active_reply_to)   ? $tka_active_reply_to   : get_option('tka_broadcast_reply_to', 'admin@example.com');

    // Envelope Return-Path vs Header From:
    // When sending via Amazon SES, the SMTP envelope (MAIL FROM) must use the verified sending domain.
    // If the display address is an external domain (e.g. support@thekingsassembly.org) that lacks
    // custom SES MAIL FROM MX records, setting $phpmailer->Sender to it causes SES to reject the connection.
    // Keeping $phpmailer->Sender as the verified domain (broadcast@elevate.tkamedia.com) satisfies the SES envelope check,
    // while setFrom() sets the display header so the recipient sees "TKA Community <support@thekingsassembly.org>".
    $envelope = get_option('tka_ses_envelope_email', get_option('tka_broadcast_from_email', 'broadcast@elevate.tkamedia.com'));
    if (empty($envelope)) $envelope = 'broadcast@elevate.tkamedia.com';

    $is_ses = (stripos($host, 'amazonaws.com') !== false);
    if ($is_ses) {
        $phpmailer->Sender = $envelope;
    } else if (!empty($from_email)) {
        $phpmailer->Sender = $from_email;
    }

    if (!empty($from_email)) {
        $phpmailer->setFrom($from_email, $from_name, false);
    }
    if (!empty($reply_to)) {
        $phpmailer->clearReplyTos();
        $phpmailer->addReplyTo($reply_to, $from_name);
    }
}

// Ensure WordPress core mail filters respect chosen sender profile
add_filter('wp_mail_from', function($email) {
    global $tka_active_from_email;
    return !empty($tka_active_from_email) ? $tka_active_from_email : $email;
}, 99);
add_filter('wp_mail_from_name', function($name) {
    global $tka_active_from_name;
    return !empty($tka_active_from_name) ? $tka_active_from_name : $name;
}, 99);

function tka_broadcast_send_sms($phone, $message, $name = 'Friend') {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (empty($phone)) return false;
    if (substr($phone, 0, 1) === '0') $phone = '234' . substr($phone, 1);
    if (substr($phone, 0, 3) !== '234') $phone = '234' . $phone;

    $first_name = strtok(trim($name), ' ');
    $personalized = str_ireplace(['[name]', '[Name]'], $name, $message);
    $personalized = str_ireplace(['[first_name]', '[First_Name]'], $first_name, $personalized);

    $provider = get_option('tka_sms_provider', 'smart_sms');

    if ($provider === 'smart_sms') {
        $token     = get_option('tka_smart_sms_token', '2WvMjOqEksHfWIBFoG6AoxjbT2LpPpoQ14pVsdT00CrY0KWLzb');
        $sender_id = get_option('tka_smart_sms_sender', 'Elevate TKA');

        $response = wp_remote_post('https://smartsmssolutions.com/api/json.php', [
            'body'    => [
                'token'   => $token,
                'sender'  => $sender_id,
                'to'      => $phone,
                'message' => $personalized,
                'type'    => 0,
                'routing' => 3, // Corporate DND-Bypass Route
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return false;
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        return (isset($decoded['code']) && intval($decoded['code']) === 1000);

    } elseif ($provider === 'termii') {
        $api_key   = get_option('tka_termii_api_key', '');
        $sender_id = get_option('tka_termii_sender_id', 'Elevate TKA');

        $response = wp_remote_post('https://api.ng.termii.com/api/sms/send', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'to'      => $phone,
                'from'    => $sender_id,
                'sms'     => $personalized,
                'type'    => 'plain',
                'channel' => 'generic',
                'api_key' => $api_key,
            ]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return false;
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        return (isset($decoded['code']) && strtolower($decoded['code']) === 'ok') || isset($decoded['message_id']);

    } else {
        // HollaTags
        $username  = get_option('tka_sms_username', 'admin');
        $password  = get_option('tka_sms_password', '');
        $sender_id = get_option('tka_sms_sender', 'Elevate TKA');

        $response = wp_remote_post('https://sms.hollatags.com/api/send/', [
            'body'    => ['user' => $username, 'pass' => $password, 'from' => $sender_id, 'to' => $phone, 'msg' => $personalized],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return false;
        $body = wp_remote_retrieve_body($response);
        return (strpos(strtoupper($body), 'OK') !== false || strpos($body, '200') !== false || wp_remote_retrieve_response_code($response) === 200);
    }
}

// ================================================================
// 6. AJAX: SEND EMAIL BATCH
// ================================================================
add_action('wp_ajax_tka_send_broadcast_batch', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $subject         = sanitize_text_field(stripslashes($_POST['subject'] ?? ''));
    $content         = wp_kses_post(stripslashes($_POST['message'] ?? ''));
    $batch           = isset($_POST['batch']) ? $_POST['batch'] : [];
    $broadcast_id    = intval($_POST['broadcast_id'] ?? 0);
    $attachment_path = sanitize_text_field($_POST['attachment_path'] ?? '');
    $attachment_url  = esc_url_raw($_POST['attachment_url'] ?? '');
    $attachment_name = sanitize_text_field($_POST['attachment_name'] ?? 'Download File');
    $from_name       = sanitize_text_field(stripslashes($_POST['from_name'] ?? ''));
    $from_email      = sanitize_email($_POST['from_email'] ?? '');
    $reply_to        = sanitize_email($_POST['reply_to'] ?? '');
    $logo_url        = esc_url_raw($_POST['logo_url'] ?? '');

    if (empty($batch)) {
        wp_send_json_error('No recipients in this batch.');
    }

    $results = [];
    try {
        foreach ($batch as $person) {
            $to   = sanitize_email($person['email'] ?? '');
            $name = sanitize_text_field($person['name'] ?? 'Friend');
            if (empty($to)) continue;

            $sent = tka_broadcast_send_email($to, $name, $subject, $content, $broadcast_id, $attachment_path, $attachment_url, $attachment_name, $from_name, $from_email, $reply_to, $logo_url);
            if ($sent) {
                $results[] = ['email' => $to, 'name' => $name, 'status' => 'success'];
            } else {
                global $phpmailer, $tka_last_send_error, $tka_last_engine_error;
                $raw_err = !empty($tka_last_send_error) ? $tka_last_send_error : (!empty($tka_last_engine_error) ? $tka_last_engine_error : (!empty($phpmailer->ErrorInfo) ? $phpmailer->ErrorInfo : 'Server rejected'));
                $err = $raw_err;
                if (stripos($raw_err, 'Email address is not verified') !== false || stripos($raw_err, 'MessageRejected') !== false) {
                    $ses_region = get_option('tka_ses_region', 'eu-north-1');
                    $err = "Amazon SES rejected sender '$from_email': Address is not verified in region $ses_region (Stockholm). In AWS Console, check that region is Europe (Stockholm) eu-north-1 and status is Verified.";
                } elseif (stripos($raw_err, 'AccessDenied') !== false || stripos($raw_err, 'not authorized') !== false) {
                    $err = "AWS IAM AccessDenied: User AKIA... is not authorized to send from '$from_email'. In AWS IAM, allow ses:SendRawEmail on * or identity/$from_email.";
                }
                $results[] = ['email' => $to, 'name' => $name, 'status' => 'error', 'msg' => $err];
            }
            // 70ms pause to comply with Amazon SES 14 emails/sec rate limit
            usleep(70000);
        }
    } catch (\Throwable $e) {
        wp_send_json_error('Batch Exception: ' . $e->getMessage());
    } finally {
        global $phpmailer;
        if ($phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer) {
            $phpmailer->smtpClose();
        }
    }

    wp_send_json_success($results);
});

// ================================================================
// 7. AJAX: SEND SMS BATCH
// ================================================================
add_action('wp_ajax_tka_send_sms_batch', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    set_time_limit(120);

    $message      = sanitize_textarea_field(stripslashes($_POST['message'] ?? ''));
    $batch        = isset($_POST['batch']) ? $_POST['batch'] : [];
    $broadcast_id = intval($_POST['broadcast_id'] ?? 0);

    if (empty($batch)) wp_send_json_error('No recipients in batch.');

    $results = [];
    foreach ($batch as $person) {
        $phone = sanitize_text_field($person['phone'] ?? '');
        $name  = sanitize_text_field($person['name'] ?? 'Friend');
        $sent  = tka_broadcast_send_sms($phone, $message, $name);
        if ($sent) $results[] = ['phone' => $phone, 'name' => $name, 'status' => 'success'];
        else        $results[] = ['phone' => $phone, 'name' => $name, 'status' => 'error', 'msg' => 'SMS failed'];
    }
    wp_send_json_success($results);
});

// ================================================================
// 8. AJAX: CREATE BROADCAST RECORD (get ID before sending)
// ================================================================
add_action('wp_ajax_tka_create_broadcast_record', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $table = $wpdb->prefix . 'church_broadcast_history';
    $wpdb->insert($table, [
        'broadcast_type' => sanitize_text_field($_POST['broadcast_type'] ?? 'email'),
        'subject'        => sanitize_text_field($_POST['subject']),
        'message_content'=> wp_kses_post(stripslashes($_POST['message'] ?? '')),
        'audience_label' => sanitize_text_field($_POST['audience']),
        'from_name'      => sanitize_text_field(stripslashes($_POST['from_name'] ?? '')),
        'from_email'     => sanitize_email($_POST['from_email'] ?? ''),
        'date_sent'      => current_time('mysql'),
        'total_sent'     => 0,
        'total_failed'   => 0,
        'total_opens'    => 0,
        'total_clicks'   => 0,
        'status'         => 'sending',
    ]);
    wp_send_json_success(['broadcast_id' => $wpdb->insert_id]);
});

// ================================================================
// 9. AJAX: UPDATE BROADCAST RECORD AFTER SEND
// ================================================================
add_action('wp_ajax_tka_save_broadcast_history', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $table         = $wpdb->prefix . 'church_broadcast_history';
    $broadcast_id  = intval($_POST['broadcast_id'] ?? 0);
    $failed_emails = isset($_POST['failed_emails']) ? (array)$_POST['failed_emails'] : [];

    if ($broadcast_id) {
        $wpdb->update($table, [
            'total_sent'   => intval($_POST['sent_count']),
            'total_failed' => intval($_POST['error_count']),
            'failed_emails'=> json_encode(array_map('sanitize_text_field', $failed_emails)),
            'status'       => 'sent',
        ], ['id' => $broadcast_id]);
    } else {
        $wpdb->insert($table, [
            'broadcast_type' => sanitize_text_field($_POST['broadcast_type'] ?? 'email'),
            'subject'        => sanitize_text_field($_POST['subject']),
            'message_content'=> wp_kses_post(stripslashes($_POST['message'] ?? '')),
            'audience_label' => sanitize_text_field($_POST['audience']),
            'date_sent'      => current_time('mysql'),
            'total_sent'     => intval($_POST['sent_count']),
            'total_failed'   => intval($_POST['error_count']),
            'failed_emails'  => json_encode(array_map('sanitize_text_field', $failed_emails)),
            'status'         => 'sent',
        ]);
    }
    wp_send_json_success();
});

// ================================================================
// 10. AJAX: SCHEDULE BROADCAST
// ================================================================
add_action('wp_ajax_tka_schedule_broadcast', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $table      = $wpdb->prefix . 'church_broadcast_queue';
    $recipients = isset($_POST['recipients']) ? $_POST['recipients'] : [];

    if (empty($recipients)) wp_send_json_error('No recipients.');

    $scheduled_at = sanitize_text_field($_POST['scheduled_at']);
    // Convert from local to MySQL datetime
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduled_at, new DateTimeZone(wp_timezone_string()));
    if (!$dt) wp_send_json_error('Invalid date format.');
    $mysql_dt = $dt->format('Y-m-d H:i:s');

    $wpdb->insert($table, [
        'broadcast_type'  => sanitize_text_field($_POST['broadcast_type'] ?? 'email'),
        'subject'         => sanitize_text_field($_POST['subject']),
        'message_content' => wp_kses_post(stripslashes($_POST['message'] ?? '')),
        'audience_label'  => sanitize_text_field($_POST['audience']),
        'from_name'       => sanitize_text_field(stripslashes($_POST['from_name'] ?? '')),
        'from_email'      => sanitize_email($_POST['from_email'] ?? ''),
        'reply_to'        => sanitize_email($_POST['reply_to'] ?? ''),
        'logo_url'        => esc_url_raw($_POST['logo_url'] ?? ''),
        'recipients'      => json_encode($recipients),
        'scheduled_at'    => $mysql_dt,
        'attachment_path' => sanitize_text_field($_POST['attachment_path'] ?? ''),
        'attachment_url'  => esc_url_raw($_POST['attachment_url'] ?? ''),
        'attachment_name' => sanitize_text_field($_POST['attachment_name'] ?? ''),
        'status'          => 'pending',
    ]);
    wp_send_json_success(['queue_id' => $wpdb->insert_id, 'scheduled_at' => $mysql_dt]);
});

// ================================================================
// 11. AJAX: GET SCHEDULED QUEUE
// ================================================================
add_action('wp_ajax_tka_get_schedule_queue', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $table = $wpdb->prefix . 'church_broadcast_queue';
    $jobs  = $wpdb->get_results("SELECT id, broadcast_type, subject, audience_label, scheduled_at, status, created_at FROM $table ORDER BY scheduled_at DESC LIMIT 50");
    wp_send_json_success($jobs);
});

// ================================================================
// 12. AJAX: CANCEL & RUN SCHEDULED BROADCAST
// ================================================================
add_action('wp_ajax_tka_cancel_scheduled', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $table = $wpdb->prefix . 'church_broadcast_queue';
    $id    = intval($_POST['queue_id']);
    $wpdb->update($table, ['status' => 'cancelled'], ['id' => $id, 'status' => 'pending']);
    wp_send_json_success();
});

add_action('wp_ajax_tka_run_scheduled_now', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    $id  = intval($_POST['queue_id']);
    $res = tka_execute_queue_job($id);
    if ($res) wp_send_json_success();
    else wp_send_json_error('Failed to execute job or job already finished.');
});

// ================================================================
// 13. AJAX: ATTACHMENT UPLOAD
// ================================================================
add_action('wp_ajax_tka_upload_broadcast_attachment', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    if (empty($_FILES['attachment'])) wp_send_json_error('No file received.');
    $upload = wp_handle_upload($_FILES['attachment'], array('test_form' => false));
    if (isset($upload['file'])) {
        wp_send_json_success(['file_path' => $upload['file'], 'file_url' => $upload['url'], 'file_name' => basename($upload['file'])]);
    } else {
        wp_send_json_error($upload['error']);
    }
});

// ================================================================
// 14. AJAX: HISTORY ACTIONS
// ================================================================
add_action('wp_ajax_tka_delete_history', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $id = intval($_POST['history_id']);
    $wpdb->delete($wpdb->prefix . 'church_broadcast_history', ['id' => $id]);
    wp_send_json_success();
});

add_action('wp_ajax_tka_bulk_delete_history', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $ids = array_map('intval', (array)($_POST['history_ids'] ?? []));
    if (empty($ids)) wp_send_json_error();
    $in = implode(',', $ids);
    $wpdb->query("DELETE FROM {$wpdb->prefix}church_broadcast_history WHERE id IN ($in)");
    wp_send_json_success();
});

// ================================================================
// 15. AJAX: TEMPLATES
// ================================================================
add_action('wp_ajax_tka_save_template', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $name    = sanitize_text_field($_POST['template_name']);
    $content = wp_kses_post(stripslashes($_POST['content']));
    if (empty($name) || empty($content)) wp_send_json_error();
    $wpdb->insert($wpdb->prefix . 'church_broadcast_templates', ['template_name' => $name, 'template_content' => $content, 'date_created' => current_time('mysql')]);
    wp_send_json_success();
});

add_action('wp_ajax_tka_delete_template', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'church_broadcast_templates', ['id' => intval($_POST['template_id'])]);
    wp_send_json_success();
});

// ================================================================
// 16. AJAX: ADDRESS BOOK
// ================================================================
add_action('wp_ajax_tka_bulk_import_contacts', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    global $wpdb;

    $table      = $wpdb->prefix . 'church_broadcast_contacts';
    $group_name = sanitize_text_field($_POST['group_name'] ?? 'General');
    $raw_text   = stripslashes($_POST['csv_data'] ?? '');
    if (empty($group_name)) $group_name = 'General';

    $lines = explode("\n", $raw_text);
    $added = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Auto-detect separator: tab, comma, semicolon, or pipe
        $sep = ",";
        if (strpos($line, "\t") !== false) $sep = "\t";
        elseif (strpos($line, ";") !== false) $sep = ";";
        elseif (strpos($line, "|") !== false) $sep = "|";

        $parts = array_values(array_filter(array_map('trim', explode($sep, $line)), function($v) { return $v !== ''; }));
        if (empty($parts)) continue;

        $email = '';
        $phone = '';
        $unmatched = [];

        // Helper to test phone number
        $is_num_phone = function($str) {
            $digits = preg_replace('/[^0-9]/', '', $str);
            return (strlen($digits) >= 7 && strlen($digits) <= 16);
        };

        // Classify each part
        foreach ($parts as $p) {
            if (empty($email) && is_email($p)) {
                $email = sanitize_email($p);
            } elseif (empty($phone) && $is_num_phone($p)) {
                $phone = sanitize_text_field($p);
            } else {
                $unmatched[] = $p;
            }
        }

        $name = !empty($unmatched) ? sanitize_text_field(implode(' ', $unmatched)) : '';

        // If no name, assign smart fallback
        if (empty($name)) {
            if (!empty($phone)) $name = 'Member (' . substr(preg_replace('/[^0-9]/', '', $phone), -4) . ')';
            elseif (!empty($email)) $name = strtok($email, '@');
            else $name = 'Friend';
        }

        // Valid if has either phone or email
        if (!empty($email) || !empty($phone)) {
            $wpdb->insert($table, [
                'full_name'  => $name,
                'email'      => $email,
                'phone'      => $phone,
                'group_name' => $group_name,
                'date_added' => current_time('mysql'),
            ]);
            $added++;
        }
    }
    wp_send_json_success("$added contacts successfully saved into group '$group_name'.");
});

add_action('wp_ajax_tka_sync_members_to_address_book', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    global $wpdb;

    $members_table  = $wpdb->prefix . 'church_members';
    $contacts_table = $wpdb->prefix . 'church_broadcast_contacts';
    $target_group   = sanitize_text_field($_POST['group_name'] ?? 'Church Members');
    if (empty($target_group)) $target_group = 'Church Members';

    if ($wpdb->get_var("SHOW TABLES LIKE '$members_table'") != $members_table) {
        wp_send_json_error('Church Members table does not exist.');
    }

    $members = $wpdb->get_results("SELECT full_name, email, phone FROM $members_table WHERE (phone != '' AND phone IS NOT NULL) OR (email != '' AND email IS NOT NULL)");
    if (empty($members)) {
        wp_send_json_error('No members with phone or email found in database.');
    }

    // Pre-fetch existing contacts in this group to avoid duplicates
    $existing_phones = $wpdb->get_col($wpdb->prepare("SELECT phone FROM $contacts_table WHERE group_name = %s AND phone != ''", $target_group));
    $existing_emails = $wpdb->get_col($wpdb->prepare("SELECT email FROM $contacts_table WHERE group_name = %s AND email != ''", $target_group));
    $phone_set = array_flip($existing_phones);
    $email_set = array_flip(array_map('strtolower', $existing_emails));

    $added = 0;
    foreach ($members as $m) {
        $name  = !empty(trim($m->full_name)) ? sanitize_text_field($m->full_name) : 'Friend';
        $email = sanitize_email($m->email ?? '');
        $phone = sanitize_text_field($m->phone ?? '');

        // Check if already in this group
        if (!empty($phone) && isset($phone_set[$phone])) continue;
        if (!empty($email) && isset($email_set[strtolower($email)])) continue;

        $wpdb->insert($contacts_table, [
            'full_name'  => $name,
            'email'      => $email,
            'phone'      => $phone,
            'group_name' => $target_group,
            'date_added' => current_time('mysql'),
        ]);

        if (!empty($phone)) $phone_set[$phone] = true;
        if (!empty($email)) $email_set[strtolower($email)] = true;
        $added++;
    }

    wp_send_json_success("Successfully synced $added members into Address Book group '$target_group'!");
});

add_action('wp_ajax_tka_update_contact', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $id    = intval($_POST['contact_id']);
    $name  = sanitize_text_field($_POST['name']);
    $email = sanitize_email($_POST['email']);
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $wpdb->update($wpdb->prefix . 'church_broadcast_contacts', ['full_name' => $name, 'email' => $email, 'phone' => $phone], ['id' => $id]);
    wp_send_json_success();
});

add_action('wp_ajax_tka_add_single_contact', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    global $wpdb;
    $table = $wpdb->prefix . 'church_broadcast_contacts';
    $name  = sanitize_text_field($_POST['name'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $group = sanitize_text_field($_POST['group_name'] ?? 'General');

    if (empty($email) && empty($phone)) {
        wp_send_json_error('Please enter at least an Email address or a Phone number.');
    }
    if (empty($name)) {
        if (!empty($phone)) $name = 'Member (' . substr(preg_replace('/[^0-9]/', '', $phone), -4) . ')';
        elseif (!empty($email)) $name = strtok($email, '@');
        else $name = 'Friend';
    }
    if (empty($group)) $group = 'General';

    $wpdb->insert($table, [
        'full_name'  => $name,
        'email'      => $email,
        'phone'      => $phone,
        'group_name' => $group,
        'date_added' => current_time('mysql'),
    ]);
    wp_send_json_success(['id' => $wpdb->insert_id]);
});

add_action('wp_ajax_tka_bulk_delete_contacts', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $ids = array_map('intval', (array)($_POST['contact_ids'] ?? []));
    if (empty($ids)) wp_send_json_error('No contacts selected.');
    $in = implode(',', $ids);
    $wpdb->query("DELETE FROM {$wpdb->prefix}church_broadcast_contacts WHERE id IN ($in)");
    wp_send_json_success();
});

add_action('wp_ajax_tka_bulk_move_contacts', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $ids   = array_map('intval', (array)($_POST['contact_ids'] ?? []));
    $group = sanitize_text_field($_POST['target_group'] ?? '');
    if (empty($ids) || empty($group)) wp_send_json_error('Missing data.');
    $in = implode(',', $ids);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}church_broadcast_contacts SET group_name = %s WHERE id IN ($in)", $group));
    wp_send_json_success();
});

add_action('wp_ajax_tka_delete_contact', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'church_broadcast_contacts', ['id' => intval($_POST['contact_id'])]);
    wp_send_json_success();
});

add_action('wp_ajax_tka_rename_group', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $old = sanitize_text_field($_POST['old_name']);
    $new = sanitize_text_field($_POST['new_name']);
    $wpdb->update($wpdb->prefix . 'church_broadcast_contacts', ['group_name' => $new], ['group_name' => $old]);
    wp_send_json_success();
});

add_action('wp_ajax_tka_delete_group', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'church_broadcast_contacts', ['group_name' => sanitize_text_field($_POST['group_name'])]);
    wp_send_json_success();
});

// ================================================================
// 17. AJAX: TRACKING STATS FOR A BROADCAST
// ================================================================
add_action('wp_ajax_tka_get_tracking_detail', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $bid   = intval($_POST['broadcast_id']);
    $table = $wpdb->prefix . 'church_broadcast_tracking';
    $opens  = $wpdb->get_results($wpdb->prepare("SELECT recipient_email, event_time FROM $table WHERE broadcast_id=%d AND event_type='open' ORDER BY event_time DESC LIMIT 100", $bid));
    $clicks = $wpdb->get_results($wpdb->prepare("SELECT recipient_email, url, event_time FROM $table WHERE broadcast_id=%d AND event_type='click' ORDER BY event_time DESC LIMIT 100", $bid));
    wp_send_json_success(['opens' => $opens, 'clicks' => $clicks]);
});

// ================================================================
// 18. AJAX: SAVE SETTINGS & TEST SMTP / SMS
// ================================================================
add_action('wp_ajax_tka_save_broadcast_settings', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    $fields = [
        'tka_broadcast_from_name', 'tka_broadcast_from_email', 'tka_broadcast_reply_to', 'tka_broadcast_logo_url',
        'tka_active_sender_profile',
        'tka_email_engine', 'tka_ses_region', 'tka_ses_access_key', 'tka_ses_secret_key',
        'tka_smtp_host', 'tka_smtp_port', 'tka_smtp_user', 'tka_smtp_pass', 'tka_smtp_secure', 'tka_smtp_preset',
        'tka_sms_provider', 'tka_smart_sms_token', 'tka_smart_sms_sender', 'tka_termii_api_key', 'tka_termii_sender_id',
        'tka_sms_username', 'tka_sms_password', 'tka_sms_sender'
    ];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) update_option($f, sanitize_text_field($_POST[$f]));
    }
    if (isset($_POST['tka_sender_profiles'])) {
        $raw = stripslashes($_POST['tka_sender_profiles']);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            update_option('tka_sender_profiles', $decoded);
        }
    }
    if (isset($_POST['tka_smtp_profiles'])) {
        $raw = stripslashes($_POST['tka_smtp_profiles']);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $clean = [];
            foreach ($decoded as $pKey => $pData) {
                if (is_array($pData)) {
                    $clean[sanitize_key($pKey)] = [
                        'host'   => sanitize_text_field($pData['host'] ?? ''),
                        'port'   => intval($pData['port'] ?? 587),
                        'user'   => sanitize_text_field($pData['user'] ?? ''),
                        'pass'   => sanitize_text_field($pData['pass'] ?? ''),
                        'secure' => sanitize_text_field($pData['secure'] ?? 'tls'),
                    ];
                }
            }
            update_option('tka_smtp_profiles', $clean);
        }
    }
    if (!empty($_POST['tka_studio_pin'])) {
        update_option('tka_studio_pin', sanitize_text_field($_POST['tka_studio_pin']));
    }
    wp_send_json_success('Settings saved.');
});

add_action('wp_ajax_tka_test_smtp_connection', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $to = sanitize_email($_POST['test_email'] ?? '');
    if (!is_email($to)) wp_send_json_error('Please enter a valid email address.');

    $engine = sanitize_text_field($_POST['email_engine'] ?? get_option('tka_email_engine', 'smtp'));
    $from   = sanitize_email($_POST['from'] ?? get_option('tka_broadcast_from_email', 'broadcast@elevate.tkamedia.com'));
    $name   = sanitize_text_field($_POST['name'] ?? get_option('tka_broadcast_from_name', 'Elevate TKA'));
    $reply  = sanitize_email($_POST['reply_to'] ?? get_option('tka_broadcast_reply_to', 'admin@example.com'));

    if ($engine === 'ses_api') {
        $region     = sanitize_text_field($_POST['ses_region'] ?? get_option('tka_ses_region', 'eu-north-1'));
        $access_key = sanitize_text_field($_POST['ses_access_key'] ?? get_option('tka_ses_access_key', ''));
        $secret_key = sanitize_text_field($_POST['ses_secret_key'] ?? get_option('tka_ses_secret_key', ''));

        if (!empty($region)) update_option('tka_ses_region', $region);
        if (!empty($access_key)) update_option('tka_ses_access_key', $access_key);
        if (!empty($secret_key)) update_option('tka_ses_secret_key', $secret_key);

        $subject = "🧪 Amazon SES REST API v2 Test — " . $name;
        $message = "<div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;max-width:540px;'>
            <h3 style='color:#10b981;margin-top:0;'>⚡ Direct Amazon SES REST API (HTTPS) Connected!</h3>
            <p style='color:#334155;font-size:15px;line-height:1.6;'>Congratulations! Your church broadcast suite is now communicating directly with Amazon SES via <strong>HTTPS (AWS SigV4)</strong> without SMTP latency or port 587 hosting blocks.</p>
            <div style='background:#f8fafc;border-left:4px solid #10b981;padding:12px;margin:16px 0;font-size:13px;color:#475569;'>
                <strong>Engine:</strong> Direct Amazon SES REST API (v2)<br>
                <strong>Region:</strong> $region<br>
                <strong>From Identity:</strong> $from<br>
                <strong>Reply-To:</strong> $reply<br>
                <strong>Dispatched:</strong> " . current_time('mysql') . "
            </div>
            <p style='color:#64748b;font-size:12px;margin-bottom:0;'>Church Broadcast Pro — High-Performance Broadcast Suite</p>
        </div>";

        $res = tka_send_ses_api_email($to, $subject, $message, $from, $name, $reply);
        if ($res['success']) {
            wp_send_json_success("Amazon SES REST API test email delivered successfully to $to!");
        } else {
            $err = $res['error'] ?? 'Unknown SES error.';
            if (stripos($err, 'Email address is not verified') !== false || stripos($err, 'MessageRejected') !== false) {
                $sending_domain = substr(strrchr($from, "@"), 1);
                $ses_region = get_option('tka_ses_region', 'eu-north-1');
                $err .= "\n\n💡 SES Identity Requirement:\nTo send from $from, either the email address ($from) or the domain (@$sending_domain) must be verified in your Amazon SES console (Region: $ses_region).\n1. Go to AWS Console -> Amazon SES -> Verified identities.\n2. Click 'Create identity', select Email address (or Domain), enter '$from' and submit.\n3. Click the verification link in the confirmation email Amazon sends to $from.";
            }
            wp_send_json_error("Amazon SES API Error: " . $err);
        }
        return;
    }

    $host   = sanitize_text_field($_POST['host'] ?? get_option('tka_smtp_host', 'email-smtp.eu-north-1.amazonaws.com'));
    $port   = intval($_POST['port'] ?? get_option('tka_smtp_port', 587));
    $user   = sanitize_text_field($_POST['user'] ?? get_option('tka_smtp_user', ''));
    $pass   = sanitize_text_field($_POST['pass'] ?? get_option('tka_smtp_pass', ''));
    $secure = sanitize_text_field($_POST['secure'] ?? get_option('tka_smtp_secure', 'tls'));

    if (empty($host)) wp_send_json_error('Please enter an SMTP Host first.');

    add_action('phpmailer_init', function($pm) use ($host, $port, $user, $pass, $secure, $from, $name) {
        $pm->isSMTP();
        $pm->Host       = $host;
        $pm->SMTPAuth   = true;
        $pm->Port       = $port;
        $pm->Username   = $user;
        $pm->Password   = $pass;
        $pm->SMTPSecure = !empty($secure) ? $secure : false;
        $pm->Timeout    = 15;
        $pm->CharSet    = 'UTF-8';

        $is_ses = (stripos($host, 'amazonaws.com') !== false);
        $envelope = get_option('tka_ses_envelope_email', get_option('tka_broadcast_from_email', 'broadcast@elevate.tkamedia.com'));
        if (empty($envelope)) $envelope = 'broadcast@elevate.tkamedia.com';

        if ($is_ses) {
            $pm->Sender = $envelope;
        } else if (!empty($from)) {
            $pm->Sender = $from;
        }

        if (!empty($from)) {
            $pm->setFrom($from, $name, false);
        }
    });

    $is_ses = (stripos($host, 'amazonaws.com') !== false);
    $subject = ($is_ses ? "🧪 Amazon SES Test Email — " : "🧪 SMTP Test Email — ") . $name;
    $service_label = $is_ses ? "Amazon SES" : "SMTP Server";

    $message = "<div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;max-width:540px;'>
        <h3 style='color:#10b981;margin-top:0;'>✅ $service_label Delivery Successful!</h3>
        <p style='color:#334155;font-size:15px;line-height:1.6;'>Your server (<strong>$host:$port</strong>) is properly configured and successfully dispatching emails for <strong>$name</strong> ($from).</p>
        <div style='background:#f8fafc;border-left:4px solid #10b981;padding:12px;margin:16px 0;font-size:13px;color:#475569;'>
            <strong>Delivery Mode:</strong> $service_label<br>
            <strong>Host:</strong> $host<br>
            <strong>Port:</strong> $port ($secure)<br>
            <strong>Dispatched:</strong> " . current_time('mysql') . "
        </div>
        <p style='color:#64748b;font-size:12px;margin-bottom:0;'>Church Broadcast Pro — High-Performance Broadcast Suite</p>
    </div>";
    $headers = ['Content-Type: text/html; charset=UTF-8', "From: $name <$from>"];

    global $tka_test_send_error;
    $tka_test_send_error = '';
    $test_catcher = function($wp_error) {
        global $tka_test_send_error;
        if (is_wp_error($wp_error)) {
            $msg = $wp_error->get_error_message();
            $err_data = $wp_error->get_error_data();
            if (!empty($err_data) && is_array($err_data) && !empty($err_data['errors'])) {
                $sub = implode('; ', (array)$err_data['errors']);
                if ($sub !== $msg) $msg .= ' (' . $sub . ')';
            }
            $tka_test_send_error = $msg;
        }
    };
    add_action('wp_mail_failed', $test_catcher);

    $sent = wp_mail($to, $subject, $message, $headers);

    remove_action('wp_mail_failed', $test_catcher);

    if ($sent) {
        wp_send_json_success("Test email delivered successfully via $service_label to $to! Sender: $name <$from>");
    } else {
        global $phpmailer, $tka_test_send_error;
        $raw_err = !empty($tka_test_send_error) ? $tka_test_send_error : (!empty($phpmailer->ErrorInfo) ? $phpmailer->ErrorInfo : 'Server rejected connection.');
        $err = $raw_err;
        if (stripos($raw_err, 'Email address is not verified') !== false || stripos($raw_err, 'MessageRejected') !== false) {
            $sending_domain = substr(strrchr($from, "@"), 1);
            $ses_region = get_option('tka_ses_region', 'eu-north-1');
            $err .= "\n\n💡 SES Identity Requirement:\nTo send from $from, either the email address ($from) or the domain (@$sending_domain) must be verified in your Amazon SES console (Region: $ses_region).\n1. Go to AWS Console -> Amazon SES -> Verified identities.\n2. Ensure the top-right AWS region is 'Europe (Stockholm) eu-north-1'.\n3. Click 'Create identity', select Email address, enter '$from' and submit.\n4. Click the verification link in the confirmation email Amazon sends to $from.";
        } elseif (stripos($raw_err, 'AccessDenied') !== false || stripos($raw_err, 'not authorized') !== false) {
            $err .= "\n\n💡 AWS IAM Policy Notice:\nThe IAM User does not have permission to send from $from. In AWS IAM, ensure ses:SendRawEmail policy allows Resource * or identity/$from.";
        }
        wp_send_json_error("Connection Error: " . $err);
    }
});

// ================================================================
// 18B. AJAX: QUICK TEST SENDER PROFILE (FROM COMPOSER OR SETTINGS)
// ================================================================
add_action('wp_ajax_tka_quick_test_sender', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $to = sanitize_email($_POST['test_email'] ?? '');
    if (!is_email($to)) wp_send_json_error('Please enter a valid email address.');

    $from_name  = sanitize_text_field(stripslashes($_POST['from_name'] ?? 'TKA Community'));
    $from_email = sanitize_email($_POST['from_email'] ?? 'support@thekingsassembly.org');
    $reply_to   = sanitize_email($_POST['reply_to'] ?? $from_email);
    $logo_url   = esc_url_raw($_POST['logo_url'] ?? '');

    $subject = "🧪 Sender Profile Test — " . $from_name;
    $message = "<div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;max-width:560px;'>
        <h3 style='color:#16a34a;margin-top:0;'>✅ Sender Identity Verified & Working!</h3>
        <p style='color:#334155;font-size:15px;line-height:1.6;'>This test email confirms that your broadcast suite is successfully delivering emails with the following sender identity:</p>
        <div style='background:#f8fafc;border-left:4px solid #16a34a;padding:14px;margin:16px 0;font-size:13px;color:#334155;line-height:1.7;'>
            <strong>Display From:</strong> " . esc_html($from_name) . " &lt;" . esc_html($from_email) . "&gt;<br>
            <strong>Reply-To:</strong> " . esc_html($reply_to) . "<br>
            <strong>Engine:</strong> Amazon SES (eu-north-1)<br>
            <strong>Dispatched:</strong> " . current_time('mysql') . "
        </div>
        <p style='color:#64748b;font-size:12px;margin-bottom:0;'>Church Broadcast Pro — The King's Assembly</p>
    </div>";

    $sent = tka_broadcast_send_email($to, 'Admin', $subject, $message, 0, '', '', '', $from_name, $from_email, $reply_to, $logo_url);
    if ($sent) {
        wp_send_json_success("Test email delivered successfully to $to!\n\nVerified Sender Display in Inbox:\nFrom: $from_name <$from_email>\nReply-To: $reply_to");
    } else {
        global $tka_last_send_error, $tka_last_engine_error, $phpmailer;
        $raw_err = !empty($tka_last_send_error) ? $tka_last_send_error : (!empty($tka_last_engine_error) ? $tka_last_engine_error : (!empty($phpmailer->ErrorInfo) ? $phpmailer->ErrorInfo : 'Server rejected connection.'));
        $err = $raw_err;
        if (stripos($raw_err, 'Email address is not verified') !== false || stripos($raw_err, 'MessageRejected') !== false) {
            $ses_region = get_option('tka_ses_region', 'eu-north-1');
            $err = "Amazon SES rejected sender '$from_email': This address is not verified in AWS region $ses_region (Stockholm).\n\nAction Required in AWS Console:\n1. Log into AWS Console -> Amazon SES.\n2. In the top-right corner, check the Region dropdown and switch it to 'Europe (Stockholm) eu-north-1'.\n3. Go to 'Verified identities' -> Click 'Create identity'.\n4. Choose 'Email address', enter '$from_email' and click 'Create identity'.\n5. Open the inbox of '$from_email' and click the Amazon verification link.\n6. Once Verified, click 'Quick Test' again!";
        } elseif (stripos($raw_err, 'AccessDenied') !== false || stripos($raw_err, 'not authorized') !== false) {
            $err = "AWS IAM AccessDenied: IAM user is not authorized to send from '$from_email'. In AWS IAM, allow ses:SendRawEmail on * or identity/$from_email.";
        }
        wp_send_json_error($err);
    }
});

add_action('wp_ajax_tka_test_sms_connection', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $phone = sanitize_text_field($_POST['test_phone'] ?? '');
    if (empty($phone)) wp_send_json_error('Please enter a phone number.');

    if (isset($_POST['provider'])) update_option('tka_sms_provider', sanitize_text_field($_POST['provider']));
    if (isset($_POST['smart_sms_token'])) update_option('tka_smart_sms_token', sanitize_text_field($_POST['smart_sms_token']));
    if (isset($_POST['smart_sms_sender'])) update_option('tka_smart_sms_sender', sanitize_text_field($_POST['smart_sms_sender']));
    if (isset($_POST['termii_api_key'])) update_option('tka_termii_api_key', sanitize_text_field($_POST['termii_api_key']));
    if (isset($_POST['termii_sender_id'])) update_option('tka_termii_sender_id', sanitize_text_field($_POST['termii_sender_id']));
    if (isset($_POST['sms_user'])) update_option('tka_sms_username', sanitize_text_field($_POST['sms_user']));
    if (isset($_POST['sms_pass'])) update_option('tka_sms_password', sanitize_text_field($_POST['sms_pass']));
    if (isset($_POST['sms_sender'])) update_option('tka_sms_sender', sanitize_text_field($_POST['sms_sender']));

    $provider = get_option('tka_sms_provider', 'smart_sms');
    $provider_label = [
        'smart_sms' => 'Smart SMS Solutions (Corporate DND Bypass)',
        'termii'    => 'Termii SMS Gateway',
        'hollatags' => 'HollaTags SMS Gateway',
    ][$provider] ?? ucfirst($provider);

    $res = tka_broadcast_send_sms($phone, "Hello! This is a test SMS from Church Broadcast Pro via $provider_label.", "Admin");
    if ($res) {
        wp_send_json_success("Test SMS delivered successfully via $provider_label to $phone!");
    } else {
        wp_send_json_error("SMS delivery failed via $provider_label. Please verify your API token, credentials, and approved Sender ID.");
    }
});

// ================================================================
// 19. RENDER — BROADCAST STUDIO
// ================================================================
function render_church_broadcast_studio() {
    global $wpdb;

    $is_unlocked = isset($_COOKIE['tka_studio_unlocked']) && $_COOKIE['tka_studio_unlocked'] == '1';

    if (isset($_POST['tka_studio_pin'])) {
        $stored_pin = get_option('tka_studio_pin', '1234');
        if ($_POST['tka_studio_pin'] === $stored_pin) {
            echo "<script>document.cookie='tka_studio_unlocked=1;max-age=28800;path=/';window.location.href=window.location.href;</script>"; exit;
        } else { echo "<script>alert('Incorrect Security Code.');</script>"; }
    }

    if (!$is_unlocked) { ?>
        <div class="wrap" style="display:flex;justify-content:center;align-items:center;height:75vh;font-family:-apple-system,sans-serif;">
            <div style="background:white;padding:40px;border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,0.08);text-align:center;max-width:400px;width:100%;border:1px solid #e2e8f0;">
                <div style="width:60px;height:60px;background:#fee2e2;border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px;">🔒</div>
                <h2 style="margin-top:0;color:#0f172a;font-size:24px;font-weight:800;">Studio Locked</h2>
                <p style="color:#64748b;margin-bottom:25px;font-size:14px;">Enter the access PIN to continue.</p>
                <form method="POST">
                    <input type="password" name="tka_studio_pin" placeholder="••••" style="width:100%;padding:18px;margin-bottom:20px;text-align:center;font-size:28px;letter-spacing:10px;border-radius:12px;border:2px solid #e2e8f0;outline:none;box-sizing:border-box;" required autofocus>
                    <button type="submit" style="background:#E63946;color:white;border:none;padding:16px 20px;width:100%;border-radius:12px;cursor:pointer;font-weight:700;font-size:16px;">Unlock Studio</button>
                </form>
            </div>
        </div>
    <?php return; }

    // --- Load Data ---
    $members_table   = $wpdb->prefix . 'church_members';
    $checkins_table  = $wpdb->prefix . 'church_checkins';
    $history_table   = $wpdb->prefix . 'church_broadcast_history';
    $templates_table = $wpdb->prefix . 'church_broadcast_templates';
    $contacts_table  = $wpdb->prefix . 'church_broadcast_contacts';
    $queue_table     = $wpdb->prefix . 'church_broadcast_queue';

    // Get today's check-in member IDs and emails
    $today_wp = current_time('Y-m-d');
    $today_checkin_ids = [];
    $today_checkin_emails = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '$checkins_table'") == $checkins_table) {
        $today_checkin_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT member_id FROM $checkins_table WHERE DATE(checkin_date) = %s OR DATE(checkin_date) = CURDATE()",
            $today_wp
        ));
        if (!empty($today_checkin_ids)) {
            $ids_in = implode(',', array_map('intval', $today_checkin_ids));
            $found_emails = $wpdb->get_col("SELECT LOWER(TRIM(email)) FROM $members_table WHERE id IN ($ids_in) AND email != '' AND email IS NOT NULL");
            if (!empty($found_emails)) {
                $today_checkin_emails = array_map('strtolower', array_map('trim', $found_emails));
            }
        }
    }

    // Also check event manager check-ins if table exists
    $tem_checkins_table  = $wpdb->prefix . 'tem_checkins';
    $tem_attendees_table = $wpdb->prefix . 'tem_attendees';
    if ($wpdb->get_var("SHOW TABLES LIKE '$tem_checkins_table'") == $tem_checkins_table && $wpdb->get_var("SHOW TABLES LIKE '$tem_attendees_table'") == $tem_attendees_table) {
        $tem_today_emails = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT LOWER(TRIM(a.email)) FROM $tem_attendees_table a JOIN $tem_checkins_table c ON a.id = c.attendee_id WHERE (DATE(c.checkin_time) = %s OR DATE(c.checkin_time) = CURDATE()) AND a.email != '' AND a.email IS NOT NULL",
            $today_wp
        ));
        if (!empty($tem_today_emails)) {
            $today_checkin_emails = array_unique(array_merge($today_checkin_emails, array_map('strtolower', array_map('trim', $tem_today_emails))));
        }
    }

    $today_ids_map = array_flip(array_map('intval', $today_checkin_ids ?: []));
    $today_emails_map = array_flip(array_map('strtolower', array_map('trim', $today_checkin_emails ?: [])));

    // Build merged contact list (email)
    $raw_attendees   = $wpdb->get_var("SHOW TABLES LIKE '$members_table'") == $members_table
        ? $wpdb->get_results("SELECT id, full_name, email, phone, is_first_timer, is_member, time FROM $members_table WHERE email != '' AND email IS NOT NULL ORDER BY time DESC")
        : [];
    $unique_attendees = [];
    foreach ($raw_attendees as $a) {
        $clean = strtolower(trim($a->email));
        if (!isset($unique_attendees[$clean]) && is_email($clean)) {
            $ft_raw  = strtolower(trim($a->is_first_timer ?? ''));
            $is_ft   = in_array($ft_raw, ['yes', '1', 'true', 'y']) || (!empty($a->time) && substr($a->time, 0, 10) === $today_wp && !empty($a->is_first_timer) && $ft_raw !== 'no');
            $mem_raw = strtolower(trim($a->is_member ?? ''));
            $is_mem  = in_array($mem_raw, ['yes', '1', 'true', 'y']) || (!$is_ft && empty($a->is_first_timer));

            $is_checked_in = isset($today_ids_map[intval($a->id)]) || isset($today_emails_map[$clean]);

            $a->source_type      = empty($a->is_first_timer) ? 'imported' : 'web';
            $a->address_group    = '';
            $a->checked_in_today = $is_checked_in ? 'today' : '';
            $a->is_first_timer   = $is_ft ? 'Yes' : 'No';
            $a->is_member        = $is_mem ? 'Yes' : 'No';
            $unique_attendees[$clean] = $a;
        }
    }

    $raw_contacts = $wpdb->get_results("SELECT * FROM $contacts_table ORDER BY date_added DESC");
    $address_book_groups = [];
    $ab_group_counts = [];
    $ab_with_phone = 0;
    foreach ($raw_contacts as $c) {
        $grp = !empty($c->group_name) ? $c->group_name : 'General';
        $ab_group_counts[$grp] = ($ab_group_counts[$grp] ?? 0) + 1;
        if (!in_array($grp, $address_book_groups)) $address_book_groups[] = $grp;
        if (!empty($c->phone)) $ab_with_phone++;

        $clean = strtolower(trim($c->email));
        if (is_email($clean)) {
            if (isset($unique_attendees[$clean])) {
                // Member already exists from church_members - DO NOT overwrite member status!
                // Attach address_group so phone book filters still find them
                if (empty($unique_attendees[$clean]->address_group)) {
                    $unique_attendees[$clean]->address_group = $grp;
                }
            } else {
                // Contact ONLY exists in Address Book
                $c->source_type      = 'address_book';
                $c->is_first_timer   = 'No';
                $c->is_member        = 'No';
                $c->time             = $c->date_added;
                $c->address_group    = $grp;
                $c->checked_in_today = isset($today_emails_map[$clean]) ? 'today' : '';
                $unique_attendees[$clean] = $c;
            }
        }
    }
    sort($address_book_groups);
    $attendees = array_values($unique_attendees);
    usort($attendees, function($a, $b) { return strcmp($a->full_name, $b->full_name); });

    $ft_today_count     = 0;
    $ft_all_count       = 0;
    $today_all_count    = 0;
    $today_return_count = 0;
    $mem_count          = 0;
    foreach ($attendees as $att) {
        $is_ft    = (strcasecmp($att->is_first_timer ?? '', 'Yes') === 0);
        $att_date = (!empty($att->time) && strtotime($att->time) > 0) ? date('Y-m-d', strtotime($att->time)) : '';
        if ($is_ft) {
            $ft_all_count++;
            if ($att_date === $today_wp) {
                $ft_today_count++;
            }
        }
        if (!empty($att->checked_in_today)) {
            $today_all_count++;
            if (!$is_ft) {
                $today_return_count++;
            }
        }
        if (strcasecmp($att->is_member ?? '', 'Yes') === 0) $mem_count++;
    }

    $ab_contacts_json = json_encode(array_map(function($c) {
        return [
            'id'    => intval($c->id),
            'name'  => $c->full_name,
            'email' => $c->email,
            'phone' => !empty($c->phone) ? $c->phone : '',
            'group' => !empty($c->group_name) ? $c->group_name : 'General',
            'date'  => date('M d, Y', strtotime($c->date_added)),
        ];
    }, $raw_contacts));

    // Phone list for SMS (members DB + address book)
    $sms_members = $wpdb->get_var("SHOW TABLES LIKE '$members_table'") == $members_table
        ? $wpdb->get_results("SELECT full_name, phone FROM $members_table WHERE phone != '' AND phone IS NOT NULL ORDER BY full_name ASC")
        : [];
    $sms_contacts = $wpdb->get_results("SELECT full_name, phone, group_name FROM $contacts_table WHERE phone != '' AND phone IS NOT NULL ORDER BY full_name ASC");

    $group_phone_counts = [];
    foreach ($sms_contacts as $sc) {
        $g = !empty($sc->group_name) ? $sc->group_name : 'General';
        $group_phone_counts[$g] = ($group_phone_counts[$g] ?? 0) + 1;
    }

    // History & Stats
    $campaign_history = $wpdb->get_results("SELECT * FROM $history_table ORDER BY date_sent DESC LIMIT 500");
    $saved_templates  = $wpdb->get_results("SELECT * FROM $templates_table ORDER BY date_created DESC");
    $queue_items      = $wpdb->get_results("SELECT * FROM $queue_table WHERE status IN ('pending','running') ORDER BY scheduled_at ASC LIMIT 20");

    // Dashboard Stats
    $stat_total_sent   = intval($wpdb->get_var("SELECT SUM(total_sent) FROM $history_table WHERE broadcast_type='email'"));
    $stat_total_opens  = intval($wpdb->get_var("SELECT SUM(total_opens) FROM $history_table"));
    $stat_total_clicks = intval($wpdb->get_var("SELECT SUM(total_clicks) FROM $history_table"));
    $stat_total_sms    = intval($wpdb->get_var("SELECT SUM(total_sent) FROM $history_table WHERE broadcast_type='sms'"));
    $stat_open_rate    = $stat_total_sent > 0 ? round(($stat_total_opens / $stat_total_sent) * 100, 1) : 0;
    $stat_click_rate   = $stat_total_opens > 0 ? round(($stat_total_clicks / $stat_total_opens) * 100, 1) : 0;

    // Last 7 days chart data
    $chart_data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $sent  = intval($wpdb->get_var($wpdb->prepare("SELECT SUM(total_sent) FROM $history_table WHERE DATE(date_sent) = %s AND broadcast_type='email'", $date)));
        $opens = intval($wpdb->get_var($wpdb->prepare("SELECT SUM(total_opens) FROM $history_table WHERE DATE(date_sent) = %s", $date)));
        $chart_data[] = ['date' => date('M d', strtotime($date)), 'sent' => $sent, 'opens' => $opens];
    }
    $chart_json = json_encode($chart_data);

    $current_time = time();
    ?>
    <div class="wrap tka-bs-wrap">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        :root { --red:#E63946; --red-d:#d62828; --red-light:#fff5f5; --red-glow:rgba(230,57,70,0.12); --dark:#0f172a; --muted:#64748b; --border:#e2e8f0; --surface:#fff; --bg:#f8fafc; --radius:14px; --shadow:0 4px 6px -1px rgba(0,0,0,0.05),0 2px 4px -1px rgba(0,0,0,0.03); --shadow-lg:0 20px 25px -5px rgba(0,0,0,0.07),0 10px 10px -5px rgba(0,0,0,0.03); }
        .tka-bs-wrap * { box-sizing:border-box; font-family:'Inter',sans-serif; }
        .tka-bs-wrap { max-width:1300px; margin-top:20px; }

        /* NAV */
        .bs-nav { display:flex; gap:4px; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:6px; margin-bottom:24px; flex-wrap:wrap; align-items:center; box-shadow:var(--shadow); }
        .bs-tab { padding:9px 18px; font-size:13px; font-weight:600; color:var(--muted); cursor:pointer; border-radius:8px; transition:all 0.2s; background:transparent; border:none; white-space:nowrap; }
        .bs-tab.active { background:var(--red); color:#fff; box-shadow:0 4px 12px rgba(230,57,70,0.3); }
        .bs-tab:hover:not(.active) { background:var(--bg); color:var(--dark); }
        .bs-tab-content { display:none; } .bs-tab-content.active { display:block; }

        /* STAT CARDS */
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px; }
        .stat-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:22px 24px; box-shadow:var(--shadow); position:relative; overflow:hidden; }
        .stat-card::after { content:''; position:absolute; top:0; right:0; width:80px; height:100%; opacity:0.05; }
        .stat-card .sc-icon { font-size:28px; margin-bottom:8px; display:block; }
        .stat-card .sc-val { font-size:32px; font-weight:800; color:var(--dark); line-height:1; }
        .stat-card .sc-label { font-size:12px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px; margin-top:4px; }
        .stat-card .sc-sub { font-size:12px; color:var(--muted); margin-top:6px; }
        .stat-card.red-card { background:linear-gradient(135deg,#E63946,#ff6b6b); border:none; color:white; }
        .stat-card.red-card .sc-val, .stat-card.red-card .sc-label, .stat-card.red-card .sc-sub { color:rgba(255,255,255,0.9); }

        /* COMPOSE GRID */
        .compose-grid { display:grid; grid-template-columns:1.3fr 1fr; gap:28px; }
        .panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:28px; box-shadow:var(--shadow); }
        .panel-title { font-size:11px; text-transform:uppercase; letter-spacing:1px; font-weight:700; color:var(--muted); margin-bottom:18px; padding-bottom:10px; border-bottom:1px solid var(--bg); display:flex; justify-content:space-between; align-items:center; }
        
        /* INPUTS */
        .tka-bs-wrap label.lbl { display:block; margin-bottom:6px; font-weight:600; font-size:13px; color:#1e293b; }
        .tka-bs-wrap input[type=text],.tka-bs-wrap input[type=email],.tka-bs-wrap input[type=date],.tka-bs-wrap input[type=datetime-local],.tka-bs-wrap input[type=number],.tka-bs-wrap input[type=password],.tka-bs-wrap select,.tka-bs-wrap textarea { width:100%; padding:11px 15px; margin-bottom:18px; border:1.5px solid var(--border); border-radius:10px; font-size:14px; background:var(--bg); transition:all 0.2s; color:var(--dark); outline:none; }
        .tka-bs-wrap input[type=text]:focus,.tka-bs-wrap input[type=email]:focus,.tka-bs-wrap input[type=date]:focus,.tka-bs-wrap input[type=datetime-local]:focus,.tka-bs-wrap input[type=number]:focus,.tka-bs-wrap input[type=password]:focus,.tka-bs-wrap select:focus,.tka-bs-wrap textarea:focus { border-color:var(--red); background:#fff; box-shadow:0 0 0 3px var(--red-glow); }
        .mb0 { margin-bottom:0!important; }

        /* TOGGLE PILLS */
        .pill-group { display:flex; background:var(--bg); padding:5px; border-radius:10px; margin-bottom:18px; border:1px solid var(--border); }
        .pill-opt { flex:1; text-align:center; cursor:pointer; padding:9px; font-size:13px; font-weight:600; color:var(--muted); transition:0.2s; border-radius:7px; margin:0; }
        .pill-opt input { display:none; }
        .pill-opt:has(input:checked) { background:#fff; color:var(--red); box-shadow:0 2px 8px rgba(0,0,0,0.06); }

        /* SENDER SWITCHER */
        .bs-sender-card { background:linear-gradient(145deg, #ffffff, #f8fafc); border:1.5px solid var(--border); border-radius:12px; padding:16px; margin-bottom:18px; box-shadow:0 2px 8px rgba(0,0,0,0.03); }
        .bs-sender-pills { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:8px; margin-bottom:12px; }
        .bs-sender-pill { padding:10px 12px; background:var(--bg); border:1.5px solid var(--border); border-radius:10px; cursor:pointer; text-align:left; transition:all 0.2s; font-size:12px; font-weight:600; color:var(--dark); display:flex; align-items:center; gap:8px; user-select:none; }
        .bs-sender-pill:hover { border-color:var(--red); background:#fff; transform:translateY(-1px); }
        .bs-sender-pill.active { border-color:var(--red); background:#fff; box-shadow:0 0 0 2px var(--red-glow), 0 3px 10px rgba(230,57,70,0.1); color:var(--red); font-weight:700; }
        .bs-sender-pill .sp-icon { font-size:18px; flex-shrink:0; }
        .bs-sender-pill .sp-title { font-size:12px; font-weight:700; line-height:1.2; }
        .bs-sender-pill .sp-sub { font-size:10px; color:var(--muted); font-weight:500; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        @media (max-width: 600px) {
            .bs-sender-pills { grid-template-columns:1fr; }
        }

        /* EDITOR */
        .editor-box { border:1.5px solid var(--border); border-radius:10px; overflow:hidden; margin-bottom:18px; }
        .editor-hdr { background:var(--bg); padding:10px 14px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
        .editor-toggles { display:flex; background:var(--border); border-radius:6px; padding:3px; }
        .ed-btn { padding:4px 12px; border:none; background:transparent; border-radius:4px; font-size:12px; font-weight:600; color:var(--muted); cursor:pointer; transition:0.2s; }
        .ed-btn.active { background:#fff; color:var(--red); box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        #bs-visual-editor { min-height:300px; padding:16px; font-size:14px; line-height:1.7; outline:none; max-height:450px; overflow-y:auto; }
        #bs-code-editor { display:none; width:100%; min-height:300px; padding:16px; font-family:monospace; font-size:13px; color:#abb2bf; background:#282c34; border:none; outline:none; resize:vertical; }
        .sms-editor { min-height:120px; font-family:inherit; resize:vertical; }

        /* AUDIENCE LIST */
        .list-hdr { background:var(--bg); padding:10px 15px; border-radius:10px 10px 0 0; display:flex; justify-content:space-between; align-items:center; border:1px solid var(--border); border-bottom:none; font-size:12px; font-weight:600; }
        .list-body { border:1px solid var(--border); border-radius:0 0 10px 10px; height:320px; overflow-y:auto; background:#fff; }
        .check-item { display:flex; padding:10px 14px; border-bottom:1px solid var(--bg); cursor:pointer; align-items:center; transition:0.15s; margin:0; user-select:none; -webkit-user-select:none; }
        .check-item:hover { background:var(--bg); }
        .check-item input[type="checkbox"], #bs-select-all { width:18px!important; height:18px!important; min-width:18px!important; margin:0 12px 0 0!important; accent-color:var(--red)!important; cursor:pointer!important; outline:none!important; box-shadow:none!important; border-radius:4px!important; }
        .check-item input[type="checkbox"]:focus, #bs-select-all:focus { outline:none!important; box-shadow:none!important; }
        .ci-avatar { width:32px; height:32px; border-radius:50%; background:#fee2e2; color:var(--red); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; margin-right:10px; flex-shrink:0; }
        .ci-name { font-weight:600; color:var(--dark); font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .ci-meta { font-size:11px; color:var(--muted); margin-top:1px; }
        .src-tag { display:inline-block; padding:1px 6px; border-radius:4px; font-size:9px; margin-left:4px; text-transform:uppercase; font-weight:700; background:var(--border); color:var(--muted); }
        .src-book { background:#dbeafe; color:#1d4ed8; }

        /* BUTTONS */
        .btn { padding:12px 22px; border:none; border-radius:10px; cursor:pointer; font-size:14px; font-weight:600; transition:all 0.2s; display:inline-flex; align-items:center; gap:6px; }
        .btn-primary { background:var(--red); color:#fff; box-shadow:0 4px 14px rgba(230,57,70,0.3); }
        .btn-primary:hover:not(:disabled) { background:var(--red-d); transform:translateY(-1px); box-shadow:0 6px 20px rgba(230,57,70,0.35); }
        .btn-primary:disabled { background:#94a3b8; cursor:not-allowed; box-shadow:none; }
        .btn-dark { background:var(--dark); color:#fff; }
        .btn-dark:hover { background:#1e293b; transform:translateY(-1px); }
        .btn-ghost { background:var(--bg); border:1px solid var(--border); color:var(--dark); }
        .btn-ghost:hover { background:var(--border); }
        .btn-danger { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }
        .btn-danger:hover { background:#fee2e2; }
        .btn-sm { padding:7px 14px; font-size:12px; border-radius:8px; }
        .btn-full { width:100%; justify-content:center; padding:15px; font-size:15px; }
        .btn-group { display:flex; gap:10px; }

        /* PROGRESS / CONSOLE */
        .send-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.97); z-index:99999; backdrop-filter:blur(8px); padding:60px 40px; box-sizing:border-box; flex-direction:column; align-items:center; justify-content:center; }
        .send-card { background:#fff; border:1px solid var(--border); border-radius:20px; padding:36px; max-width:700px; width:100%; box-shadow:var(--shadow-lg); }
        .prog-bar-wrap { width:100%; background:var(--border); border-radius:99px; height:14px; overflow:hidden; margin:14px 0 8px; }
        .prog-bar-fill { height:100%; background:linear-gradient(90deg,#10b981,#34d399); width:0%; transition:width 0.4s; border-radius:99px; }
        .console-log { background:#0f172a; color:#10b981; font-family:monospace; padding:18px; border-radius:12px; overflow-y:auto; font-size:13px; line-height:1.6; height:280px; margin-top:14px; border:1px solid #1e293b; }
        .log-err { color:#f43f5e; } .log-info { color:#38bdf8; } .log-warn { color:#fbbf24; }

        /* HISTORY TABLE */
        .tbl-wrap { overflow-x:auto; border-radius:var(--radius); border:1px solid var(--border); }
        .bs-table { width:100%; border-collapse:collapse; min-width:900px; }
        .bs-table th { padding:12px 16px; background:var(--bg); color:var(--muted); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid var(--border); }
        .bs-table td { padding:14px 16px; border-bottom:1px solid var(--bg); font-size:13px; vertical-align:middle; }
        .bs-table tr:last-child td { border-bottom:none; }
        .bs-table tr:hover td { background:#fafafa; }
        .badge { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:99px; font-size:11px; font-weight:600; }
        .badge-green { background:#d1fae5; color:#065f46; }
        .badge-yellow { background:#fef3c7; color:#92400e; }
        .badge-blue { background:#dbeafe; color:#1d4ed8; }
        .badge-purple { background:#ede9fe; color:#5b21b6; }
        .badge-red { background:#fee2e2; color:#991b1b; }

        /* MODAL */
        .bs-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:999999; backdrop-filter:blur(4px); align-items:center; justify-content:center; }
        .bs-modal.open { display:flex; }
        .modal-card { background:#fff; border-radius:20px; box-shadow:0 25px 50px rgba(0,0,0,0.15); display:flex; flex-direction:column; max-height:90vh; overflow:hidden; width:90%; max-width:800px; }
        .modal-hdr { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
        .modal-hdr h2 { margin:0; font-size:18px; font-weight:700; color:var(--dark); }
        .modal-close { background:var(--bg); border:1px solid var(--border); width:32px; height:32px; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:18px; color:var(--muted); transition:0.2s; }
        .modal-close:hover { background:var(--border); color:var(--dark); }
        .modal-body { padding:24px; overflow-y:auto; flex:1; }

        /* CHART */
        .chart-bar-wrap { display:flex; align-items:flex-end; gap:8px; height:140px; padding:10px 0 0; }
        .chart-col { display:flex; flex-direction:column; align-items:center; flex:1; gap:2px; }
        .chart-bar-group { display:flex; align-items:flex-end; gap:3px; width:100%; }
        .chart-bar { border-radius:4px 4px 0 0; min-height:4px; transition:height 0.3s; cursor:pointer; }
        .chart-lbl { font-size:10px; color:var(--muted); font-weight:500; white-space:nowrap; }

        /* SCHEDULE PREVIEW */
        .sched-item { display:flex; align-items:center; gap:12px; padding:12px 16px; border:1px solid var(--border); border-radius:10px; margin-bottom:8px; background:var(--bg); }
        .sched-item .si-icon { font-size:20px; flex-shrink:0; }
        .sched-item .si-info { flex:1; }
        .sched-item .si-title { font-weight:600; font-size:13px; color:var(--dark); }
        .sched-item .si-meta { font-size:11px; color:var(--muted); margin-top:2px; }

        /* ======================== ADDRESS BOOK CRM ======================== */
        .ab-crm-container { display:flex; gap:22px; align-items:flex-start; margin-top:4px; }
        .ab-sidebar { width:270px; flex-shrink:0; }
        .ab-sidebar-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:20px; box-shadow:var(--shadow); }
        .ab-sb-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid var(--border); }
        .ab-sb-title { display:flex; align-items:center; gap:10px; }
        .ab-sb-icon { font-size:22px; }
        .ab-sb-heading { font-weight:800; font-size:14px; color:var(--dark); }
        .ab-sb-sub { font-size:11px; color:var(--muted); margin-top:1px; }
        .ab-btn-icon { background:var(--bg); border:1px solid var(--border); width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:13px; transition:0.2s; }
        .ab-btn-icon:hover { background:#fee2e2; border-color:#fca5a5; color:var(--red); }
        .ab-sb-actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:16px; }
        .ab-action-pill { background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:9px 6px; font-size:11px; font-weight:700; color:var(--dark); cursor:pointer; text-align:center; transition:0.2s; display:flex; align-items:center; justify-content:center; gap:4px; }
        .ab-action-pill:hover { background:#fff; border-color:var(--red); color:var(--red); transform:translateY(-1px); box-shadow:0 2px 6px rgba(0,0,0,0.04); }
        .ab-action-pill.active { background:var(--red); border-color:var(--red); color:#fff; box-shadow:0 3px 10px rgba(230,57,70,0.25); }
        .ab-group-list { display:flex; flex-direction:column; gap:4px; max-height:480px; overflow-y:auto; padding-right:2px; }
        .ab-group-item { display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border-radius:9px; cursor:pointer; transition:0.15s; font-size:13px; font-weight:600; color:#334155; }
        .ab-group-item:hover { background:var(--bg); color:var(--dark); }
        .ab-group-item.active { background:#fee2e2; color:var(--red); font-weight:700; }
        .ab-gi-left { display:flex; align-items:center; gap:10px; overflow:hidden; }
        .ab-gi-bullet { width:8px; height:8px; border-radius:50%; background:#cbd5e1; flex-shrink:0; }
        .ab-group-item.active .ab-gi-bullet { background:var(--red); }
        .ab-gi-name { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .ab-gi-badge { background:var(--border); color:var(--muted); font-size:11px; font-weight:700; padding:2px 8px; border-radius:99px; }
        .ab-group-item.active .ab-gi-badge { background:var(--red); color:#fff; }

        .ab-main { flex:1; min-width:0; }
        .ab-main-header { padding:22px 26px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; background:#fff; border-radius:var(--radius) var(--radius) 0 0; }
        .ab-header-buttons { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .ab-filter-bar { padding:14px 26px; background:var(--bg); border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; }
        .ab-search-wrap { position:relative; flex:1; min-width:240px; }
        .ab-search-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:14px; color:var(--muted); pointer-events:none; }
        .ab-search-wrap input { width:100%!important; padding:9px 34px 9px 34px!important; margin-bottom:0!important; background:#fff!important; font-size:13px!important; border-radius:9px!important; }
        .ab-clear-btn { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:var(--muted); cursor:pointer; font-size:12px; padding:4px; }
        .ab-segmented-filter { display:flex; background:var(--border); border-radius:8px; padding:3px; gap:2px; }
        .ab-seg-btn { border:none; background:transparent; padding:6px 12px; font-size:12px; font-weight:600; color:var(--muted); border-radius:6px; cursor:pointer; transition:0.15s; }
        .ab-seg-btn.active { background:#fff; color:var(--dark); box-shadow:0 1px 3px rgba(0,0,0,0.08); font-weight:700; }

        .ab-bulk-bar { padding:10px 26px; background:#fef3c7; border-bottom:1px solid #fde68a; display:flex; justify-content:space-between; align-items:center; }
        .ab-table th { padding:12px 18px; }
        .ab-table td { padding:12px 18px; border-bottom:1px solid #f1f5f9; }
        .ab-contact-avatar { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; color:#fff; flex-shrink:0; box-shadow:0 2px 5px rgba(0,0,0,0.1); }
        .ab-contact-name { font-weight:700; color:var(--dark); font-size:13px; margin-bottom:2px; }
        .ab-contact-email { font-size:12px; color:var(--muted); }
        .ab-contact-phone { font-size:12px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:5px; }
        .ab-pagination-footer { padding:14px 26px; background:#fff; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; border-radius:0 0 var(--radius) var(--radius); }
        .ab-row-item.selected-row td { background:#fffbeb!important; }

        /* SETTINGS GRID & SECTIONS */
        .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
        .settings-section { background:#fff; border:1px solid var(--border); border-radius:14px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.03); }

        @media (max-width:960px) {
            .ab-crm-container { flex-direction:column; }
            .ab-sidebar { width:100%; }
        }
        @media (max-width:900px) {
            .compose-grid { grid-template-columns:1fr; }
            .settings-grid { grid-template-columns:1fr; }
            .stat-grid { grid-template-columns:1fr 1fr; }
        }
        @media (max-width:500px) {
            .stat-grid { grid-template-columns:1fr; }
        }
    </style>

    <!-- NAV -->
    <div class="bs-nav">
        <button class="bs-tab active" onclick="bsTab('dashboard')">📊 Dashboard</button>
        <button class="bs-tab" onclick="bsTab('compose')">✉️ Email Broadcast</button>
        <button class="bs-tab" onclick="bsTab('sms')">📱 SMS Broadcast</button>
        <button class="bs-tab" onclick="bsTab('schedule')">🕐 Scheduled</button>
        <button class="bs-tab" onclick="bsTab('address-book')">📞 Address Book</button>
        <button class="bs-tab" onclick="bsTab('history')">📜 History</button>
        <button class="bs-tab" onclick="bsTab('settings')">⚙️ Settings</button>
        <button class="bs-tab" style="margin-left:auto;color:#dc2626;" onclick="document.cookie='tka_studio_unlocked=;expires=Thu,01 Jan 1970 00:00:00 UTC;path=/;';window.location.reload();">🔒 Lock</button>
    </div>

    <!-- ======================== DASHBOARD TAB ======================== -->
    <div id="tab-dashboard" class="bs-tab-content active">
        <div class="stat-grid">
            <div class="stat-card red-card">
                <span class="sc-icon">✉️</span>
                <div class="sc-val"><?php echo number_format($stat_total_sent); ?></div>
                <div class="sc-label">Total Emails Sent</div>
                <div class="sc-sub">All time</div>
            </div>
            <div class="stat-card">
                <span class="sc-icon">👁️</span>
                <div class="sc-val"><?php echo number_format($stat_total_opens); ?></div>
                <div class="sc-label">Unique Opens</div>
                <div class="sc-sub">Open Rate: <strong style="color:var(--red)"><?php echo $stat_open_rate; ?>%</strong></div>
            </div>
            <div class="stat-card">
                <span class="sc-icon">🖱️</span>
                <div class="sc-val"><?php echo number_format($stat_total_clicks); ?></div>
                <div class="sc-label">Link Clicks</div>
                <div class="sc-sub">CTR: <strong style="color:var(--red)"><?php echo $stat_click_rate; ?>%</strong></div>
            </div>
            <div class="stat-card">
                <span class="sc-icon">📱</span>
                <div class="sc-val"><?php echo number_format($stat_total_sms); ?></div>
                <div class="sc-label">SMS Sent</div>
                <div class="sc-sub">All time</div>
            </div>
            <div class="stat-card">
                <span class="sc-icon">👥</span>
                <div class="sc-val"><?php echo count($attendees); ?></div>
                <div class="sc-label">Email Contacts</div>
                <div class="sc-sub">DB + Address Book</div>
            </div>
            <div class="stat-card">
                <span class="sc-icon">📊</span>
                <div class="sc-val"><?php echo count($campaign_history); ?></div>
                <div class="sc-label">Total Campaigns</div>
                <div class="sc-sub">All time</div>
            </div>
        </div>

        <!-- 7-Day Activity Chart -->
        <div class="panel" style="margin-bottom:24px;">
            <div class="panel-title">📈 Last 7 Days Activity</div>
            <div style="display:flex;align-items:flex-end;gap:16px;margin-bottom:10px;">
                <span style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--muted);"><span style="width:12px;height:12px;background:#E63946;border-radius:3px;display:inline-block;"></span>Sent</span>
                <span style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--muted);"><span style="width:12px;height:12px;background:#10b981;border-radius:3px;display:inline-block;"></span>Opens</span>
            </div>
            <div class="chart-bar-wrap" id="dash-chart"></div>
        </div>

        <!-- Recent Campaigns -->
        <div class="panel">
            <div class="panel-title">🕐 Recent Campaigns</div>
            <div class="tbl-wrap">
                <table class="bs-table">
                    <thead><tr><th>Campaign</th><th>Type</th><th>Date</th><th>Sent</th><th>Opens</th><th>Clicks</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($campaign_history)): ?>
                            <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted);">No campaigns yet.</td></tr>
                        <?php else: foreach(array_slice($campaign_history, 0, 10) as $c): ?>
                            <tr>
                                <td><strong><?php echo esc_html($c->subject); ?></strong><br><span style="font-size:11px;color:var(--muted);"><?php echo esc_html($c->audience_label); ?></span><?php if (!empty($c->from_name) || !empty($c->from_email)): ?><br><span style="font-size:10px;background:#f1f5f9;color:#475569;padding:1px 6px;border-radius:4px;display:inline-block;margin-top:2px;font-weight:600;">From: <?php echo esc_html(!empty($c->from_name) ? $c->from_name : $c->from_email); ?></span><?php endif; ?></td>
                                <td><span class="badge <?php echo $c->broadcast_type === 'sms' ? 'badge-purple' : 'badge-blue'; ?>"><?php echo strtoupper(esc_html($c->broadcast_type)); ?></span></td>
                                <td style="font-size:12px;"><?php echo date('M d, Y', strtotime($c->date_sent)); ?></td>
                                <td><?php echo number_format($c->total_sent); ?></td>
                                <td><?php echo number_format($c->total_opens); ?><?php if ($c->total_sent > 0): ?><span style="font-size:10px;color:var(--muted);margin-left:4px;">(<?php echo round(($c->total_opens/$c->total_sent)*100,0); ?>%)</span><?php endif; ?></td>
                                <td><?php echo number_format($c->total_clicks); ?></td>
                                <td><span class="badge <?php echo intval($c->total_failed) > 0 ? 'badge-yellow' : 'badge-green'; ?>"><?php echo intval($c->total_failed) > 0 ? 'Partial' : 'Complete'; ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ======================== EMAIL COMPOSE TAB ======================== -->
    <div id="tab-compose" class="bs-tab-content">
        <div class="compose-grid">
            <!-- LEFT: COMPOSE -->
            <div>
                <div class="panel">
                    <div class="panel-title">✉️ Email Composer</div>

                    <?php
                    $all_sender_profiles = tka_get_sender_profiles();
                    $active_sender_key   = get_option('tka_active_sender_profile', 'main_church');
                    $active_sender_data  = $all_sender_profiles[$active_sender_key] ?? $all_sender_profiles['main_church'];
                    ?>
                    <!-- SENDER PROFILE SWITCHER -->
                    <div class="bs-sender-card">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                            <label class="lbl" style="margin-bottom:0;display:flex;align-items:center;gap:6px;font-weight:700;">
                                <span>📬</span> Sender Profile (From Identity)
                            </label>
                            <span style="font-size:11px;font-weight:700;background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:6px;">⚡ Switch Anytime</span>
                        </div>

                        <div class="bs-sender-pills">
                            <div class="bs-sender-pill <?php echo ($active_sender_key === 'main_church') ? 'active' : ''; ?>" id="bs-pill-sender-main_church" onclick="bsSelectSenderProfile('main_church')">
                                <span class="sp-icon">🏛️</span>
                                <div style="min-width:0;">
                                    <div class="sp-title">TKA Community <span style="font-size:10px;color:#16a34a;background:#dcfce7;padding:1px 5px;border-radius:4px;font-weight:700;">Direct</span></div>
                                    <div class="sp-sub">support@thekingsassembly.org</div>
                                </div>
                            </div>
                            <div class="bs-sender-pill <?php echo ($active_sender_key === 'main_church_relay') ? 'active' : ''; ?>" id="bs-pill-sender-main_church_relay" onclick="bsSelectSenderProfile('main_church_relay')">
                                <span class="sp-icon">🏛️</span>
                                <div style="min-width:0;">
                                    <div class="sp-title">TKA Community <span style="font-size:10px;color:#d97706;background:#fef3c7;padding:1px 5px;border-radius:4px;font-weight:700;">Relay</span></div>
                                    <div class="sp-sub">Via elevate.tkamedia.com</div>
                                </div>
                            </div>
                            <div class="bs-sender-pill <?php echo ($active_sender_key === 'elevate') ? 'active' : ''; ?>" id="bs-pill-sender-elevate" onclick="bsSelectSenderProfile('elevate')">
                                <span class="sp-icon">⚡</span>
                                <div style="min-width:0;">
                                    <div class="sp-title">Elevate TKA</div>
                                    <div class="sp-sub">Youth Church Default</div>
                                </div>
                            </div>
                            <div class="bs-sender-pill <?php echo ($active_sender_key === 'custom') ? 'active' : ''; ?>" id="bs-pill-sender-custom" onclick="bsSelectSenderProfile('custom')">
                                <span class="sp-icon">⚙️</span>
                                <div style="min-width:0;">
                                    <div class="sp-title">Custom...</div>
                                    <div class="sp-sub">Custom sender</div>
                                </div>
                            </div>
                        </div>

                        <!-- Active Preview Banner -->
                        <div id="bs-sender-preview-card" style="background:#fff;border:1px solid var(--border);border-radius:9px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <span style="font-size:10px;color:var(--muted);text-transform:uppercase;font-weight:700;letter-spacing:0.5px;">Active Sender Identity:</span>
                                <div style="font-weight:700;color:var(--dark);font-size:13px;" id="bs-sender-display-name"><?php echo esc_html($active_sender_data['from_name']); ?></div>
                                <div style="font-size:12px;color:var(--muted);" id="bs-sender-display-email"><?php echo esc_html($active_sender_data['from_email']); ?> &bull; Reply-To: <span id="bs-sender-display-reply"><?php echo esc_html($active_sender_data['reply_to']); ?></span></div>
                            </div>
                            <div style="display:flex;gap:6px;">
                                <button type="button" class="btn btn-ghost" style="padding:4px 10px;font-size:11px;" onclick="bsQuickTestSender(this)">🧪 Quick Test</button>
                                <button type="button" class="btn btn-ghost" style="padding:4px 10px;font-size:11px;" onclick="bsToggleSenderDetails()">Details ✏️</button>
                            </div>
                        </div>

                        <!-- Expandable Drawer for Fine-Tuning -->
                        <div id="bs-sender-details-drawer" style="display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border);">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;">
                                <div>
                                    <label class="lbl" style="font-size:11px;">From Name (Display Name)</label>
                                    <input type="text" id="bs-sender-from-name" value="<?php echo esc_attr($active_sender_data['from_name']); ?>" style="margin-bottom:0;" oninput="bsUpdateSenderLivePreview()">
                                </div>
                                <div>
                                    <label class="lbl" style="font-size:11px;">From Email (Verified Identity)</label>
                                    <input type="email" id="bs-sender-from-email" value="<?php echo esc_attr($active_sender_data['from_email']); ?>" style="margin-bottom:0;" oninput="bsUpdateSenderLivePreview()">
                                </div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div>
                                    <label class="lbl" style="font-size:11px;">Reply-To Email</label>
                                    <input type="email" id="bs-sender-reply-to" value="<?php echo esc_attr($active_sender_data['reply_to']); ?>" style="margin-bottom:0;" oninput="bsUpdateSenderLivePreview()">
                                </div>
                                <div>
                                    <label class="lbl" style="font-size:11px;">Header Logo URL (Optional)</label>
                                    <input type="text" id="bs-sender-logo-url" value="<?php echo esc_attr($active_sender_data['logo_url']); ?>" style="margin-bottom:0;" placeholder="https://..." oninput="bsUpdateSenderLivePreview()">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="background:var(--bg);border:1px dashed var(--border);border-radius:10px;padding:14px;margin-bottom:18px;">
                        <label class="lbl">Saved Templates</label>
                        <div style="display:flex;gap:10px;">
                            <select id="bs-tpl-selector" onchange="bsLoadTemplate(this)" style="margin:0;flex:1;">
                                <option value="" data-id="">-- Start fresh --</option>
                                <?php foreach($saved_templates as $tpl): ?>
                                    <option value="<?php echo esc_attr($tpl->template_content); ?>" data-id="<?php echo $tpl->id; ?>"><?php echo esc_html($tpl->template_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="bs-del-tpl" class="btn btn-danger btn-sm" style="display:none;" onclick="bsDeleteTemplate()">🗑️</button>
                        </div>
                    </div>

                    <label class="lbl">Subject Line</label>
                    <input type="text" id="bs-subject" placeholder="e.g. Amplified XI — Weekend Special" required>

                    <label class="lbl">Attachment (Optional)</label>
                    <div style="background:#fff;border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer;position:relative;margin-bottom:18px;">
                        <input type="file" id="bs-attachment" style="width:100%;height:100%;position:absolute;top:0;left:0;opacity:0;cursor:pointer;z-index:5;">
                        <div style="display:flex;align-items:center;gap:10px;pointer-events:none;">
                            <span style="background:var(--border);padding:6px 12px;border-radius:6px;font-size:12px;font-weight:700;color:var(--muted);">Choose File</span>
                            <span id="bs-file-name" style="font-size:13px;color:#94a3b8;">No file chosen</span>
                        </div>
                    </div>

                    <label class="lbl">Message</label>
                    <div class="editor-box">
                        <div class="editor-hdr">
                            <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;">✏️ Design</span>
                            <div class="editor-toggles">
                                <button type="button" class="ed-btn active" id="bs-btn-visual" onclick="bsToggleEditor('visual')">Visual</button>
                                <button type="button" class="ed-btn" id="bs-btn-code" onclick="bsToggleEditor('code')">HTML</button>
                            </div>
                        </div>
                        <div id="bs-visual-editor" contenteditable="true"><h2>Welcome!</h2><p>Write your message here... Use <strong>[Name]</strong> to personalize.</p></div>
                        <textarea id="bs-code-editor" placeholder="<!-- HTML here -->"></textarea>
                    </div>

                    <!-- Schedule or Send -->
                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:18px;">
                        <div class="pill-group" style="margin-bottom:12px;">
                            <label class="pill-opt"><input type="radio" name="bs-send-mode" value="now" checked onclick="document.getElementById('bs-schedule-row').style.display='none'"> Send Now</label>
                            <label class="pill-opt"><input type="radio" name="bs-send-mode" value="later" onclick="document.getElementById('bs-schedule-row').style.display='block'"> Schedule</label>
                        </div>
                        <div id="bs-schedule-row" style="display:none;">
                            <label class="lbl">Send At (Your local time)</label>
                            <input type="datetime-local" id="bs-scheduled-at" class="mb0">
                        </div>
                    </div>

                    <div class="btn-group">
                        <button type="button" id="bs-send-btn" class="btn btn-primary btn-full" onclick="bsStartSend()">🚀 Send Campaign</button>
                        <button type="button" class="btn btn-dark" onclick="bsSaveTemplate()" title="Save as Template">💾</button>
                    </div>
                </div>
            </div>

            <!-- RIGHT: AUDIENCE -->
            <div>
                <div class="panel">
                    <div class="panel-title">🎯 Target Audience <span style="color:var(--red);font-size:13px;font-weight:700;" id="bs-count"><?php echo count($attendees); ?> selected</span></div>

                    <div class="pill-group">
                        <label class="pill-opt"><input type="radio" name="bs-audience-mode" value="all" checked onclick="bsAudienceMode('all')"> All Emails</label>
                        <label class="pill-opt"><input type="radio" name="bs-audience-mode" value="filter" onclick="bsAudienceMode('filter')"> Filter</label>
                        <label class="pill-opt"><input type="radio" name="bs-audience-mode" value="direct" onclick="bsAudienceMode('direct')"> Paste/CSV</label>
                    </div>

                    <div id="bs-direct-area" style="display:none;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:14px;">
                        <label class="lbl">Paste (Name, Email or Email, Name):</label>
                        <textarea id="bs-direct-text" rows="5" placeholder="John Doe, john@example.com&#10;jane@demo.com, Jane Smith" style="font-family:monospace;font-size:13px;"></textarea>
                        <label class="lbl">Or upload CSV:</label>
                        <input type="file" id="bs-direct-csv" accept=".csv,.txt" class="mb0">
                    </div>

                    <div id="bs-filter-area" style="display:none;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:14px;">
                        <input type="text" id="bs-search" placeholder="🔍 Search name or email..." onkeyup="bsApplyFilters()" class="mb0" style="margin-bottom:10px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <select id="bs-filter-source" onchange="bsApplyFilters()" style="margin:0;">
                                <option value="">All Sources</option>
                                <option value="imported">CSV Imports</option>
                                <option value="web">Web Registered</option>
                                <option value="address_book">Address Book</option>
                            </select>
                            <select id="bs-filter-group" onchange="bsApplyFilters()" style="margin:0;">
                                <option value="">All Groups</option>
                                <option value="today-returning">⚡ Checked In Members (Returning) (<?php echo $today_return_count; ?>)</option>
                                <option value="first-timer-today">🌟 First Timers Today (<?php echo $ft_today_count; ?>)</option>
                                <option value="today-all">⚡ All Checked In Today (<?php echo $today_all_count; ?>)</option>
                                <option value="first-timer-all">🌟 All First Timers (<?php echo $ft_all_count; ?>)</option>
                                <option value="member">Members Only (<?php echo $mem_count; ?>)</option>
                                <?php foreach($address_book_groups as $g): ?>
                                    <option value="ab_<?php echo esc_attr($g); ?>">📖 <?php echo esc_html($g); ?> (<?php echo $ab_group_counts[$g] ?? 0; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;background:#f8fafc;padding:8px 12px;border-radius:8px;border:1px solid #e2e8f0;margin-top:8px;">
                            <span style="font-size:12px;font-weight:600;color:#64748b;white-space:nowrap;">📅 Date:</span>
                            <input type="date" id="bs-filter-date" onchange="bsApplyFilters()" value="" style="margin:0;padding:4px 8px;font-size:12px;border-radius:6px;border:1px solid #cbd5e1;flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('bs-filter-date').value='<?php echo esc_attr($today_wp); ?>';bsApplyFilters();" style="padding:4px 8px;font-size:11px;">Today</button>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('bs-filter-date').value='';bsApplyFilters();" style="padding:4px 8px;font-size:11px;">Clear</button>
                        </div>
                    </div>

                    <div id="bs-list-area">
                        <div class="list-hdr">
                            <label style="margin:0;display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;">
                                <input type="checkbox" id="bs-select-all" onclick="bsToggleAll(this)" checked> Select All Visible
                            </label>
                        </div>
                        <div class="list-body">
                            <?php if (empty($attendees)): ?>
                                <div style="padding:20px;text-align:center;color:var(--muted);">No email contacts found.</div>
                            <?php else: foreach($attendees as $a):
                                $init      = strtoupper(substr($a->full_name, 0, 1));
                                $src       = $a->source_type ?? 'imported';
                                $srcTx     = $src === 'imported' ? 'CSV' : ($src === 'web' ? 'WEB' : '📖 BOOK');
                                $srcCl     = $src === 'address_book' ? 'src-book' : '';
                                $is_ft     = (strcasecmp($a->is_first_timer ?? '', 'Yes') === 0);
                                $reg_date  = (!empty($a->time) && strtotime($a->time) > 0) ? date('Y-m-d', strtotime($a->time)) : '';
                                $ft        = $is_ft ? 'first-timer' : '';
                                $ft_today  = ($is_ft && $reg_date === $today_wp) ? 'today' : '';
                                $mem       = (strcasecmp($a->is_member ?? '', 'Yes') === 0) ? 'member' : '';
                                $days      = !empty($a->time) ? floor(($current_time - strtotime($a->time)) / 86400) : 0;
                                $is_return = (!empty($a->checked_in_today) && !$is_ft) ? 'yes' : 'no';
                            ?>
                                <div class="check-item" onclick="bsToggleRow(event, this)" data-name="<?php echo esc_attr(strtolower($a->full_name)); ?>" data-email="<?php echo esc_attr(strtolower($a->email)); ?>" data-source="<?php echo esc_attr($src); ?>" data-first="<?php echo esc_attr($ft); ?>" data-first-today="<?php echo esc_attr($ft_today); ?>" data-date="<?php echo esc_attr($reg_date); ?>" data-member="<?php echo esc_attr($mem); ?>" data-group="<?php echo esc_attr($a->address_group ?? ''); ?>" data-days="<?php echo $days; ?>" data-today="<?php echo esc_attr($a->checked_in_today ?? ''); ?>" data-today-returning="<?php echo $is_return; ?>">
                                    <input type="checkbox" name="recipients[]" value="<?php echo esc_attr($a->email); ?>" data-realname="<?php echo esc_attr($a->full_name); ?>" onclick="event.stopPropagation(); this.blur(); bsUpdateCount();" onchange="this.blur(); bsUpdateCount();" checked>
                                    <div class="ci-avatar"><?php echo esc_html($init); ?></div>
                                    <div style="overflow:hidden;flex:1;">
                                        <div class="ci-name">
                                            <?php echo esc_html($a->full_name); ?> 
                                            <span class="src-tag <?php echo $srcCl; ?>"><?php echo esc_html($srcTx); ?></span>
                                            <?php if ($ft_today): ?>
                                                <span class="src-tag" style="background:#fef3c7;color:#92400e;font-weight:700;">🌟 First Timer Today</span>
                                            <?php elseif ($ft): ?>
                                                <span class="src-tag" style="background:#fef3c7;color:#92400e;font-weight:700;">🌟 First Timer</span>
                                            <?php endif; ?>
                                            <?php if (!empty($a->checked_in_today)): ?>
                                                <span class="src-tag" style="background:#dcfce7;color:#166534;font-weight:700;">⚡ In Today</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ci-meta">
                                            <?php echo esc_html($a->email); ?>
                                            <?php if (!empty($reg_date)): ?>
                                                <span style="color:#94a3b8;font-size:11px;margin-left:6px;">• <?php echo esc_html(date('M d, Y', strtotime($reg_date))); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================== SMS TAB ======================== -->
    <div id="tab-sms" class="bs-tab-content">
        <div class="compose-grid">
            <div>
                <div class="panel">
                    <div class="panel-title">📱 SMS Composer</div>

                    <label class="lbl">Message</label>
                    <div style="position:relative;margin-bottom:18px;">
                        <textarea id="sms-message" class="sms-editor" placeholder="Hello [Name], join us this Sunday at 9am...&#10;&#10;Use [Name] to personalize each SMS." style="margin-bottom:6px;padding-right:90px;"></textarea>
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:12px;color:var(--muted);">Use <code>[Name]</code> for personalization</span>
                            <span id="sms-char-count" style="font-size:12px;font-weight:700;color:var(--muted);">0/160</span>
                        </div>
                    </div>

                    <div class="pill-group">
                        <label class="pill-opt"><input type="radio" name="sms-send-mode" value="now" checked onclick="document.getElementById('sms-schedule-row').style.display='none'"> Send Now</label>
                        <label class="pill-opt"><input type="radio" name="sms-send-mode" value="later" onclick="document.getElementById('sms-schedule-row').style.display='block'"> Schedule</label>
                    </div>
                    <div id="sms-schedule-row" style="display:none;margin-bottom:18px;">
                        <label class="lbl">Send At</label>
                        <input type="datetime-local" id="sms-scheduled-at" class="mb0">
                    </div>

                    <button type="button" id="sms-send-btn" class="btn btn-primary btn-full" onclick="smsStartSend()">📤 Send SMS Campaign</button>
                </div>
            </div>
            <div>
                <div class="panel">
                    <div class="panel-title">🎯 SMS Recipients <span style="color:var(--red);font-size:13px;font-weight:700;" id="sms-count">0 selected</span></div>

                    <div class="pill-group" style="margin-bottom:14px;">
                        <label class="pill-opt"><input type="radio" name="sms-src" value="addressbook" checked onclick="smsBuildList()"> 📖 Phone Books</label>
                        <label class="pill-opt"><input type="radio" name="sms-src" value="members" onclick="smsBuildList()"> ⛪ Members DB</label>
                        <label class="pill-opt"><input type="radio" name="sms-src" value="direct" onclick="smsBuildList()"> ✍️ Paste Numbers</label>
                        <label class="pill-opt"><input type="radio" name="sms-src" value="all" onclick="smsBuildList()"> All</label>
                    </div>

                    <!-- Stored Phone Book Selector Dropdown -->
                    <div id="sms-group-select-wrap" style="background:#f8fafc;border:1.5px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:14px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                            <label class="lbl" style="margin-bottom:0;font-size:12px;font-weight:700;color:var(--dark);">📁 Select Stored Phone Book:</label>
                            <span style="font-size:11px;color:var(--muted);"><?php echo count($address_book_groups); ?> Phone Books</span>
                        </div>
                        <select id="sms-group-select" onchange="smsBuildList()" style="margin-bottom:0;background:#fff;font-weight:600;font-size:13px;color:var(--dark);">
                            <option value="">📂 All Phone Books Combined (<?php echo number_format(count($sms_contacts)); ?> contacts)</option>
                            <?php foreach($address_book_groups as $g): 
                                $g_count = $group_phone_counts[$g] ?? 0;
                            ?>
                                <option value="<?php echo esc_attr($g); ?>">📖 <?php echo esc_html($g); ?> (<?php echo number_format($g_count); ?> numbers)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Direct Numbers Area -->
                    <div id="sms-direct-area" style="display:none;margin-bottom:14px;">
                        <label class="lbl">Enter or Paste Phone Numbers:</label>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:10px;font-size:11px;color:var(--muted);line-height:1.5;">
                            💡 One number per line, or comma-separated. Format: <code>08012345678</code> or <code>John Doe, 08012345678</code>
                        </div>
                        <textarea id="sms-direct-numbers" rows="8" placeholder="08074272063&#10;Pastor Somto, 09155727255&#10;08157389769" oninput="smsUpdateDirectCount()" style="font-family:monospace;font-size:13px;line-height:1.5;margin-bottom:6px;"></textarea>
                        <div id="sms-direct-count" style="font-size:12px;font-weight:700;color:var(--red);">0 valid numbers entered</div>
                    </div>

                    <!-- Recipient List Container -->
                    <div id="sms-list-container">
                        <input type="text" id="sms-search" placeholder="🔍 Search contacts in this phone book..." onkeyup="smsFilter()" style="margin-bottom:10px;">

                        <div class="list-hdr">
                            <label style="margin:0;display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;font-weight:600;">
                                <input type="checkbox" id="sms-select-all" onclick="smsToggleAll(this)" checked> Select All Visible
                            </label>
                            <div style="display:flex;gap:6px;">
                                <button type="button" class="btn btn-ghost btn-sm" onclick="smsSelectAll(true)" style="padding:2px 8px;font-size:11px;">Select All</button>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="smsSelectAll(false)" style="padding:2px 8px;font-size:11px;">Deselect All</button>
                            </div>
                        </div>
                        <div class="list-body" id="sms-list-body" style="height:360px;">
                            <div style="padding:20px;text-align:center;color:var(--muted);">Loading...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================== SCHEDULED TAB ======================== -->
    <div id="tab-schedule" class="bs-tab-content">
        <div class="panel">
            <div class="panel-title">🕐 Scheduled & Queued Broadcasts</div>
            <?php if (empty($queue_items)): ?>
                <div style="text-align:center;padding:50px 20px;color:var(--muted);">
                    <div style="font-size:48px;margin-bottom:12px;">🕐</div>
                    <h3 style="margin:0 0 8px;color:var(--dark);">No Scheduled Broadcasts</h3>
                    <p style="margin:0;font-size:14px;">Schedule a campaign from the Email or SMS tabs.</p>
                </div>
            <?php else: ?>
                <?php foreach($queue_items as $job): ?>
                    <div class="sched-item">
                        <div class="si-icon"><?php echo $job->broadcast_type === 'sms' ? '📱' : '✉️'; ?></div>
                        <div class="si-info">
                            <div class="si-title"><?php echo esc_html($job->subject); ?></div>
                            <div class="si-meta">
                                📅 <?php echo date('M d, Y @ g:i A', strtotime($job->scheduled_at)); ?>
                                &nbsp;·&nbsp; 🎯 <?php echo esc_html($job->audience_label); ?>
                                &nbsp;·&nbsp; <span class="badge <?php echo $job->status === 'running' ? 'badge-yellow' : 'badge-blue'; ?>"><?php echo ucfirst($job->status); ?></span>
                            </div>
                        </div>
                        <?php if ($job->status === 'pending'): ?>
                            <div style="display:flex;gap:6px;">
                                <button class="btn btn-primary btn-sm" onclick="runScheduledNow(<?php echo $job->id; ?>, this)">⚡ Send Now</button>
                                <button class="btn btn-danger btn-sm" onclick="cancelScheduled(<?php echo $job->id; ?>, this)">Cancel</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <p style="font-size:12px;color:var(--muted);margin-top:16px;">⚙️ The scheduler runs automatically every 2 minutes via WordPress Cron. Make sure your site receives regular traffic or use a real cron job for reliable delivery.</p>
        </div>
    </div>

    <!-- ======================== ADDRESS BOOK TAB ======================== -->
    <div id="tab-address-book" class="bs-tab-content">
        <div class="ab-crm-container">
            <!-- LEFT: GROUPS SIDEBAR -->
            <div class="ab-sidebar">
                <div class="ab-sidebar-card">
                    <!-- Sidebar Header -->
                    <div class="ab-sb-header">
                        <div class="ab-sb-title">
                            <span class="ab-sb-icon">📁</span>
                            <div>
                                <div class="ab-sb-heading">Groups</div>
                                <div class="ab-sb-sub"><?php echo count($address_book_groups); ?> Groups Total</div>
                            </div>
                        </div>
                        <button type="button" class="ab-btn-icon" onclick="abOpenImportModal()" title="Import or Create Group">➕</button>
                    </div>

                    <!-- Quick Actions -->
                    <div class="ab-sb-actions" style="grid-template-columns:1fr 1fr 1fr;">
                        <button type="button" class="ab-action-pill" onclick="abOpenImportModal()">
                            <span>📥</span> Import
                        </button>
                        <button type="button" class="ab-action-pill" onclick="abOpenAddContactModal()">
                            <span>👤</span> Add
                        </button>
                        <button type="button" class="ab-action-pill" onclick="abSyncMembers(this)" title="Sync all registered members from Members DB into Address Book">
                            <span>🔄</span> Sync DB
                        </button>
                    </div>

                    <!-- Groups List -->
                    <div class="ab-group-list">
                        <div class="ab-group-item active" data-group="" onclick="abSelectGroup(this, '')">
                            <div class="ab-gi-left">
                                <span class="ab-gi-bullet" style="background:#E63946;"></span>
                                <span class="ab-gi-name">All Contacts</span>
                            </div>
                            <span class="ab-gi-badge" id="ab-sb-total"><?php echo count($raw_contacts); ?></span>
                        </div>
                        <?php foreach($address_book_groups as $g): 
                            $count = $ab_group_counts[$g] ?? 0;
                        ?>
                            <div class="ab-group-item" data-group="<?php echo esc_attr($g); ?>" onclick="abSelectGroup(this, '<?php echo esc_js($g); ?>')">
                                <div class="ab-gi-left">
                                    <span class="ab-gi-bullet"></span>
                                    <span class="ab-gi-name"><?php echo esc_html($g); ?></span>
                                </div>
                                <span class="ab-gi-badge"><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Active Group Actions -->
                    <div id="ab-group-manage-bar" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                        <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px;">Selected Phone Book:</div>
                        <div style="display:flex;gap:6px;margin-bottom:8px;">
                            <button type="button" class="btn btn-ghost btn-sm" style="flex:1;" onclick="abRenameActiveGroup()">✏️ Rename</button>
                            <button type="button" class="btn btn-danger btn-sm" style="flex:1;" onclick="abDeleteActiveGroup()">🗑️ Delete</button>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm btn-full" onclick="smsSendToActiveGroup()" style="font-size:12px;">📱 Send SMS to this Phone Book</button>
                    </div>
                </div>
            </div>

            <!-- RIGHT: CONTACTS DIRECTORY -->
            <div class="ab-main">
                <div class="panel" style="padding:0;overflow:hidden;">
                    <!-- Main Header -->
                    <div class="ab-main-header">
                        <div>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <h2 id="ab-active-title" style="margin:0;font-size:20px;font-weight:800;color:var(--dark);">All Contacts</h2>
                                <span class="badge badge-blue" id="ab-count-badge"><?php echo count($raw_contacts); ?> contacts</span>
                            </div>
                            <p style="margin:4px 0 0;font-size:13px;color:var(--muted);" id="ab-active-desc">
                                <?php echo count($raw_contacts); ?> total contacts &nbsp;·&nbsp; <?php echo $ab_with_phone; ?> with phone numbers
                            </p>
                        </div>

                        <div class="ab-header-buttons">
                            <button type="button" class="btn btn-ghost btn-sm" onclick="abSyncMembers(this)" title="Sync all registered members from Members DB into Address Book">🔄 Sync Members DB</button>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="abExportCsv()">📤 Export CSV</button>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="abOpenAddContactModal()">👤 + Add Contact</button>
                            <button type="button" class="btn btn-primary btn-sm" onclick="abOpenImportModal()">📥 Import Contacts</button>
                        </div>
                    </div>

                    <!-- Toolbar -->
                    <div class="ab-filter-bar">
                        <div class="ab-search-wrap">
                            <span class="ab-search-icon">🔍</span>
                            <input type="text" id="ab-search-input" placeholder="Search by name, email, or phone number..." oninput="abHandleSearch()">
                            <button type="button" id="ab-clear-search" class="ab-clear-btn" onclick="abClearSearch()" style="display:none;">✕</button>
                        </div>

                        <div class="ab-segmented-filter">
                            <button type="button" class="ab-seg-btn active" data-filter="all" onclick="abFilterByPhone('all', this)">All</button>
                            <button type="button" class="ab-seg-btn" data-filter="phone" onclick="abFilterByPhone('phone', this)">📱 Has Phone</button>
                            <button type="button" class="ab-seg-btn" data-filter="email_only" onclick="abFilterByPhone('email_only', this)">✉️ Email Only</button>
                        </div>
                    </div>

                    <!-- Bulk Actions Bar -->
                    <div id="ab-bulk-bar" class="ab-bulk-bar" style="display:none;">
                        <span id="ab-bulk-count" style="font-weight:700;font-size:13px;color:#92400e;">0 contacts selected</span>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <select id="ab-bulk-move-target" style="margin:0;padding:6px 10px;font-size:12px;border-radius:8px;background:#fff;">
                                <option value="">Move to Group...</option>
                                <?php foreach($address_book_groups as $g): ?>
                                    <option value="<?php echo esc_attr($g); ?>"><?php echo esc_html($g); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="abBulkMove()">Move</button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="abBulkDelete()">🗑️ Delete Selected</button>
                        </div>
                    </div>

                    <!-- Contacts Table -->
                    <div class="tbl-wrap" style="border:none;border-radius:0;">
                        <table class="bs-table ab-table">
                            <thead>
                                <tr>
                                    <th style="width:36px;"><input type="checkbox" id="ab-select-all" onclick="abToggleSelectAll(this)"></th>
                                    <th style="width:50px;"></th>
                                    <th>Name &amp; Email</th>
                                    <th>Phone Number</th>
                                    <th>Group</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="ab-table-body">
                                <!-- Populated dynamically by JS with fast pagination -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Empty State -->
                    <div id="ab-empty-state" style="display:none;text-align:center;padding:50px 20px;">
                        <div style="font-size:42px;margin-bottom:10px;">📇</div>
                        <h3 style="margin:0 0 6px;color:var(--dark);">No Contacts Found</h3>
                        <p style="margin:0 0 16px;color:var(--muted);font-size:13px;" id="ab-empty-text">No contacts match your current search or filter.</p>
                        <button type="button" class="btn btn-primary btn-sm" onclick="abOpenImportModal()">📥 Import Contacts Now</button>
                    </div>

                    <!-- Pagination Footer -->
                    <div class="ab-pagination-footer">
                        <div style="font-size:13px;color:var(--muted);">
                            Showing <strong id="ab-page-range" style="color:var(--dark);">0–0</strong> of <strong id="ab-page-total" style="color:var(--dark);">0</strong> contacts
                        </div>

                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);">
                                <span>Per page:</span>
                                <select id="ab-per-page" onchange="abChangePerPage(this.value)" style="margin:0;padding:4px 8px;font-size:12px;border-radius:6px;background:var(--bg);">
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>

                            <div style="display:flex;gap:6px;">
                                <button type="button" class="btn btn-ghost btn-sm" id="ab-prev-btn" onclick="abChangePage(-1)">← Prev</button>
                                <span id="ab-current-page" style="padding:6px 12px;font-size:12px;font-weight:700;color:var(--dark);background:var(--bg);border-radius:8px;">1 / 1</span>
                                <button type="button" class="btn btn-ghost btn-sm" id="ab-next-btn" onclick="abChangePage(1)">Next →</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================== HISTORY TAB ======================== -->
    <div id="tab-history" class="bs-tab-content">
        <div class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
                <h2 style="margin:0;font-size:20px;font-weight:800;color:var(--dark);">📜 Broadcast History</h2>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button id="hist-bulk-del" class="btn btn-danger btn-sm" style="display:none;" onclick="histBulkDelete()">🗑️ Delete Selected</button>
                    <input type="date" id="hist-date-filter" onchange="histRender()" style="margin:0;max-width:180px;" title="Filter by date">
                    <select id="hist-type-filter" onchange="histRender()" style="margin:0;max-width:140px;">
                        <option value="">All Types</option>
                        <option value="email">Email</option>
                        <option value="sms">SMS</option>
                    </select>
                </div>
            </div>
            <div class="tbl-wrap">
                <table class="bs-table" id="hist-table">
                    <thead>
                        <tr>
                            <th style="width:36px;"><input type="checkbox" id="hist-sel-all" onclick="histToggleAll(this)"></th>
                            <th>Campaign</th><th>Type</th><th>Date</th><th>Sent</th><th>Opens</th><th>Clicks</th><th>Status</th><th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campaign_history)): ?>
                            <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted);">No campaigns sent yet.</td></tr>
                        <?php else: foreach($campaign_history as $c):
                            $dt       = date_create($c->date_sent);
                            $iso      = date_format($dt, 'Y-m-d');
                            $ds       = date_format($dt, 'M d, Y');
                            $ts       = date_format($dt, 'g:i A');
                            $has_fail = intval($c->total_failed) > 0;
                            $or       = $c->total_sent > 0 ? round(($c->total_opens / $c->total_sent) * 100, 0) : 0;
                        ?>
                        <template id="hist-msg-<?php echo $c->id; ?>"><?php echo !empty($c->message_content) ? wp_kses_post($c->message_content) : '<i>Content not stored for this campaign.</i>'; ?></template>
                        <tr class="hist-row" data-date="<?php echo $iso; ?>" data-type="<?php echo esc_attr($c->broadcast_type); ?>">
                            <td><input type="checkbox" class="hist-check" value="<?php echo $c->id; ?>" onchange="histUpdateBulkBtn()"></td>
                            <td><strong><?php echo esc_html($c->subject); ?></strong><br><span style="font-size:11px;color:var(--muted);"><?php echo esc_html($c->audience_label); ?></span><?php if (!empty($c->from_name) || !empty($c->from_email)): ?><br><span style="font-size:10px;background:#f1f5f9;color:#475569;padding:1px 6px;border-radius:4px;display:inline-block;margin-top:2px;font-weight:600;">From: <?php echo esc_html(!empty($c->from_name) ? $c->from_name : $c->from_email); ?></span><?php endif; ?></td>
                            <td><span class="badge <?php echo $c->broadcast_type === 'sms' ? 'badge-purple' : 'badge-blue'; ?>"><?php echo strtoupper(esc_html($c->broadcast_type)); ?></span></td>
                            <td style="font-size:12px;"><?php echo $ds; ?><br><span style="color:var(--muted);"><?php echo $ts; ?></span></td>
                            <td><?php echo number_format($c->total_sent); ?><?php if ($has_fail): ?><br><span style="color:#dc2626;font-size:11px;">–<?php echo $c->total_failed; ?> failed</span><?php endif; ?></td>
                            <td><?php echo $c->broadcast_type === 'email' ? number_format($c->total_opens) . ($or > 0 ? ' <span style="font-size:10px;color:var(--muted);">(' . $or . '%)</span>' : '') : '—'; ?></td>
                            <td><?php echo $c->broadcast_type === 'email' ? number_format($c->total_clicks) : '—'; ?></td>
                            <td><span class="badge <?php echo $has_fail ? 'badge-yellow' : 'badge-green'; ?>"><?php echo $has_fail ? 'Partial' : 'Done'; ?></span></td>
                            <td>
                                <div style="display:flex;gap:4px;justify-content:flex-end;flex-wrap:wrap;">
                                    <?php if ($c->broadcast_type === 'email'): ?>
                                        <button class="btn btn-ghost btn-sm" onclick="histOpenTracking(<?php echo $c->id; ?>)" title="Tracking Detail">📊</button>
                                        <button class="btn btn-ghost btn-sm" onclick="histViewCampaign(<?php echo $c->id; ?>,'<?php echo esc_js($c->subject); ?>','<?php echo esc_js($c->audience_label); ?>','<?php echo esc_js($ds.' at '.$ts); ?>',<?php echo $c->total_sent; ?>,<?php echo $c->total_failed; ?>)" title="Preview">👁️</button>
                                        <?php if ($has_fail && !empty($c->failed_emails) && $c->failed_emails !== '[]'): ?>
                                            <button class="btn btn-ghost btn-sm" style="color:#f59e0b;" data-failed="<?php echo esc_attr($c->failed_emails); ?>" onclick="histRetry(<?php echo $c->id; ?>,'<?php echo esc_js($c->subject); ?>',this)" title="Retry Failed">🔄</button>
                                        <?php endif; ?>
                                        <button class="btn btn-ghost btn-sm" onclick="histSaveTemplate(<?php echo $c->id; ?>,'<?php echo esc_js($c->subject); ?>')" title="Save as Template">💾</button>
                                    <?php endif; ?>
                                    <button class="btn btn-danger btn-sm" onclick="histDelete(<?php echo $c->id; ?>)" title="Delete">🗑️</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-top:1px solid var(--border);margin-top:4px;">
                <div style="font-size:13px;color:var(--muted);">Showing <span id="hist-page-info">—</span></div>
                <div style="display:flex;gap:6px;">
                    <button class="btn btn-ghost btn-sm" id="hist-prev" onclick="histChangePage(-1)">← Prev</button>
                    <button class="btn btn-ghost btn-sm" id="hist-next" onclick="histChangePage(1)">Next →</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================== SETTINGS TAB ======================== -->
    <div id="tab-settings" class="bs-tab-content">
        <div class="panel">
            <div class="panel-title">⚙️ Broadcast Engine & Gateway Settings</div>
            
            <div class="settings-grid">
                <!-- SECTION 1: EMAIL SENDER IDENTITY -->
                <div class="settings-section">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border);">
                        <span style="font-size:20px;">✉️</span>
                        <div>
                            <h4 style="margin:0;font-size:15px;color:var(--dark);">Email Sender Identity</h4>
                            <span style="font-size:11px;color:var(--muted);">Verified sender details & branded headers</span>
                        </div>
                    </div>

                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:16px;">
                        <label class="lbl" style="font-size:11px;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);">Quick-Load Identity Preset:</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button type="button" class="btn btn-ghost btn-sm" style="background:#fff;border-color:var(--border);" onclick="applySenderPreset('main_church')">🏛️ The King's Assembly (support@)</button>
                            <button type="button" class="btn btn-ghost btn-sm" style="background:#fff;border-color:var(--border);" onclick="applySenderPreset('main_church_relay')">🏛️ TKA Community (Relay)</button>
                            <button type="button" class="btn btn-ghost btn-sm" style="background:#fff;border-color:var(--border);" onclick="applySenderPreset('elevate')">⚡ Elevate TKA (Youth Church)</button>
                        </div>
                    </div>

                    <label class="lbl">Default Active Sender Profile</label>
                    <select id="set-active-sender-profile" style="margin-bottom:16px;font-weight:600;" onchange="applySenderPreset(this.value)">
                        <option value="main_church" <?php selected(get_option('tka_active_sender_profile', 'main_church'), 'main_church'); ?>>🏛️ The King's Assembly (Direct From: support@thekingsassembly.org)</option>
                        <option value="main_church_relay" <?php selected(get_option('tka_active_sender_profile', 'main_church'), 'main_church_relay'); ?>>🏛️ TKA Community (Elevate Relay • Reply: support@thekingsassembly.org)</option>
                        <option value="elevate" <?php selected(get_option('tka_active_sender_profile', 'main_church'), 'elevate'); ?>>⚡ Elevate Church (Elevate TKA • Youth)</option>
                    </select>
                    
                    <label class="lbl">From Name (Display Name)</label>
                    <input type="text" id="set-from-name" value="<?php echo esc_attr(get_option('tka_broadcast_from_name','Elevate TKA')); ?>" placeholder="Elevate TKA">

                    <label class="lbl">From Email (Verified SES Identity)</label>
                    <input type="email" id="set-from-email" value="<?php echo esc_attr(get_option('tka_broadcast_from_email','broadcast@elevate.tkamedia.com')); ?>" placeholder="broadcast@elevate.tkamedia.com">
                    <p style="font-size:11px;color:var(--muted);margin:-12px 0 14px;">Must be a verified identity or domain in your Amazon SES console (e.g. <code>elevate.tkamedia.com</code>).</p>

                    <label class="lbl">Reply-To Email (Member Replies Destination)</label>
                    <input type="email" id="set-reply-to-email" value="<?php echo esc_attr(get_option('tka_broadcast_reply_to','admin@example.com')); ?>" placeholder="admin@example.com">
                    <p style="font-size:11px;color:var(--muted);margin:-12px 0 14px;">When church members click "Reply", their messages arrive at this inbox.</p>

                    <label class="lbl">Email Header Logo URL</label>
                    <input type="text" id="set-logo-url" value="<?php echo esc_attr(get_option('tka_broadcast_logo_url','https://elevate.tkamedia.com/wp-content/uploads/2026/01/ELEVATE-LOGO-1.png')); ?>" class="mb0">
                </div>

                <!-- SECTION 2: EMAIL DISPATCH ENGINE -->
                <div class="settings-section">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border);">
                        <span style="font-size:20px;">⚡</span>
                        <div>
                            <h4 style="margin:0;font-size:15px;color:var(--dark);">Email Dispatch Engine</h4>
                            <span style="font-size:11px;color:var(--muted);">Choose between Direct SES REST API or SMTP</span>
                        </div>
                    </div>

                    <?php
                    $cur_email_engine = get_option('tka_email_engine', 'smtp');
                    ?>
                    <label class="lbl">Active Dispatch Engine</label>
                    <select id="set-email-engine" onchange="switchEmailEngine(this.value)" style="margin-bottom:14px;font-weight:700;color:var(--dark);">
                        <option value="ses_api" <?php selected($cur_email_engine, 'ses_api'); ?>>⚡ Direct Amazon SES REST API v2 (HTTPS — Recommended for Speed)</option>
                        <option value="smtp" <?php selected($cur_email_engine, 'smtp'); ?>>📡 SMTP Relay (Port 587 / TLS)</option>
                    </select>

                    <!-- DIRECT AMAZON SES REST API v2 PANEL -->
                    <div id="engine-panel-ses" style="<?php echo ($cur_email_engine === 'ses_api') ? '' : 'display:none;'; ?>">
                        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;margin-bottom:14px;font-size:12px;color:#166534;line-height:1.5;">
                            ⚡ <strong>Hyper-Speed HTTPS v2 Engine:</strong> Delivers directly to Amazon SES over authenticated AWS SigV4 HTTPS requests. Zero SMTP socket overhead, immune to host port blocks.
                        </div>

                        <?php
                        $cur_ses_region = get_option('tka_ses_region', 'eu-north-1');
                        $cur_ses_access = get_option('tka_ses_access_key', get_option('tka_smtp_user', ''));
                        $cur_ses_secret = get_option('tka_ses_secret_key', '');
                        ?>
                        <label class="lbl">AWS Region</label>
                        <select id="set-ses-region" style="margin-bottom:14px;">
                            <option value="eu-north-1" <?php selected($cur_ses_region, 'eu-north-1'); ?>>Europe (Stockholm eu-north-1) — Elevate TKA</option>
                            <option value="us-east-1" <?php selected($cur_ses_region, 'us-east-1'); ?>>US East (N. Virginia us-east-1)</option>
                            <option value="eu-west-1" <?php selected($cur_ses_region, 'eu-west-1'); ?>>Europe (Ireland eu-west-1)</option>
                            <option value="eu-central-1" <?php selected($cur_ses_region, 'eu-central-1'); ?>>Europe (Frankfurt eu-central-1)</option>
                            <option value="us-west-2" <?php selected($cur_ses_region, 'us-west-2'); ?>>US West (Oregon us-west-2)</option>
                        </select>

                        <label class="lbl">AWS Access Key ID</label>
                        <input type="text" id="set-ses-access-key" value="<?php echo esc_attr($cur_ses_access); ?>" placeholder="AKIA...">

                        <label class="lbl">AWS Secret Access Key</label>
                        <input type="password" id="set-ses-secret-key" value="<?php echo esc_attr($cur_ses_secret); ?>" placeholder="Paste your 40-character AWS Secret Key">
                    </div>

                    <!-- SMTP RELAY PANEL -->
                    <div id="engine-panel-smtp" style="<?php echo ($cur_email_engine === 'smtp') ? '' : 'display:none;'; ?>">
                        <?php
                        $cur_preset = get_option('tka_smtp_preset', 'amazon_eu_north_1');
                        $saved_profiles = get_option('tka_smtp_profiles', []);
                        if (!is_array($saved_profiles)) $saved_profiles = [];

                        $default_profiles = [
                            'amazon_eu_north_1' => [
                                'host'   => 'email-smtp.eu-north-1.amazonaws.com',
                                'port'   => 587,
                                'user'   => get_option('tka_smtp_user', ''),
                                'pass'   => get_option('tka_smtp_pass', ''),
                                'secure' => 'tls',
                            ],
                            'amazon_us_east_1' => [
                                'host'   => 'email-smtp.us-east-1.amazonaws.com',
                                'port'   => 587,
                                'user'   => '',
                                'pass'   => '',
                                'secure' => 'tls',
                            ],
                            'amazon_eu_west_1' => [
                                'host'   => 'email-smtp.eu-west-1.amazonaws.com',
                                'port'   => 587,
                                'user'   => '',
                                'pass'   => '',
                                'secure' => 'tls',
                            ],
                            'gmail' => [
                                'host'   => 'smtp.gmail.com',
                                'port'   => 587,
                                'user'   => get_option('tka_broadcast_from_email', 'admin@example.com'),
                                'pass'   => '',
                                'secure' => 'tls',
                            ],
                            'brevo' => [
                                'host'   => 'smtp-relay.brevo.com',
                                'port'   => 587,
                                'user'   => '',
                                'pass'   => '',
                                'secure' => 'tls',
                            ],
                            'custom' => [
                                'host'   => '',
                                'port'   => 587,
                                'user'   => '',
                                'pass'   => '',
                                'secure' => 'tls',
                            ],
                        ];
                        $smtp_profiles = array_replace_recursive($default_profiles, $saved_profiles);
                        $active_host = get_option('tka_smtp_host', '');
                        if (!empty($active_host) && isset($smtp_profiles[$cur_preset])) {
                            $smtp_profiles[$cur_preset]['host']   = $active_host;
                            $smtp_profiles[$cur_preset]['port']   = intval(get_option('tka_smtp_port', 587));
                            $smtp_profiles[$cur_preset]['user']   = get_option('tka_smtp_user', '');
                            $smtp_profiles[$cur_preset]['pass']   = get_option('tka_smtp_pass', '');
                            $smtp_profiles[$cur_preset]['secure'] = get_option('tka_smtp_secure', 'tls');
                        }
                        $active_prof = $smtp_profiles[$cur_preset] ?? $smtp_profiles['amazon_eu_north_1'];
                        ?>
                        <label class="lbl">SMTP Preset Profile</label>
                        <select id="set-smtp-preset" onchange="applySmtpPreset(this.value)" style="margin-bottom:12px;font-weight:600;color:var(--dark);">
                            <option value="amazon_eu_north_1" <?php selected($cur_preset, 'amazon_eu_north_1'); ?>>⚡ Amazon SES — Europe (Stockholm eu-north-1)</option>
                            <option value="amazon_us_east_1" <?php selected($cur_preset, 'amazon_us_east_1'); ?>>⚡ Amazon SES — US East (N. Virginia us-east-1)</option>
                            <option value="amazon_eu_west_1" <?php selected($cur_preset, 'amazon_eu_west_1'); ?>>⚡ Amazon SES — Europe (Ireland eu-west-1)</option>
                            <option value="gmail" <?php selected($cur_preset, 'gmail'); ?>>📬 Gmail SMTP (smtp.gmail.com)</option>
                            <option value="brevo" <?php selected($cur_preset, 'brevo'); ?>>Brevo / Sendinblue (smtp-relay.brevo.com)</option>
                            <option value="custom" <?php selected($cur_preset, 'custom'); ?>>Custom SMTP Server</option>
                        </select>

                        <label class="lbl">SMTP Host</label>
                        <input type="text" id="set-smtp-host" value="<?php echo esc_attr($active_prof['host']); ?>" placeholder="email-smtp.eu-north-1.amazonaws.com">
                        
                        <label class="lbl">SMTP Port</label>
                        <input type="number" id="set-smtp-port" value="<?php echo esc_attr($active_prof['port']); ?>" placeholder="587">
                        
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <div>
                                <label class="lbl" id="lbl-smtp-user"><?php echo ($cur_preset === 'gmail') ? 'Gmail Address' : 'SMTP Username (Access Key)'; ?></label>
                                <input type="text" id="set-smtp-user" value="<?php echo esc_attr($active_prof['user']); ?>" placeholder="<?php echo ($cur_preset === 'gmail') ? 'admin@example.com' : 'AKIA...'; ?>">
                            </div>
                            <div>
                                <label class="lbl" id="lbl-smtp-pass"><?php echo ($cur_preset === 'gmail') ? 'Google App Password' : 'SMTP Password'; ?></label>
                                <input type="password" id="set-smtp-pass" value="<?php echo esc_attr($active_prof['pass']); ?>" placeholder="<?php echo ($cur_preset === 'gmail') ? '16-character App Password' : 'Paste SMTP password'; ?>">
                            </div>
                        </div>
                        
                        <label class="lbl">Encryption</label>
                        <select id="set-smtp-secure" style="margin-bottom:0;">
                            <option value="tls" <?php selected($active_prof['secure'],'tls'); ?>>TLS (port 587 - Recommended)</option>
                            <option value="ssl" <?php selected($active_prof['secure'],'ssl'); ?>>SSL (port 465)</option>
                            <option value="" <?php selected($active_prof['secure'],''); ?>>None</option>
                        </select>
                    </div>

                    <!-- TEST EMAIL DISPATCH -->
                    <div style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--border);">
                        <label class="lbl" style="font-size:12px;">Test Email Delivery</label>
                        <div style="display:flex;gap:8px;">
                            <input type="email" id="test-smtp-email" placeholder="admin@example.com" class="mb0" style="margin:0;flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" onclick="testSmtp(this)">🧪 Test Email</button>
                        </div>
                        <p style="font-size:11px;color:var(--muted);margin:6px 0 0;">Tests transmission using your currently active engine.</p>
                    </div>
                </div>

                <!-- SECTION 3: MULTI-GATEWAY SMS ENGINE -->
                <div class="settings-section">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border);">
                        <span style="font-size:20px;">📱</span>
                        <div>
                            <h4 style="margin:0;font-size:15px;color:var(--dark);">Multi-Gateway SMS Engine</h4>
                            <span style="font-size:11px;color:var(--muted);">Switch SMS routes anytime with preserved credentials</span>
                        </div>
                    </div>

                    <?php
                    $cur_sms_provider = get_option('tka_sms_provider', 'smart_sms');
                    $smart_token      = get_option('tka_smart_sms_token', '2WvMjOqEksHfWIBFoG6AoxjbT2LpPpoQ14pVsdT00CrY0KWLzb');
                    $smart_sender     = get_option('tka_smart_sms_sender', 'Elevate TKA');
                    $termii_key       = get_option('tka_termii_api_key', '');
                    $termii_sender    = get_option('tka_termii_sender_id', 'Elevate TKA');
                    $holla_user       = get_option('tka_sms_username', 'admin');
                    $holla_pass       = get_option('tka_sms_password', '');
                    $holla_sender     = get_option('tka_sms_sender', 'Elevate TKA');
                    ?>

                    <label class="lbl">Active SMS Gateway</label>
                    <select id="set-sms-provider" onchange="switchSmsProvider(this.value)" style="margin-bottom:14px;font-weight:700;color:var(--dark);">
                        <option value="smart_sms" <?php selected($cur_sms_provider, 'smart_sms'); ?>>🟢 Smart SMS Solutions (Corporate DND Bypass — Recommended ~₦3.50/SMS)</option>
                        <option value="termii" <?php selected($cur_sms_provider, 'termii'); ?>>🟣 Termii (Direct Telco Route)</option>
                        <option value="hollatags" <?php selected($cur_sms_provider, 'hollatags'); ?>>🟠 HollaTags SMS</option>
                    </select>

                    <!-- SMART SMS SOLUTIONS PANEL -->
                    <div id="sms-panel-smart_sms" style="<?php echo ($cur_sms_provider === 'smart_sms') ? '' : 'display:none;'; ?>">
                        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;margin-bottom:14px;font-size:12px;color:#166534;line-height:1.5;">
                            🛡️ <strong>Corporate DND Bypass (Route 3):</strong> Guarantees delivery across all Nigerian networks (MTN, Airtel, Glo, 9mobile) even if member phones have Do-Not-Disturb enabled. Costs only ~₦3.50/SMS.
                        </div>
                        <label class="lbl">Smart SMS API Token</label>
                        <input type="password" id="set-smart-sms-token" value="<?php echo esc_attr($smart_token); ?>" placeholder="Paste Smart SMS Token">

                        <label class="lbl">Approved Sender ID</label>
                        <input type="text" id="set-smart-sms-sender" value="<?php echo esc_attr($smart_sender); ?>" placeholder="Elevate TKA">
                    </div>

                    <!-- TERMII PANEL -->
                    <div id="sms-panel-termii" style="<?php echo ($cur_sms_provider === 'termii') ? '' : 'display:none;'; ?>">
                        <div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:8px;padding:12px;margin-bottom:14px;font-size:12px;color:#6b21a8;line-height:1.5;">
                            ⚡ <strong>Termii Direct Gateway:</strong> High-reliability telco peering with instant real-time delivery reports.
                        </div>
                        <label class="lbl">Termii API Key</label>
                        <input type="password" id="set-termii-api-key" value="<?php echo esc_attr($termii_key); ?>" placeholder="tlv_...">

                        <label class="lbl">Approved Sender ID</label>
                        <input type="text" id="set-termii-sender" value="<?php echo esc_attr($termii_sender); ?>" placeholder="Elevate TKA">
                    </div>

                    <!-- HOLLATAGS PANEL -->
                    <div id="sms-panel-hollatags" style="<?php echo ($cur_sms_provider === 'hollatags') ? '' : 'display:none;'; ?>">
                        <label class="lbl">Username</label>
                        <input type="text" id="set-sms-user" value="<?php echo esc_attr($holla_user); ?>" placeholder="admin">

                        <label class="lbl">Password</label>
                        <input type="password" id="set-sms-pass" value="<?php echo esc_attr($holla_pass); ?>" placeholder="Password">

                        <label class="lbl">Sender ID</label>
                        <input type="text" id="set-sms-sender" value="<?php echo esc_attr($holla_sender); ?>" placeholder="Elevate TKA">
                    </div>

                    <!-- TEST SMS DELIVERY -->
                    <div style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--border);">
                        <label class="lbl" style="font-size:12px;">Test SMS Delivery</label>
                        <div style="display:flex;gap:8px;">
                            <input type="text" id="test-sms-phone" placeholder="08012345678" class="mb0" style="margin:0;flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" onclick="testSms(this)">🧪 Test SMS</button>
                        </div>
                        <p style="font-size:11px;color:var(--muted);margin:6px 0 0;">Sends an instant test SMS through the currently selected gateway.</p>
                    </div>
                </div>

                <!-- SECTION 4: SECURITY -->
                <div class="settings-section">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border);">
                        <span style="font-size:20px;">🔒</span>
                        <div>
                            <h4 style="margin:0;font-size:15px;color:var(--dark);">Studio Access & Security</h4>
                            <span style="font-size:11px;color:var(--muted);">PIN protection for the broadcast studio</span>
                        </div>
                    </div>

                    <label class="lbl">Studio Security PIN</label>
                    <input type="password" id="set-pin" value="" placeholder="Leave blank to keep current PIN">
                    <p style="font-size:12px;color:var(--muted);margin:-10px 0 16px;">Current PIN is set. Default: <strong>1234</strong>.</p>

                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:18px;">
                        <h5 style="margin:0 0 6px;color:var(--dark);font-size:13px;">💡 Quick Tip: Applying Settings</h5>
                        <p style="margin:0;font-size:12px;color:var(--muted);line-height:1.5;">Clicking <strong>Save All Settings</strong> instantly updates both your email and SMS infrastructure without restarting the plugin.</p>
                    </div>

                    <button class="btn btn-primary btn-full" onclick="saveSettings()">💾 Save All Settings</button>
                </div>
            </div>

            <!-- COMPREHENSIVE INFRASTRUCTURE & PRICING GUIDE -->
            <div style="margin-top:28px;background:var(--bg);border:1px solid var(--border);border-radius:14px;padding:24px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                    <div>
                        <h4 style="margin:0 0 4px;color:var(--dark);font-size:16px;">🚀 High-Volume Church Broadcast Infrastructure</h4>
                        <p style="font-size:13px;color:var(--muted);margin:0;">Optimized for reliable inbox delivery and transparent carrier billing.</p>
                    </div>
                    <span class="badge badge-green" style="font-size:12px;padding:6px 12px;">✅ Production Grade</span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px;">
                        <h5 style="margin:0 0 8px;color:#1e293b;font-size:14px;">✉️ Amazon SES (Stockholm eu-north-1)</h5>
                        <ul style="font-size:12px;color:var(--muted);line-height:1.7;margin:0;padding-left:18px;">
                            <li><strong>Sending Identity:</strong> <code>broadcast@elevate.tkamedia.com</code> (Verified)</li>
                            <li><strong>Member Replies:</strong> Routed automatically to <code>admin@example.com</code></li>
                            <li><strong>Quota / Speed:</strong> 50,000 emails/day @ 14 emails/sec</li>
                            <li><strong>Ultra-Low Cost:</strong> ~$0.10 per 1,000 sent emails (~₦160 per 1,000 members)</li>
                        </ul>
                    </div>
                    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px;">
                        <h5 style="margin:0 0 8px;color:#1e293b;font-size:14px;">📱 Multi-Gateway Nigerian SMS</h5>
                        <ul style="font-size:12px;color:var(--muted);line-height:1.7;margin:0;padding-left:18px;">
                            <li><strong>Smart SMS Solutions:</strong> Routing 3 (Corporate DND-Bypass) @ ~₦3.50/SMS</li>
                            <li><strong>Termii:</strong> Direct Telco route with API fallback</li>
                            <li><strong>HollaTags:</strong> Legacy bulk SMS integration</li>
                            <li><strong>Tag Personalization:</strong> <code>[First_Name]</code> and <code>[Name]</code> supported</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SENDING OVERLAY -->
    <div class="send-overlay" id="bs-send-overlay">
        <div class="send-card">
            <div style="text-align:center;margin-bottom:20px;">
                <div style="font-size:36px;margin-bottom:8px;" id="bs-overlay-icon">🚀</div>
                <h2 style="margin:0 0 4px;color:var(--dark);font-size:22px;font-weight:800;" id="bs-overlay-title">Sending Broadcast</h2>
                <p style="margin:0;color:var(--muted);font-size:14px;" id="bs-progress-text">Initializing...</p>
            </div>
            <div class="prog-bar-wrap"><div class="prog-bar-fill" id="bs-prog-bar"></div></div>
            <div class="console-log" id="bs-console"></div>
            <button type="button" class="btn btn-primary btn-full" id="bs-close-overlay" style="display:none;margin-top:20px;" onclick="location.reload()">🏠 Return to Dashboard</button>
        </div>
    </div>

    <!-- CAMPAIGN VIEW MODAL -->
    <div class="bs-modal" id="campaign-modal">
        <div class="modal-card">
            <div class="modal-hdr">
                <h2 id="c-modal-subject">Campaign</h2>
                <button class="modal-close" onclick="document.getElementById('campaign-modal').classList.remove('open')">×</button>
            </div>
            <div style="padding:14px 24px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;gap:20px;flex-wrap:wrap;font-size:13px;color:var(--muted);font-weight:600;">
                <span id="c-modal-date"></span><span id="c-modal-audience"></span>
                <span id="c-modal-sent" style="color:#10b981;"></span>
                <span id="c-modal-failed" style="color:#dc2626;"></span>
            </div>
            <div class="modal-body">
                <iframe id="c-modal-iframe" style="width:100%;height:420px;border:1px solid var(--border);border-radius:10px;background:#fff;"></iframe>
            </div>
        </div>
    </div>

    <!-- TRACKING MODAL -->
    <div class="bs-modal" id="tracking-modal">
        <div class="modal-card">
            <div class="modal-hdr">
                <h2>📊 Tracking Detail</h2>
                <button class="modal-close" onclick="document.getElementById('tracking-modal').classList.remove('open')">×</button>
            </div>
            <div class="modal-body">
                <div id="tracking-modal-body"><p style="color:var(--muted);">Loading...</p></div>
            </div>
        </div>
    </div>

    <!-- EDIT CONTACT MODAL -->
    <div class="bs-modal" id="edit-contact-modal">
        <div class="modal-card" style="max-width:420px;">
            <div class="modal-hdr"><h2>✏️ Edit Contact</h2><button class="modal-close" onclick="document.getElementById('edit-contact-modal').classList.remove('open')">×</button></div>
            <div class="modal-body" style="background:#fff;">
                <input type="hidden" id="ec-id">
                <label class="lbl">Full Name</label><input type="text" id="ec-name">
                <label class="lbl">Email</label><input type="email" id="ec-email">
                <label class="lbl">Phone</label><input type="text" id="ec-phone" placeholder="080...">
                <button class="btn btn-primary btn-full" onclick="abSaveContact()">💾 Update Contact</button>
            </div>
        </div>
    </div>

    <!-- IMPORT CONTACTS MODAL -->
    <div class="bs-modal" id="ab-import-modal">
        <div class="modal-card" style="max-width:680px;">
            <div class="modal-hdr">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:24px;">📥</span>
                    <div>
                        <h2 style="margin:0;font-size:18px;">Import Contacts &amp; Groups</h2>
                        <p style="margin:0;font-size:12px;color:var(--muted);">Import bulk contacts from Excel, Google Sheets, or CSV.</p>
                    </div>
                </div>
                <button class="modal-close" onclick="document.getElementById('ab-import-modal').classList.remove('open')">×</button>
            </div>
            <div class="modal-body" style="background:#fff;padding:26px;">
                <div style="margin-bottom:18px;">
                    <label class="lbl">Assign to Group</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" id="ab-modal-group" placeholder="Type group name (e.g. Choir, First Timers, Workers...)" style="margin-bottom:0;flex:1;">
                        <select id="ab-modal-existing-group" onchange="if(this.value)document.getElementById('ab-modal-group').value=this.value;" style="margin-bottom:0;max-width:180px;">
                            <option value="">Choose existing...</option>
                            <?php foreach($address_book_groups as $g): ?>
                                <option value="<?php echo esc_attr($g); ?>"><?php echo esc_html($g); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Import Method Tabs -->
                <div class="pill-group" style="margin-bottom:16px;">
                    <label class="pill-opt"><input type="radio" name="ab-modal-method" value="paste" checked onclick="abToggleModalMethod('paste')">📋 Copy &amp; Paste (Spreadsheet)</label>
                    <label class="pill-opt"><input type="radio" name="ab-modal-method" value="file" onclick="abToggleModalMethod('file')">📁 Upload CSV File</label>
                </div>

                <div id="ab-modal-paste-area">
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:12px;color:#475569;line-height:1.6;">
                        💡 <strong>Accepted Formats (Auto-Detects Phone &amp; Email):</strong><br>
                        • <strong>Phone only:</strong> <code>08012345678</code><br>
                        • <strong>Name &amp; Phone:</strong> <code>John Doe, 08012345678</code><br>
                        • <strong>Full details:</strong> <code>John Doe, john@example.com, 08012345678</code><br>
                        <span style="color:#0ea5e9;">Tip: Copy phone numbers or contact columns directly from Excel, CSV, or WhatsApp and paste them below!</span>
                    </div>
                    <textarea id="ab-modal-raw-text" rows="8" style="font-family:monospace;font-size:13px;line-height:1.5;margin-bottom:10px;" placeholder="08074272063&#10;Brother Samuel, 08157389769&#10;Joy James, joy@example.com, 08098765432" oninput="abPreviewParseCount()"></textarea>
                    <div id="ab-modal-detected-count" style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:16px;">0 contacts ready to import</div>
                </div>

                <div id="ab-modal-file-area" style="display:none;margin-bottom:16px;">
                    <div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:12px;padding:32px;text-align:center;">
                        <div style="font-size:36px;margin-bottom:8px;">📄</div>
                        <div style="font-weight:600;color:var(--dark);margin-bottom:4px;">Upload CSV or TXT file</div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:16px;">Columns: Name, Email, Phone (in any order)</div>
                        <input type="file" id="ab-modal-file" accept=".csv,.txt" style="max-width:280px;margin:0 auto;display:block;">
                    </div>
                </div>

                <button type="button" class="btn btn-primary btn-full" id="ab-modal-submit-btn" onclick="abRunModalImport()">💾 Save Group &amp; Import Contacts</button>
            </div>
        </div>
    </div>

    <!-- ADD SINGLE CONTACT MODAL -->
    <div class="bs-modal" id="ab-add-contact-modal">
        <div class="modal-card" style="max-width:440px;">
            <div class="modal-hdr">
                <h2>👤 Add New Contact</h2>
                <button class="modal-close" onclick="document.getElementById('ab-add-contact-modal').classList.remove('open')">×</button>
            </div>
            <div class="modal-body" style="background:#fff;padding:24px;">
                <label class="lbl">Full Name</label>
                <input type="text" id="ac-name" placeholder="e.g. Joy James">

                <label class="lbl">Phone Number (For SMS Broadcast)</label>
                <input type="text" id="ac-phone" placeholder="e.g. 08012345678">

                <label class="lbl">Email Address (For Email Broadcast)</label>
                <input type="email" id="ac-email" placeholder="e.g. joy@example.com">

                <p style="font-size:11px;color:var(--muted);margin:-12px 0 16px;">* Enter at least a Phone Number or an Email Address.</p>

                <label class="lbl">Group</label>
                <input type="text" id="ac-group" placeholder="e.g. Choir, General..." list="ac-group-list">
                <datalist id="ac-group-list">
                    <?php foreach($address_book_groups as $g): ?>
                        <option value="<?php echo esc_attr($g); ?>">
                    <?php endforeach; ?>
                </datalist>

                <button type="button" class="btn btn-primary btn-full" onclick="abSaveNewContact()">💾 Save Contact</button>
            </div>
        </div>
    </div>

    <script>
    // ===================== CHART =====================
    const chartData = <?php echo $chart_json; ?>;
    const chartEl   = document.getElementById('dash-chart');
    if (chartEl && chartData.length) {
        const maxSent = Math.max(...chartData.map(d => d.sent), 1);
        chartData.forEach(d => {
            const col = document.createElement('div');
            col.className = 'chart-col';
            const sentH  = Math.max(4, Math.round((d.sent  / maxSent) * 120));
            const opensH = Math.max(0, Math.round((d.opens / maxSent) * 120));
            col.innerHTML = `<div class="chart-bar-group">
                <div class="chart-bar" style="height:${sentH}px;background:#E63946;flex:1;" title="Sent: ${d.sent}"></div>
                <div class="chart-bar" style="height:${opensH}px;background:#10b981;flex:1;" title="Opens: ${d.opens}"></div>
            </div><div class="chart-lbl">${d.date}</div>`;
            chartEl.appendChild(col);
        });
    }

    // ===================== TABS =====================
    function bsTab(id) {
        document.querySelectorAll('.bs-tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.bs-tab').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + id).classList.add('active');
        const btn = document.querySelector(`.bs-tab[onclick*="${id}"]`);
        if (btn) btn.classList.add('active');
        if (id === 'sms' && (!smsCurrentList || !smsCurrentList.length)) smsBuildList();
    }

    // ===================== EMAIL EDITOR =====================
    let bsEditorMode = 'visual';
    const bsVisual = document.getElementById('bs-visual-editor');
    const bsCode   = document.getElementById('bs-code-editor');
    function bsToggleEditor(mode) {
        if (mode === bsEditorMode) return;
        if (mode === 'code') {
            bsCode.value = bsVisual.innerHTML.replace(/></g, '>\n<').trim();
            bsVisual.style.display = 'none'; bsCode.style.display = 'block';
            document.getElementById('bs-btn-code').classList.add('active');
            document.getElementById('bs-btn-visual').classList.remove('active');
        } else {
            bsVisual.innerHTML = bsCode.value;
            bsCode.style.display = 'none'; bsVisual.style.display = 'block';
            document.getElementById('bs-btn-visual').classList.add('active');
            document.getElementById('bs-btn-code').classList.remove('active');
        }
        bsEditorMode = mode;
    }
    function bsGetContent() { return bsEditorMode === 'visual' ? bsVisual.innerHTML : bsCode.value; }

    // ===================== TEMPLATES =====================
    const bsTplSel = document.getElementById('bs-tpl-selector');
    const bsDelTpl = document.getElementById('bs-del-tpl');
    function bsLoadTemplate(sel) {
        if (!sel.value) { bsDelTpl.style.display='none'; return; }
        bsDelTpl.style.display='inline-flex';
        if (confirm("Load this template? It will overwrite your current design.")) {
            bsVisual.innerHTML = sel.value; bsCode.value = sel.value;
        } else { sel.selectedIndex = 0; bsDelTpl.style.display='none'; }
    }
    function bsSaveTemplate() {
        const c = bsGetContent();
        if (!c.trim()) { alert("Design is empty."); return; }
        const name = prompt("Template name:");
        if (!name) return;
        jQuery.post(ajaxurl, { action:'tka_save_template', template_name:name, content:c }, r => {
            if (r.success) { alert("Saved!"); location.reload(); } else alert("Error.");
        });
    }
    function bsDeleteTemplate() {
        const opt = bsTplSel.options[bsTplSel.selectedIndex];
        const id  = opt.getAttribute('data-id');
        if (!id) return;
        if (confirm("Delete template \"" + opt.text + "\"?")) {
            jQuery.post(ajaxurl, { action:'tka_delete_template', template_id:id }, r => {
                if (r.success) location.reload(); else alert("Error.");
            });
        }
    }
    function histSaveTemplate(id, name) {
        const raw = document.getElementById('hist-msg-' + id).innerHTML;
        if (!raw || raw.includes('Content not stored')) { alert("Content not available."); return; }
        const tName = prompt("Save as template:", name + " Template");
        if (!tName) return;
        jQuery.post(ajaxurl, { action:'tka_save_template', template_name:tName, content:raw }, r => {
            if (r.success) { alert("Template saved!"); location.reload(); } else alert("Error.");
        });
    }

    // ===================== ATTACHMENT =====================
    document.getElementById('bs-attachment').addEventListener('change', function(e) {
        const disp = document.getElementById('bs-file-name');
        disp.textContent = e.target.files.length ? e.target.files[0].name : 'No file chosen';
        disp.style.color = e.target.files.length ? '#0f172a' : '#94a3b8';
        disp.style.fontWeight = e.target.files.length ? '600' : 'normal';
    });

    // ===================== EMAIL AUDIENCE =====================
    function bsAudienceMode(mode) {
        document.getElementById('bs-direct-area').style.display  = mode === 'direct' ? 'block' : 'none';
        document.getElementById('bs-filter-area').style.display  = mode === 'filter' ? 'block' : 'none';
        document.getElementById('bs-list-area').style.display    = mode === 'direct' ? 'none'  : 'block';
        if (mode === 'filter') {
            document.getElementById('bs-search').focus();
            bsApplyFilters();
        } else if (mode === 'all') {
            const sInput = document.getElementById('bs-search');
            const fsSelect = document.getElementById('bs-filter-source');
            const fgSelect = document.getElementById('bs-filter-group');
            const fdInput  = document.getElementById('bs-filter-date');
            if (sInput) sInput.value = '';
            if (fsSelect) fsSelect.value = '';
            if (fgSelect) fgSelect.value = '';
            if (fdInput) fdInput.value = '';
            document.querySelectorAll('#bs-list-area .check-item').forEach(item => {
                item.style.display = 'flex';
                const cb = item.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = true;
            });
            const selAll = document.getElementById('bs-select-all');
            if (selAll) selAll.checked = true;
            bsUpdateCount();
        } else {
            bsUpdateCount();
        }
    }

    function bsApplyFilters() {
        const s  = (document.getElementById('bs-search')?.value || '').toLowerCase().trim();
        const fs = document.getElementById('bs-filter-source')?.value || '';
        const fg = document.getElementById('bs-filter-group')?.value || '';
        const fd = document.getElementById('bs-filter-date')?.value || '';
        let visibleCount = 0;
        let checkedCount = 0;

        document.querySelectorAll('#bs-list-area .check-item').forEach(item => {
            const nm      = item.getAttribute('data-name') || '';
            const em      = item.getAttribute('data-email') || '';
            const src     = item.getAttribute('data-source') || '';
            const ft      = item.getAttribute('data-first') || '';
            const ftToday = item.getAttribute('data-first-today') || '';
            const dt          = item.getAttribute('data-date') || '';
            const mem         = item.getAttribute('data-member') || '';
            const grp         = item.getAttribute('data-group') || '';
            const td          = item.getAttribute('data-today') || '';
            const isReturning = item.getAttribute('data-today-returning') || 'no';
            let show = true;

            if (s  && !nm.includes(s) && !em.includes(s)) show = false;
            if (fs && src !== fs) show = false;
            if (fd && dt !== fd) show = false;

            if (fg === 'today-returning' && isReturning !== 'yes') show = false;
            else if (fg === 'today' && isReturning !== 'yes') show = false;
            else if (fg === 'today-all' && td !== 'today') show = false;
            else if (fg === 'first-timer-today' && ftToday !== 'today') show = false;
            else if (fg === 'first-timer-all' && ft !== 'first-timer') show = false;
            else if (fg === 'first-timer' && ftToday !== 'today') show = false;
            else if (fg === 'member' && mem !== 'member') show = false;
            else if (fg.startsWith('ab_') && grp !== fg.replace('ab_', '')) show = false;

            item.style.display = show ? 'flex' : 'none';
            const cb = item.querySelector('input[type="checkbox"]');
            if (show) {
                cb.checked = true;
                visibleCount++;
                checkedCount++;
            } else {
                cb.checked = false;
            }
        });

        const selectAllCb = document.getElementById('bs-select-all');
        if (selectAllCb) {
            selectAllCb.checked = (visibleCount > 0 && checkedCount === visibleCount);
        }
        bsUpdateCount();
    }

    function bsToggleAll(src) {
        document.querySelectorAll('#bs-list-area .check-item').forEach(item => {
            if (item.style.display !== 'none') {
                const cb = item.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = src.checked;
            }
        });
        bsUpdateCount();
    }

    function bsToggleRow(e, row) {
        if (e.target && e.target.tagName && e.target.tagName.toLowerCase() === 'input') {
            e.target.blur();
            bsUpdateCount();
            return;
        }
        const cb = row.querySelector('input[type="checkbox"]');
        if (cb) {
            cb.checked = !cb.checked;
            cb.blur();
            bsUpdateCount();
        }
    }

    function bsUpdateCount() {
        const mode = document.querySelector('input[name="bs-audience-mode"]:checked')?.value || 'all';
        let n = 0;
        let totalVisible = 0;
        if (mode === 'direct') {
            const raw = document.getElementById('bs-direct-text')?.value || '';
            n = raw.split("\n").filter(l => l.trim() && l.includes('@')).length;
        } else {
            document.querySelectorAll('#bs-list-area .check-item').forEach(item => {
                if (item.style.display !== 'none') {
                    totalVisible++;
                    const cb = item.querySelector('input[type="checkbox"]');
                    if (cb && cb.checked) n++;
                }
            });
            const selAll = document.getElementById('bs-select-all');
            if (selAll) {
                selAll.checked = (totalVisible > 0 && n === totalVisible);
            }
        }
        const countEl = document.getElementById('bs-count');
        if (countEl) countEl.textContent = n.toLocaleString() + ' selected';
    }

    // ===================== SMS LIST =====================
    const smsData = {
        members: <?php
            $sms_list_members = [];
            foreach ($sms_members as $m) {
                if (!empty($m->phone)) $sms_list_members[] = ['name' => $m->full_name, 'phone' => $m->phone, 'src' => 'Members DB'];
            }
            echo json_encode($sms_list_members);
        ?>,
        addressbook: <?php
            $sms_list_ab = [];
            foreach ($sms_contacts as $c) {
                if (!empty($c->phone)) $sms_list_ab[] = ['name' => $c->full_name, 'phone' => $c->phone, 'src' => !empty($c->group_name) ? $c->group_name : 'General'];
            }
            echo json_encode($sms_list_ab);
        ?>
    };
    let smsCurrentList = [];
    let smsSelectedSet = new Set();
    let smsFilterQuery = '';
    let smsPreviewLimit = 80;

    function smsBuildList() {
        const srcEl = document.querySelector('input[name="sms-src"]:checked');
        const src = srcEl ? srcEl.value : 'addressbook';
        const groupWrap = document.getElementById('sms-group-select-wrap');
        const groupSelect = document.getElementById('sms-group-select');
        const directArea = document.getElementById('sms-direct-area');
        const listContainer = document.getElementById('sms-list-container');

        if (src === 'direct') {
            if (groupWrap) groupWrap.style.display = 'none';
            if (listContainer) listContainer.style.display = 'none';
            if (directArea) directArea.style.display = 'block';
            smsUpdateDirectCount();
            return;
        }

        if (directArea) directArea.style.display = 'none';
        if (listContainer) listContainer.style.display = 'block';

        if (src === 'addressbook') {
            if (groupWrap) groupWrap.style.display = 'block';
            const selectedGroup = groupSelect ? groupSelect.value : '';
            if (selectedGroup) {
                // Filter Address Book to ONLY contacts belonging to the chosen Phone Book
                smsCurrentList = smsData.addressbook.filter(c => c.src === selectedGroup);
            } else {
                smsCurrentList = smsData.addressbook;
            }
        } else if (src === 'members') {
            if (groupWrap) groupWrap.style.display = 'none';
            smsCurrentList = smsData.members;
        } else if (src === 'all') {
            if (groupWrap) groupWrap.style.display = 'none';
            smsCurrentList = [...smsData.members, ...smsData.addressbook];
        }

        // Select all by default
        smsSelectedSet = new Set(smsCurrentList.map((_, i) => i));
        smsFilterQuery = '';
        smsPreviewLimit = 80;
        const searchInput = document.getElementById('sms-search');
        if (searchInput) searchInput.value = '';
        smsRender();
    }

    function smsSelectAll(state) {
        if (state) {
            smsSelectedSet = new Set(smsCurrentList.map((_, i) => i));
        } else {
            smsSelectedSet.clear();
        }
        smsRender();
    }

    function smsParseDirectNumbers(text) {
        let out = [];
        if (!text) return out;
        text.split(/\r?\n/).forEach(line => {
            line = line.trim();
            if (!line) return;
            const parts = line.split(/[,;\t]/).map(p => p.trim());
            let phone = '', name = 'Friend';
            if (parts.length >= 2) {
                if (/\d{7,}/.test(parts[0])) { phone = parts[0]; name = parts[1]; }
                else { name = parts[0]; phone = parts[1]; }
            } else {
                phone = parts[0];
            }
            phone = phone.replace(/[^0-9+]/g, '');
            if (phone.length >= 7) {
                out.push({ name: name, phone: phone });
            }
        });
        return out;
    }

    function smsUpdateDirectCount() {
        const raw = document.getElementById('sms-direct-numbers') ? document.getElementById('sms-direct-numbers').value : '';
        const parsed = smsParseDirectNumbers(raw);
        const countEl = document.getElementById('sms-direct-count');
        const topCount = document.getElementById('sms-count');
        if (countEl) countEl.textContent = parsed.length.toLocaleString() + ' valid numbers entered';
        if (topCount) topCount.textContent = parsed.length.toLocaleString() + ' selected';
    }

    function smsSendToActiveGroup() {
        if (!abActiveGroup) return;
        bsTab('sms');
        const radio = document.querySelector('input[name="sms-src"][value="addressbook"]');
        if (radio) radio.checked = true;
        const sel = document.getElementById('sms-group-select');
        if (sel) sel.value = abActiveGroup;
        smsBuildList();
    }

    function smsShowMore() {
        smsPreviewLimit += 100;
        smsRender();
    }

    function smsRender() {
        const body = document.getElementById('sms-list-body');
        if (!body) return;

        if (!smsCurrentList.length) {
            body.innerHTML = '<div style="padding:28px 16px;text-align:center;color:var(--muted);"><p style="margin:0 0 12px;font-size:13px;">No phone numbers found in this phone book.</p><button type="button" class="btn btn-ghost btn-sm" onclick="abSyncMembers(this)">🔄 Sync Members DB to Address Book</button></div>';
            smsUpdateCount();
            return;
        }

        const q = smsFilterQuery.toLowerCase().trim();
        let visibleIndices = [];
        for (let i = 0; i < smsCurrentList.length; i++) {
            const p = smsCurrentList[i];
            const name = p.name || '';
            const phone = p.phone || '';
            if (!q || name.toLowerCase().includes(q) || phone.toLowerCase().includes(q)) {
                visibleIndices.push(i);
            }
        }

        if (!visibleIndices.length) {
            body.innerHTML = '<div style="padding:24px;text-align:center;color:var(--muted);font-size:13px;">No contacts match your search.</div>';
            smsUpdateCount();
            return;
        }

        // Fast preview limit to prevent browser DOM freezing
        const pageIndices = visibleIndices.slice(0, smsPreviewLimit);

        const html = pageIndices.map(i => {
            const p = smsCurrentList[i];
            const name = p.name || 'Friend';
            const phone = p.phone || '';
            const isChecked = smsSelectedSet.has(i);
            const initial = name.charAt(0).toUpperCase() || 'M';
            return `
            <label class="check-item sms-item" style="display:flex;cursor:pointer;">
                <input type="checkbox" class="sms-check" value="${i}" ${isChecked ? 'checked' : ''} onchange="smsToggleItem(${i}, this.checked)">
                <div class="ci-avatar">${initial}</div>
                <div style="overflow:hidden;">
                    <div class="ci-name">${name} <span class="src-tag">${p.src || 'SMS'}</span></div>
                    <div class="ci-meta">📱 ${phone}</div>
                </div>
            </label>`;
        }).join('');

        let extraNote = '';
        if (visibleIndices.length > smsPreviewLimit) {
            extraNote = `<div style="padding:14px;text-align:center;background:#f8fafc;border-top:1px solid #e2e8f0;">
                <div style="font-size:12px;color:var(--muted);margin-bottom:8px;">
                    Showing <strong>${Math.min(smsPreviewLimit, visibleIndices.length).toLocaleString()}</strong> of <strong>${visibleIndices.length.toLocaleString()}</strong> contacts (${smsSelectedSet.size.toLocaleString()} selected).
                </div>
                <button type="button" class="btn btn-ghost btn-sm" onclick="smsShowMore()" style="font-size:11px;padding:5px 14px;">⬇️ Load 100 More</button>
            </div>`;
        }

        body.innerHTML = html + extraNote;
        smsUpdateCount();
    }

    function smsToggleItem(idx, checked) {
        if (checked) smsSelectedSet.add(idx);
        else smsSelectedSet.delete(idx);
        smsUpdateCount();
    }

    function smsFilter() {
        const input = document.getElementById('sms-search');
        smsFilterQuery = input ? input.value : '';
        smsPreviewLimit = 80;
        smsRender();
    }

    function smsToggleAll(masterCheckbox) {
        const q = smsFilterQuery.toLowerCase().trim();
        for (let i = 0; i < smsCurrentList.length; i++) {
            const p = smsCurrentList[i];
            const name = p.name || '';
            const phone = p.phone || '';
            if (!q || name.toLowerCase().includes(q) || phone.toLowerCase().includes(q)) {
                if (masterCheckbox.checked) smsSelectedSet.add(i);
                else smsSelectedSet.delete(i);
            }
        }
        smsRender();
    }

    function smsUpdateCount() {
        const countEl = document.getElementById('sms-count');
        if (countEl) {
            countEl.textContent = smsSelectedSet.size.toLocaleString() + ' selected';
        }
        const selectAll = document.getElementById('sms-select-all');
        if (selectAll) {
            selectAll.checked = (smsCurrentList.length > 0 && smsSelectedSet.size === smsCurrentList.length);
        }
    }
    document.getElementById('sms-message').addEventListener('input', function() {
        document.getElementById('sms-char-count').textContent = this.value.length + '/160 (×' + Math.ceil(this.value.length / 160) + ')';
    });

    // ===================== OVERLAY HELPERS =====================
    function bsShowOverlay(title, icon='🚀') {
        document.getElementById('bs-overlay-title').textContent = title;
        document.getElementById('bs-overlay-icon').textContent  = icon;
        document.getElementById('bs-prog-bar').style.width      = '0%';
        document.getElementById('bs-progress-text').textContent = 'Initializing...';
        document.getElementById('bs-close-overlay').style.display = 'none';
        document.getElementById('bs-console').innerHTML = '<div class="log-info">> System ready. Preparing batches...</div>';
        document.getElementById('bs-send-overlay').style.display = 'flex';
    }
    function bsLog(text, cls='') {
        const el = document.getElementById('bs-console');
        const d  = document.createElement('div');
        d.className = cls; d.textContent = text;
        el.appendChild(d); el.scrollTop = el.scrollHeight;
    }

    // ===================== SENDER PROFILES & SWITCHER =====================
    const tkaSenderProfiles = <?php echo json_encode(tka_get_sender_profiles()); ?>;
    let tkaActiveSenderKey = <?php echo json_encode(get_option('tka_active_sender_profile', 'main_church')); ?>;

    function bsSelectSenderProfile(key) {
        tkaActiveSenderKey = key;
        document.querySelectorAll('.bs-sender-pill').forEach(p => p.classList.remove('active'));
        const pill = document.getElementById('bs-pill-sender-' + key);
        if (pill) pill.classList.add('active');

        const drawer = document.getElementById('bs-sender-details-drawer');
        if (key === 'custom') {
            if (drawer) drawer.style.display = 'block';
            return;
        }

        const prof = tkaSenderProfiles[key];
        if (prof) {
            const nameEl  = document.getElementById('bs-sender-from-name');
            const emailEl = document.getElementById('bs-sender-from-email');
            const replyEl = document.getElementById('bs-sender-reply-to');
            const logoEl  = document.getElementById('bs-sender-logo-url');

            if (nameEl)  nameEl.value  = prof.from_name || '';
            if (emailEl) emailEl.value = prof.from_email || '';
            if (replyEl) replyEl.value = prof.reply_to || '';
            if (logoEl)  logoEl.value  = prof.logo_url || '';

            bsUpdateSenderLivePreview();
        }
    }

    function bsUpdateSenderLivePreview() {
        const fn = document.getElementById('bs-sender-from-name')?.value || 'TKA Community';
        const fe = document.getElementById('bs-sender-from-email')?.value || 'support@thekingsassembly.org';
        const rt = document.getElementById('bs-sender-reply-to')?.value || fe;

        const nameEl  = document.getElementById('bs-sender-display-name');
        const emailEl = document.getElementById('bs-sender-display-email');
        if (nameEl) nameEl.textContent = fn;
        if (emailEl) {
            emailEl.innerHTML = bsEscHtml(fe) + ' &bull; Reply-To: <span id="bs-sender-display-reply">' + bsEscHtml(rt) + '</span>';
        }
    }

    function bsToggleSenderDetails() {
        const d = document.getElementById('bs-sender-details-drawer');
        if (!d) return;
        d.style.display = (d.style.display === 'none' || !d.style.display) ? 'block' : 'none';
    }

    function bsEscHtml(s) {
        if (!s) return '';
        return String(s).replace(/[&<>"']/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[m]));
    }

    function applySenderPreset(key) {
        const prof = tkaSenderProfiles[key];
        if (!prof) return;
        if (document.getElementById('set-from-name')) document.getElementById('set-from-name').value = prof.from_name || '';
        if (document.getElementById('set-from-email')) document.getElementById('set-from-email').value = prof.from_email || '';
        if (document.getElementById('set-reply-to-email')) document.getElementById('set-reply-to-email').value = prof.reply_to || '';
        if (document.getElementById('set-logo-url')) document.getElementById('set-logo-url').value = prof.logo_url || '';
        if (document.getElementById('set-active-sender-profile')) document.getElementById('set-active-sender-profile').value = key;
    }

    function bsQuickTestSender(btn) {
        const fromName  = document.getElementById('bs-sender-from-name') ? document.getElementById('bs-sender-from-name').value.trim() : 'TKA Community';
        const fromEmail = document.getElementById('bs-sender-from-email') ? document.getElementById('bs-sender-from-email').value.trim() : 'support@thekingsassembly.org';
        const replyTo   = document.getElementById('bs-sender-reply-to') ? document.getElementById('bs-sender-reply-to').value.trim() : fromEmail;
        const logoUrl   = document.getElementById('bs-sender-logo-url') ? document.getElementById('bs-sender-logo-url').value.trim() : '';

        const defaultTest = "<?php echo esc_js(get_option('admin_email', 'elelegideon@gmail.com')); ?>";
        const testEmail = prompt("Send a quick 1-email test to verify this sender profile.\n\nEnter recipient email address:", defaultTest);
        if (!testEmail || !testEmail.trim()) return;

        const origHtml = btn.innerHTML;
        btn.innerHTML = '⏳ Testing...';
        btn.disabled = true;

        jQuery.post(ajaxurl, {
            action: 'tka_quick_test_sender',
            test_email: testEmail.trim(),
            from_name: fromName,
            from_email: fromEmail,
            reply_to: replyTo,
            logo_url: logoUrl
        }, r => {
            btn.innerHTML = origHtml;
            btn.disabled = false;
            if (r.success) {
                alert("✅ " + r.data);
            } else {
                alert("❌ Delivery Rejected:\n\n" + (r.data || 'Unknown error.'));
            }
        }).fail((xhr, status, error) => {
            btn.innerHTML = origHtml;
            btn.disabled = false;
            alert("❌ Server Connection Error: " + (error || status));
        });
    }

    // ===================== EMAIL SEND ENGINE =====================
    function bsStartSend() {
        const subject = document.getElementById('bs-subject').value.trim();
        if (!subject) { alert("Please enter a subject."); return; }
        const message = bsGetContent();
        if (!message.trim()) { alert("Please write your email message."); return; }
        const mode     = document.querySelector('input[name="bs-audience-mode"]:checked').value;
        const sendMode = document.querySelector('input[name="bs-send-mode"]:checked').value;
        let recipients = [];
        let audLabel   = 'All Contacts';

        const fromName  = document.getElementById('bs-sender-from-name') ? document.getElementById('bs-sender-from-name').value.trim() : '';
        const fromEmail = document.getElementById('bs-sender-from-email') ? document.getElementById('bs-sender-from-email').value.trim() : '';
        const replyTo   = document.getElementById('bs-sender-reply-to') ? document.getElementById('bs-sender-reply-to').value.trim() : '';
        const logoUrl   = document.getElementById('bs-sender-logo-url') ? document.getElementById('bs-sender-logo-url').value.trim() : '';

        const proceed = (finalRecipients, label) => {
            if (!finalRecipients.length) { alert("No valid recipients found!"); return; }
            const senderSummary = fromName ? (fromName + " <" + fromEmail + ">") : fromEmail;
            if (!confirm("Send campaign to " + finalRecipients.length + " recipients?\n\nSender Identity: " + senderSummary)) return;

            if (sendMode === 'later') {
                const at = document.getElementById('bs-scheduled-at').value;
                if (!at) { alert("Please select a schedule date/time."); return; }
                const fileInput = document.getElementById('bs-attachment');
                if (fileInput.files.length) {
                    bsUploadAndSchedule(finalRecipients, subject, message, label, at, fromName, fromEmail, replyTo, logoUrl);
                } else {
                    bsSchedule(finalRecipients, subject, message, label, at, '', '', '', fromName, fromEmail, replyTo, logoUrl);
                }
            } else {
                const fileInput = document.getElementById('bs-attachment');
                if (fileInput.files.length) {
                    bsUploadThenSend(finalRecipients, subject, message, label, fromName, fromEmail, replyTo, logoUrl);
                } else {
                    bsExecuteSend(finalRecipients, subject, message, label, '', '', '', fromName, fromEmail, replyTo, logoUrl);
                }
            }
        };

        if (mode === 'direct') {
            const text    = document.getElementById('bs-direct-text').value;
            const csvFile = document.getElementById('bs-direct-csv').files[0];
            const parse   = (raw) => {
                let out = [];
                raw.split("\n").forEach(line => {
                    line = line.trim(); if(!line) return;
                    const sep = line.includes("\t") ? "\t" : ",";
                    const pts = line.split(sep).map(p => p.trim());
                    let em='', nm='Friend';
                    if (pts.length >= 2) { if(pts[0].includes('@')) { em=pts[0]; nm=pts[1]; } else { nm=pts[0]; em=pts[1]; } } else { em=pts[0]; }
                    em = em.replace(/<|>/g,'');
                    if(em && em.includes('@')) out.push({email:em, name:nm});
                });
                return out;
            };
            if (csvFile) { const r = new FileReader(); r.onload = e => { proceed(parse(e.target.result + "\n" + text), 'Direct Paste'); }; r.readAsText(csvFile); }
            else proceed(parse(text), 'Direct Paste');
        } else {
            document.querySelectorAll('#bs-list-area input[name="recipients[]"]:checked').forEach(el => recipients.push({ email:el.value, name:el.getAttribute('data-realname') }));
            const fg = document.getElementById('bs-filter-group')?.value || '';
            const fd = document.getElementById('bs-filter-date')?.value || '';
            let groupLabel = (mode === 'all') ? 'All Contacts (' + recipients.length + ')' : 'Filtered Audience';
            if (mode === 'filter') {
                if (fg === 'today-returning' || fg === 'today') groupLabel = 'Checked In Members (Returning) (' + recipients.length + ')';
                else if (fg === 'today-all') groupLabel = 'All Checked In Today (' + recipients.length + ')';
                else if (fg === 'first-timer-today') groupLabel = 'First Timers Today (' + recipients.length + ')';
                else if (fg === 'first-timer-all' || fg === 'first-timer') groupLabel = 'First Timers (' + recipients.length + ')';
                else if (fg === 'member') groupLabel = 'Members Only (' + recipients.length + ')';
                else if (fg.startsWith('ab_')) groupLabel = 'Phone Book: ' + fg.replace('ab_', '') + ' (' + recipients.length + ')';
                if (fd) groupLabel += ' [' + fd + ']';
            }
            proceed(recipients, groupLabel);
        }
    }

    function bsUploadThenSend(recipients, subject, message, label, fromName='', fromEmail='', replyTo='', logoUrl='') {
        bsShowOverlay('Sending Broadcast');
        document.getElementById('bs-progress-text').textContent = 'Uploading attachment...';
        const fd = new FormData();
        fd.append('action','tka_upload_broadcast_attachment');
        fd.append('attachment', document.getElementById('bs-attachment').files[0]);
        jQuery.ajax({ url:ajaxurl, type:'POST', data:fd, processData:false, contentType:false,
            success: r => {
                if (r.success) bsExecuteSend(recipients, subject, message, label, r.data.file_path, r.data.file_url, r.data.file_name, fromName, fromEmail, replyTo, logoUrl);
                else { alert("Upload failed: " + r.data); document.getElementById('bs-send-overlay').style.display='none'; }
            },
            error: () => { alert("Upload error."); document.getElementById('bs-send-overlay').style.display='none'; }
        });
    }

    function bsUploadAndSchedule(recipients, subject, message, label, scheduledAt, fromName='', fromEmail='', replyTo='', logoUrl='') {
        const fd = new FormData();
        fd.append('action','tka_upload_broadcast_attachment');
        fd.append('attachment', document.getElementById('bs-attachment').files[0]);
        jQuery.ajax({ url:ajaxurl, type:'POST', data:fd, processData:false, contentType:false,
            success: r => {
                if (r.success) bsSchedule(recipients, subject, message, label, scheduledAt, r.data.file_path, r.data.file_url, r.data.file_name, fromName, fromEmail, replyTo, logoUrl);
                else alert("Upload failed: " + r.data);
            }
        });
    }

    function bsSchedule(recipients, subject, message, label, scheduledAt, attPath, attUrl, attName, fromName='', fromEmail='', replyTo='', logoUrl='') {
        jQuery.post(ajaxurl, { 
            action:'tka_schedule_broadcast', 
            broadcast_type:'email', 
            subject, 
            message, 
            audience:label, 
            recipients, 
            scheduled_at:scheduledAt, 
            attachment_path:attPath, 
            attachment_url:attUrl, 
            attachment_name:attName,
            from_name: fromName,
            from_email: fromEmail,
            reply_to: replyTo,
            logo_url: logoUrl
        }, r => {
            if (r.success) alert("✅ Scheduled for " + new Date(r.data.scheduled_at).toLocaleString() + ". Check the Scheduled tab.");
            else alert("Error scheduling: " + r.data);
        });
    }

    function bsExecuteSend(recipients, subject, message, audLabel, attPath, attUrl, attName, fromName='', fromEmail='', replyTo='', logoUrl='') {
        const totalOriginal = recipients.length;
        const lastSent = prompt("Enter number of contacts to SKIP if resuming a previous broadcast (or 0 to send to all " + totalOriginal + "):", "0");
        let skipped = 0;
        if (lastSent && !isNaN(parseInt(lastSent)) && parseInt(lastSent) > 0) {
            skipped = parseInt(lastSent);
            recipients = recipients.slice(skipped);
        }

        bsShowOverlay('Sending Broadcast');
        if (skipped > 0) {
            bsLog('> Resuming broadcast: Skipped first ' + skipped + ' contacts. ' + recipients.length + ' remaining.', 'log-info');
        }
        if (fromName || fromEmail) {
            bsLog('> Sender Profile: ' + (fromName || 'Default') + ' <' + (fromEmail || '') + '>', 'log-info');
        }

        // First create broadcast record to get ID for tracking
        jQuery.post(ajaxurl, { 
            action:'tka_create_broadcast_record', 
            broadcast_type:'email', 
            subject, 
            message, 
            audience:audLabel,
            from_name: fromName,
            from_email: fromEmail
        }, rec => {
            const broadcastId = rec.success ? rec.data.broadcast_id : 0;
            const total = recipients.length; let sent=0, failed=0, failedEmails=[];
            const BATCH = 25;
            const chunks = [];
            for (let i=0; i<total; i+=BATCH) chunks.push(recipients.slice(i,i+BATCH));

            function sendChunk(idx) {
                if (idx >= chunks.length) {
                    bsLog('\n--- DONE ---','log-info');
                    bsLog('Sent: ' + sent);
                    bsLog('Failed: ' + failed, failed>0?'log-err':'');
                    document.getElementById('bs-progress-text').textContent = 'Finalizing...';
                    jQuery.post(ajaxurl, { action:'tka_save_broadcast_history', broadcast_id:broadcastId, broadcast_type:'email', subject, message, audience:audLabel, sent_count:sent, error_count:failed, failed_emails:failedEmails }, () => {
                        document.getElementById('bs-progress-text').textContent = '✅ Complete!';
                        document.getElementById('bs-close-overlay').style.display = 'block';
                    });
                    return;
                }
                bsLog('> Batch ' + (idx+1) + '/' + chunks.length + ' (' + chunks[idx].length + ' emails)...', 'log-info');
                jQuery.ajax({ url:ajaxurl, type:'POST', timeout:90000,
                    data:{ 
                        action:'tka_send_broadcast_batch', 
                        subject, 
                        message, 
                        batch:chunks[idx], 
                        broadcast_id:broadcastId, 
                        attachment_path:attPath, 
                        attachment_url:attUrl, 
                        attachment_name:attName,
                        from_name: fromName,
                        from_email: fromEmail,
                        reply_to: replyTo,
                        logo_url: logoUrl
                    },
                    success: r => {
                        if (r.success && Array.isArray(r.data)) {
                            r.data.forEach(res => {
                                if (res.status==='success') { bsLog('[OK] '+res.name+' ('+res.email+')'); sent++; }
                                else { bsLog('[FAIL] '+res.name+' ('+res.email+') — '+res.msg,'log-err'); failed++; failedEmails.push(res.name+', '+res.email); }
                            });
                        } else {
                            const err = (r && r.data) ? r.data : 'Rejected by server';
                            bsLog('[CRITICAL] Batch ' + (idx+1) + ' failed: ' + err, 'log-err');
                            failed += chunks[idx].length;
                        }
                    },
                    error: (xhr, status, error) => {
                        let errDetail = 'Server error';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                            errDetail = xhr.responseJSON.data;
                        } else if (xhr && xhr.status) {
                            errDetail = 'HTTP ' + xhr.status + (xhr.statusText ? ' ' + xhr.statusText : '');
                        } else if (error) {
                            errDetail = error;
                        }
                        bsLog('[CRITICAL] Server error on batch ' + (idx+1) + ' (' + errDetail + ')', 'log-err');
                        failed += chunks[idx].length;
                    },
                    complete: () => {
                        const done = Math.min(total, (idx+1)*BATCH);
                        document.getElementById('bs-prog-bar').style.width = ((done/total)*100)+'%';
                        document.getElementById('bs-progress-text').textContent = Math.min(done, total)+' / '+total+' processed...';
                        setTimeout(() => sendChunk(idx+1), 80);
                    }
                });
            }
            sendChunk(0);
        });
    }

    // ===================== SMS SEND ENGINE =====================
    function smsStartSend() {
        const message = document.getElementById('sms-message').value.trim();
        if (!message) { alert("Please enter your SMS message."); return; }

        const srcEl = document.querySelector('input[name="sms-src"]:checked');
        const src = srcEl ? srcEl.value : 'addressbook';
        let recipients = [];
        let audienceLabel = 'SMS Campaign';

        if (src === 'direct') {
            const rawText = document.getElementById('sms-direct-numbers') ? document.getElementById('sms-direct-numbers').value.trim() : '';
            recipients = smsParseDirectNumbers(rawText);
            if (!recipients.length) { alert("Please enter at least one valid phone number."); return; }
            audienceLabel = 'Direct Numbers (' + recipients.length + ')';
        } else {
            if (!smsSelectedSet || !smsSelectedSet.size) { alert("No recipients selected."); return; }
            smsCurrentList.forEach((p, idx) => {
                if (smsSelectedSet.has(idx) && p.phone) {
                    recipients.push({ name: p.name || 'Friend', phone: p.phone });
                }
            });
            if (!recipients.length) { alert("No recipients with valid phone numbers selected."); return; }

            const groupSelect = document.getElementById('sms-group-select');
            if (src === 'addressbook' && groupSelect && groupSelect.value) {
                audienceLabel = 'Phone Book: ' + groupSelect.value;
            } else if (src === 'addressbook') {
                audienceLabel = 'All Phone Books';
            } else if (src === 'members') {
                audienceLabel = 'Members DB';
            } else {
                audienceLabel = 'All Contacts';
            }
        }

        const sendMode = document.querySelector('input[name="sms-send-mode"]:checked').value;

        if (!confirm("Send SMS to " + recipients.length.toLocaleString() + " people (" + audienceLabel + ")?")) return;

        if (sendMode === 'later') {
            const at = document.getElementById('sms-scheduled-at').value;
            if (!at) { alert("Please select a schedule date/time."); return; }
            jQuery.post(ajaxurl, { action:'tka_schedule_broadcast', broadcast_type:'sms', subject:'SMS Campaign', message, audience:audienceLabel, recipients, scheduled_at:at }, r => {
                if (r.success) alert("✅ SMS scheduled for " + new Date(r.data.scheduled_at).toLocaleString());
                else alert("Error: " + r.data);
            });
            return;
        }

        bsShowOverlay('Sending SMS Campaign', '📱');
        jQuery.post(ajaxurl, { action:'tka_create_broadcast_record', broadcast_type:'sms', subject:'SMS Campaign', message, audience:audienceLabel }, rec => {
            const broadcastId = rec.success ? rec.data.broadcast_id : 0;
            const total = recipients.length; let sent=0, failed=0;
            const BATCH = 10;
            const chunks = [];
            for (let i=0; i<total; i+=BATCH) chunks.push(recipients.slice(i,i+BATCH));

            function sendChunk(idx) {
                if (idx >= chunks.length) {
                    bsLog('\n--- SMS DONE ---','log-info');
                    bsLog('Sent: '+sent); bsLog('Failed: '+failed, failed>0?'log-err':'');
                    jQuery.post(ajaxurl, { action:'tka_save_broadcast_history', broadcast_id:broadcastId, broadcast_type:'sms', subject:'SMS Campaign', message, audience:audienceLabel, sent_count:sent, error_count:failed }, () => {
                        document.getElementById('bs-progress-text').textContent = '✅ SMS Complete!';
                        document.getElementById('bs-close-overlay').style.display = 'block';
                    });
                    return;
                }
                bsLog('> SMS Batch '+(idx+1)+'/'+chunks.length+'...','log-info');
                jQuery.ajax({ url:ajaxurl, type:'POST', timeout:60000,
                    data:{ action:'tka_send_sms_batch', message, batch:chunks[idx], broadcast_id:broadcastId },
                    success: r => {
                        if (r.success && Array.isArray(r.data)) {
                            r.data.forEach(res => {
                                if(res.status==='success') { bsLog('[OK] SMS → '+res.name+' ('+res.phone+')'); sent++; }
                                else { bsLog('[FAIL] '+res.name+' ('+res.phone+')', 'log-err'); failed++; }
                            });
                        }
                    },
                    error: () => { bsLog('[CRITICAL] SMS batch error','log-err'); failed+=chunks[idx].length; },
                    complete: () => {
                        const done = Math.min(total,(idx+1)*BATCH);
                        document.getElementById('bs-prog-bar').style.width = ((done/total)*100)+'%';
                        document.getElementById('bs-progress-text').textContent = done+' / '+total+' SMS sent...';
                        setTimeout(() => sendChunk(idx+1), 1500); // 1.5s delay between SMS batches
                    }
                });
            }
            sendChunk(0);
        });
    }

    // ===================== HISTORY =====================
    let histPage = 1; const HIST_PER = 20;
    function histRender() {
        const dateF = document.getElementById('hist-date-filter').value;
        const typeF = document.getElementById('hist-type-filter').value;
        const rows  = [...document.querySelectorAll('.hist-row')];
        let visible = rows.filter(r => {
            const okD = !dateF || r.getAttribute('data-date') === dateF;
            const okT = !typeF || r.getAttribute('data-type') === typeF;
            return okD && okT;
        });
        const total  = visible.length;
        const pages  = Math.ceil(total / HIST_PER) || 1;
        if (histPage > pages) histPage = pages;
        if (histPage < 1)     histPage = 1;
        const start = (histPage-1)*HIST_PER;
        const end   = start + HIST_PER;
        rows.forEach(r => r.style.display='none');
        visible.forEach((r,i) => { r.style.display = (i>=start && i<end) ? '' : 'none'; });
        document.getElementById('hist-page-info').innerHTML = total ? `${start+1}–${Math.min(end,total)} of ${total}` : '0';
        document.getElementById('hist-prev').disabled = histPage<=1;
        document.getElementById('hist-next').disabled = histPage>=pages;
        document.getElementById('hist-sel-all').checked = false;
        histUpdateBulkBtn();
    }
    function histChangePage(d) { histPage+=d; histRender(); }
    function histUpdateBulkBtn() {
        const n = document.querySelectorAll('.hist-check:checked').length;
        const b = document.getElementById('hist-bulk-del');
        b.style.display = n ? 'inline-flex' : 'none';
        if(n) b.textContent = '🗑️ Delete Selected ('+n+')';
    }
    function histToggleAll(src) {
        document.querySelectorAll('.hist-row:not([style*="none"]) .hist-check').forEach(cb => cb.checked=src.checked);
        histUpdateBulkBtn();
    }
    function histDelete(id) {
        if(!confirm("Delete this campaign log?")) return;
        jQuery.post(ajaxurl,{action:'tka_delete_history',history_id:id},r=>{ if(r.success) location.reload(); });
    }
    function histBulkDelete() {
        const ids=[]; document.querySelectorAll('.hist-check:checked').forEach(cb=>ids.push(cb.value));
        if(!ids.length || !confirm("Delete "+ids.length+" logs?")) return;
        jQuery.post(ajaxurl,{action:'tka_bulk_delete_history',history_ids:ids},r=>{ if(r.success) location.reload(); });
    }
    function histViewCampaign(id,subject,audience,dateStr,sent,failed) {
        document.getElementById('c-modal-subject').textContent   = subject;
        document.getElementById('c-modal-audience').textContent  = '🎯 '+audience;
        document.getElementById('c-modal-date').textContent      = '📅 '+dateStr;
        document.getElementById('c-modal-sent').textContent      = '✅ '+sent+' Delivered';
        document.getElementById('c-modal-failed').textContent    = '❌ '+failed+' Failed';
        const raw    = document.getElementById('hist-msg-'+id).innerHTML;
        const iframe = document.getElementById('c-modal-iframe');
        const doc    = iframe.contentWindow.document;
        doc.open(); doc.write(raw); doc.close();
        document.getElementById('campaign-modal').classList.add('open');
    }
    function histOpenTracking(bid) {
        document.getElementById('tracking-modal').classList.add('open');
        document.getElementById('tracking-modal-body').innerHTML = '<p style="color:var(--muted);">Loading tracking data...</p>';
        jQuery.post(ajaxurl,{action:'tka_get_tracking_detail',broadcast_id:bid},r=>{
            if(!r.success){document.getElementById('tracking-modal-body').innerHTML='<p>Error loading data.</p>';return;}
            const {opens,clicks}=r.data;
            let html='<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">';
            html+='<div><h3 style="margin:0 0 12px;color:var(--dark);">👁️ Opens ('+opens.length+')</h3>';
            if(!opens.length) html+='<p style="color:var(--muted);">No opens yet.</p>';
            else opens.forEach(o=>{ html+='<div style="padding:8px 0;border-bottom:1px solid var(--border);font-size:13px;"><strong>'+o.recipient_email+'</strong><br><span style="color:var(--muted);font-size:11px;">'+o.event_time+'</span></div>'; });
            html+='</div><div><h3 style="margin:0 0 12px;color:var(--dark);">🖱️ Clicks ('+clicks.length+')</h3>';
            if(!clicks.length) html+='<p style="color:var(--muted);">No clicks yet.</p>';
            else clicks.forEach(c=>{ html+='<div style="padding:8px 0;border-bottom:1px solid var(--border);font-size:13px;"><strong>'+c.recipient_email+'</strong><br><span style="color:var(--muted);font-size:11px;">'+(c.url||'—')+'</span><br><span style="color:var(--muted);font-size:10px;">'+c.event_time+'</span></div>'; });
            html+='</div></div>';
            document.getElementById('tracking-modal-body').innerHTML = html;
        });
    }
    function histRetry(id,subject,btn) {
        const failedJson = btn.getAttribute('data-failed');
        let failedList=[];
        try { failedList=JSON.parse(failedJson); } catch(e){ return; }
        if(!failedList.length){alert("No failed emails.");return;}
        if(!confirm("Retry "+failedList.length+" failed contacts?")) return;
        const recipients = failedList.map(item => {
            const pts = item.split(',');
            return pts.length>=2 ? {name:pts[0].trim(),email:pts[1].trim()} : {name:'Friend',email:pts[0].trim()};
        });
        const raw = document.getElementById('hist-msg-'+id).innerHTML;
        bsTab('compose');
        bsExecuteSend(recipients, subject, raw, 'Retry: '+subject, '', '', '');
    }

    // ===================== ADDRESS BOOK CRM ENGINE =====================
    const abContactsMaster = <?php echo !empty($ab_contacts_json) ? $ab_contacts_json : '[]'; ?>;
    let abActiveGroup = '';
    let abSearchQuery = '';
    let abPhoneFilter = 'all'; // 'all', 'phone', 'email_only'
    let abCurrentPage = 1;
    let abPerPage     = 25;
    let abSelectedIds = new Set();

    function abEscapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }
    function abEscapeJs(s) {
        if (!s) return '';
        return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
    }
    function abGetAvatarColor(str) {
        const colors = ['#E63946', '#2563eb', '#059669', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#4f46e5', '#ea580c'];
        let hash = 0;
        for (let i = 0; i < (str || '').length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
        return colors[Math.abs(hash) % colors.length];
    }
    function abGetInitials(name) {
        if (!name) return '?';
        const parts = name.trim().split(/\s+/);
        if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        return parts[0].substring(0, 2).toUpperCase();
    }

    function abGetFilteredList() {
        return abContactsMaster.filter(c => {
            if (abActiveGroup && c.group !== abActiveGroup) return false;
            if (abPhoneFilter === 'phone' && !c.phone) return false;
            if (abPhoneFilter === 'email_only' && c.phone) return false;
            if (abSearchQuery) {
                const q = abSearchQuery.toLowerCase();
                const hit = (c.name && c.name.toLowerCase().includes(q)) ||
                            (c.email && c.email.toLowerCase().includes(q)) ||
                            (c.phone && c.phone.toLowerCase().includes(q));
                if (!hit) return false;
            }
            return true;
        });
    }

    function abRender() {
        const filtered = abGetFilteredList();
        const total = filtered.length;
        const totalPages = Math.ceil(total / abPerPage) || 1;
        if (abCurrentPage > totalPages) abCurrentPage = totalPages;
        if (abCurrentPage < 1) abCurrentPage = 1;

        const startIdx = (abCurrentPage - 1) * abPerPage;
        const endIdx = startIdx + abPerPage;
        const pageItems = filtered.slice(startIdx, endIdx);

        // Header Title & Stats
        const titleEl = document.getElementById('ab-active-title');
        const badgeEl = document.getElementById('ab-count-badge');
        const descEl  = document.getElementById('ab-active-desc');
        if (titleEl) titleEl.textContent = abActiveGroup ? abActiveGroup : 'All Contacts';
        if (badgeEl) badgeEl.textContent = total.toLocaleString() + ' contacts';
        if (descEl) {
            const phoneCount = filtered.filter(c => !!c.phone).length;
            descEl.innerHTML = `${total.toLocaleString()} total contacts &nbsp;·&nbsp; ${phoneCount.toLocaleString()} with phone numbers`;
        }

        // Table & Empty State
        const tbody = document.getElementById('ab-table-body');
        const emptyState = document.getElementById('ab-empty-state');
        const tableWrap = tbody ? tbody.closest('.tbl-wrap') : null;

        if (!total) {
            if (tbody) tbody.innerHTML = '';
            if (emptyState) emptyState.style.display = 'block';
            if (tableWrap) tableWrap.style.display = 'none';
        } else {
            if (emptyState) emptyState.style.display = 'none';
            if (tableWrap) tableWrap.style.display = 'block';
            if (tbody) {
                tbody.innerHTML = pageItems.map(c => {
                    const isSelected = abSelectedIds.has(c.id);
                    const color = abGetAvatarColor(c.name);
                    const initials = abGetInitials(c.name);
                    return `
                    <tr class="ab-row-item ${isSelected ? 'selected-row' : ''}" id="ab-row-${c.id}">
                        <td>
                            <input type="checkbox" class="ab-row-check" value="${c.id}" ${isSelected ? 'checked' : ''} onchange="abToggleCheck(${c.id}, this.checked)">
                        </td>
                        <td>
                            <div class="ab-contact-avatar" style="background:${color};">${initials}</div>
                        </td>
                        <td>
                            <div class="ab-contact-name">${abEscapeHtml(c.name)}</div>
                            <div class="ab-contact-email">${abEscapeHtml(c.email)}</div>
                        </td>
                        <td>
                            ${c.phone ? `<span class="ab-contact-phone">📱 ${abEscapeHtml(c.phone)}</span>` : '<span style="color:var(--muted);font-size:12px;">—</span>'}
                        </td>
                        <td>
                            <span class="badge badge-purple" style="cursor:pointer;" onclick="abSelectGroupByName('${abEscapeJs(c.group)}')" title="Filter by ${abEscapeHtml(c.group)}">${abEscapeHtml(c.group)}</span>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:flex;gap:4px;justify-content:flex-end;">
                                <button type="button" class="btn btn-ghost btn-sm" onclick="abEditContact(${c.id}, '${abEscapeJs(c.name)}', '${abEscapeJs(c.email)}', '${abEscapeJs(c.phone)}')" title="Edit Contact">✏️</button>
                                <button type="button" class="btn btn-danger btn-sm" onclick="abDeleteContact(${c.id})" title="Delete Contact">🗑️</button>
                            </div>
                        </td>
                    </tr>`;
                }).join('');
            }
        }

        // Pagination Footer
        const rangeEl = document.getElementById('ab-page-range');
        const pageTotalEl = document.getElementById('ab-page-total');
        const curPageEl = document.getElementById('ab-current-page');
        const prevBtn = document.getElementById('ab-prev-btn');
        const nextBtn = document.getElementById('ab-next-btn');

        if (rangeEl) rangeEl.textContent = total ? `${(startIdx + 1).toLocaleString()}–${Math.min(endIdx, total).toLocaleString()}` : '0–0';
        if (pageTotalEl) pageTotalEl.textContent = total.toLocaleString();
        if (curPageEl) curPageEl.textContent = `${abCurrentPage} / ${totalPages}`;
        if (prevBtn) prevBtn.disabled = abCurrentPage <= 1;
        if (nextBtn) nextBtn.disabled = abCurrentPage >= totalPages;

        // Sync header checkbox
        const allChecked = pageItems.length > 0 && pageItems.every(c => abSelectedIds.has(c.id));
        const selAllBox = document.getElementById('ab-select-all');
        if (selAllBox) selAllBox.checked = allChecked;

        abUpdateBulkBar();
    }

    function abSelectGroup(el, groupName) {
        document.querySelectorAll('.ab-group-item').forEach(item => item.classList.remove('active'));
        if (el) el.classList.add('active');
        abActiveGroup = groupName;

        const manageBar = document.getElementById('ab-group-manage-bar');
        if (manageBar) manageBar.style.display = groupName ? 'block' : 'none';

        abCurrentPage = 1;
        abRender();
    }

    function abSelectGroupByName(groupName) {
        const items = Array.from(document.querySelectorAll('.ab-group-item'));
        const match = items.find(el => el.getAttribute('data-group') === groupName);
        if (match) {
            abSelectGroup(match, groupName);
        } else {
            abActiveGroup = groupName;
            abCurrentPage = 1;
            abRender();
        }
    }

    function abHandleSearch() {
        const input = document.getElementById('ab-search-input');
        abSearchQuery = input ? input.value.trim() : '';
        const clearBtn = document.getElementById('ab-clear-search');
        if (clearBtn) clearBtn.style.display = abSearchQuery ? 'block' : 'none';
        abCurrentPage = 1;
        abRender();
    }

    function abClearSearch() {
        const input = document.getElementById('ab-search-input');
        if (input) input.value = '';
        abSearchQuery = '';
        const clearBtn = document.getElementById('ab-clear-search');
        if (clearBtn) clearBtn.style.display = 'none';
        abCurrentPage = 1;
        abRender();
    }

    function abFilterByPhone(mode, btn) {
        document.querySelectorAll('.ab-seg-btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        abPhoneFilter = mode;
        abCurrentPage = 1;
        abRender();
    }

    function abChangePage(delta) {
        abCurrentPage += delta;
        abRender();
    }

    function abChangePerPage(val) {
        abPerPage = parseInt(val, 10) || 25;
        abCurrentPage = 1;
        abRender();
    }

    function abToggleCheck(id, isChecked) {
        if (isChecked) abSelectedIds.add(id);
        else abSelectedIds.delete(id);
        const row = document.getElementById('ab-row-' + id);
        if (row) row.classList.toggle('selected-row', isChecked);
        abUpdateBulkBar();
    }

    function abToggleSelectAll(masterCb) {
        const filtered = abGetFilteredList();
        const startIdx = (abCurrentPage - 1) * abPerPage;
        const pageItems = filtered.slice(startIdx, startIdx + abPerPage);

        pageItems.forEach(c => {
            if (masterCb.checked) abSelectedIds.add(c.id);
            else abSelectedIds.delete(c.id);
            const row = document.getElementById('ab-row-' + c.id);
            if (row) {
                row.classList.toggle('selected-row', masterCb.checked);
                const cb = row.querySelector('.ab-row-check');
                if (cb) cb.checked = masterCb.checked;
            }
        });
        abUpdateBulkBar();
    }

    function abUpdateBulkBar() {
        const bar = document.getElementById('ab-bulk-bar');
        const countEl = document.getElementById('ab-bulk-count');
        const n = abSelectedIds.size;
        if (bar) bar.style.display = n > 0 ? 'flex' : 'none';
        if (countEl) countEl.textContent = n + ' contact' + (n === 1 ? '' : 's') + ' selected';
    }

    function abBulkDelete() {
        const ids = Array.from(abSelectedIds);
        if (!ids.length) return;
        if (!confirm('Are you sure you want to permanently delete ' + ids.length + ' selected contacts?')) return;
        jQuery.post(ajaxurl, { action: 'tka_bulk_delete_contacts', contact_ids: ids }, r => {
            if (r.success) {
                alert('✅ Selected contacts deleted.');
                location.reload();
            } else {
                alert('Error deleting contacts: ' + (r.data || 'Unknown error'));
            }
        });
    }

    function abBulkMove() {
        const ids = Array.from(abSelectedIds);
        const targetGroup = document.getElementById('ab-bulk-move-target').value;
        if (!ids.length) return;
        if (!targetGroup) { alert('Please select a target group.'); return; }
        if (!confirm('Move ' + ids.length + ' contacts to "' + targetGroup + '"?')) return;
        jQuery.post(ajaxurl, { action: 'tka_bulk_move_contacts', contact_ids: ids, target_group: targetGroup }, r => {
            if (r.success) {
                alert('✅ Contacts moved to ' + targetGroup);
                location.reload();
            } else {
                alert('Error moving contacts: ' + (r.data || 'Unknown error'));
            }
        });
    }

    function abExportCsv() {
        const list = abGetFilteredList();
        if (!list.length) { alert('No contacts to export.'); return; }
        let csv = 'Full Name,Email Address,Phone Number,Group\r\n';
        list.forEach(c => {
            const row = [
                `"${(c.name || '').replace(/"/g, '""')}"`,
                `"${(c.email || '').replace(/"/g, '""')}"`,
                `"${(c.phone || '').replace(/"/g, '""')}"`,
                `"${(c.group || '').replace(/"/g, '""')}"`
            ];
            csv += row.join(',') + '\r\n';
        });
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const fname = (abActiveGroup ? abActiveGroup.replace(/[^a-z0-9]/gi, '_').toLowerCase() : 'all_contacts') + '_' + new Date().toISOString().slice(0, 10) + '.csv';
        link.href = URL.createObjectURL(blob);
        link.setAttribute('download', fname);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function abOpenImportModal() {
        if (abActiveGroup) {
            const modalGrp = document.getElementById('ab-modal-group');
            if (modalGrp) modalGrp.value = abActiveGroup;
        }
        document.getElementById('ab-import-modal').classList.add('open');
    }

    function abToggleModalMethod(m) {
        document.getElementById('ab-modal-paste-area').style.display = m === 'paste' ? 'block' : 'none';
        document.getElementById('ab-modal-file-area').style.display  = m === 'file'  ? 'block' : 'none';
    }

    function abPreviewParseCount() {
        const raw = document.getElementById('ab-modal-raw-text').value;
        const lines = raw.split(/\r?\n/).filter(l => l.trim().length > 0);
        const countEl = document.getElementById('ab-modal-detected-count');
        if (countEl) countEl.textContent = lines.length + ' contact' + (lines.length === 1 ? '' : 's') + ' ready to import';
    }

    function abRunModalImport() {
        const group = document.getElementById('ab-modal-group').value.trim();
        if (!group) { alert('Please enter or select a group name.'); return; }
        const method = document.querySelector('input[name="ab-modal-method"]:checked').value;
        const btn = document.getElementById('ab-modal-submit-btn');
        btn.textContent = 'Importing...'; btn.disabled = true;

        const proceed = (raw) => {
            jQuery.post(ajaxurl, { action: 'tka_bulk_import_contacts', group_name: group, csv_data: raw }, r => {
                if (r.success) {
                    alert(r.data);
                    location.reload();
                } else {
                    alert('Import error: ' + (r.data || 'Unknown error'));
                    btn.textContent = '💾 Save Group & Import Contacts';
                    btn.disabled = false;
                }
            });
        };

        if (method === 'file') {
            const f = document.getElementById('ab-modal-file').files[0];
            if (!f) { alert('Please choose a file to upload.'); btn.textContent = '💾 Save Group & Import Contacts'; btn.disabled = false; return; }
            const reader = new FileReader();
            reader.onload = e => proceed(e.target.result);
            reader.readAsText(f);
        } else {
            const text = document.getElementById('ab-modal-raw-text').value;
            if (!text.trim()) { alert('Please paste your contacts.'); btn.textContent = '💾 Save Group & Import Contacts'; btn.disabled = false; return; }
            proceed(text);
        }
    }

    function abOpenAddContactModal() {
        if (abActiveGroup) {
            const acGrp = document.getElementById('ac-group');
            if (acGrp) acGrp.value = abActiveGroup;
        }
        document.getElementById('ab-add-contact-modal').classList.add('open');
    }

    function abSaveNewContact() {
        const name  = document.getElementById('ac-name').value.trim();
        const email = document.getElementById('ac-email').value.trim();
        const phone = document.getElementById('ac-phone').value.trim();
        const group = document.getElementById('ac-group').value.trim() || 'General';

        if (!email && !phone) { alert('Please enter at least a Phone Number or an Email Address.'); return; }
        jQuery.post(ajaxurl, { action: 'tka_add_single_contact', name: (name || 'Friend'), email, phone, group_name: group }, r => {
            if (r.success) {
                alert('✅ Contact added successfully!');
                location.reload();
            } else {
                alert('Error: ' + (r.data || 'Failed to add contact.'));
            }
        });
    }

    function abSyncMembers(btn) {
        if (!confirm("This will copy all registered church members with phone numbers or emails from the Members DB into your Address Book under the group 'Church Members'. Proceed?")) return;
        const oldText = btn ? btn.textContent : '';
        if (btn) { btn.textContent = 'Syncing...'; btn.disabled = true; }
        jQuery.post(ajaxurl, { action: 'tka_sync_members_to_address_book', group_name: 'Church Members' }, r => {
            if (btn) { btn.textContent = oldText; btn.disabled = false; }
            if (r.success) {
                alert('✅ ' + r.data);
                location.reload();
            } else {
                alert('Error: ' + (r.data || 'Failed to sync members.'));
            }
        });
    }

    function abEditContact(id, name, email, phone) {
        document.getElementById('ec-id').value    = id;
        document.getElementById('ec-name').value  = name;
        document.getElementById('ec-email').value = email;
        document.getElementById('ec-phone').value = phone;
        document.getElementById('edit-contact-modal').classList.add('open');
    }

    function abSaveContact() {
        const id    = document.getElementById('ec-id').value;
        const name  = document.getElementById('ec-name').value;
        const email = document.getElementById('ec-email').value;
        const phone = document.getElementById('ec-phone').value;
        if (!name || !email) { alert('Name and Email are required.'); return; }
        jQuery.post(ajaxurl, { action: 'tka_update_contact', contact_id: id, name, email, phone }, r => {
            if (r.success) location.reload();
            else alert('Error updating contact.');
        });
    }

    function abDeleteContact(id) {
        if (!confirm('Are you sure you want to delete this contact?')) return;
        jQuery.post(ajaxurl, { action: 'tka_delete_contact', contact_id: id }, r => {
            if (r.success) {
                const idx = abContactsMaster.findIndex(c => c.id === id);
                if (idx !== -1) abContactsMaster.splice(idx, 1);
                abSelectedIds.delete(id);
                abRender();
            } else {
                alert('Error deleting contact.');
            }
        });
    }

    function abRenameActiveGroup() {
        if (!abActiveGroup) return;
        const nw = prompt('New name for group "' + abActiveGroup + '":', abActiveGroup);
        if (!nw || nw === abActiveGroup) return;
        jQuery.post(ajaxurl, { action: 'tka_rename_group', old_name: abActiveGroup, new_name: nw }, r => {
            if (r.success) location.reload();
            else alert('Error renaming group.');
        });
    }

    function abDeleteActiveGroup() {
        if (!abActiveGroup) return;
        if (!confirm('Delete group "' + abActiveGroup + '" and ALL its contacts? This cannot be undone.')) return;
        jQuery.post(ajaxurl, { action: 'tka_delete_group', group_name: abActiveGroup }, r => {
            if (r.success) location.reload();
            else alert('Error deleting group.');
        });
    }

    // ===================== SCHEDULED =====================
    function cancelScheduled(id,btn) {
        if(!confirm("Cancel this scheduled broadcast?"))return;
        btn.textContent='Cancelling...'; btn.disabled=true;
        jQuery.post(ajaxurl,{action:'tka_cancel_scheduled',queue_id:id},r=>{
            if(r.success){btn.closest('.sched-item').remove();}
            else{btn.textContent='Cancel';btn.disabled=false;alert("Error cancelling.");}
        });
    }

    function runScheduledNow(id,btn) {
        if(!confirm("Execute and send this scheduled broadcast now?")) return;
        btn.textContent='Sending...'; btn.disabled=true;
        jQuery.post(ajaxurl,{action:'tka_run_scheduled_now',queue_id:id},r=>{
            if(r.success){alert("✅ Broadcast executed successfully!");location.reload();}
            else{btn.textContent='⚡ Send Now';btn.disabled=false;alert(r.data||"Error executing job.");}
        });
    }

    // ===================== SETTINGS & TESTS =====================
    let tkaSmtpProfiles = <?php echo json_encode($smtp_profiles); ?>;
    let tkaActivePreset = <?php echo json_encode($cur_preset); ?>;

    function switchEmailEngine(engine) {
        const sesP  = document.getElementById('engine-panel-ses');
        const smtpP = document.getElementById('engine-panel-smtp');
        if (engine === 'ses_api') {
            if (sesP)  sesP.style.display = 'block';
            if (smtpP) smtpP.style.display = 'none';
        } else {
            if (sesP)  sesP.style.display = 'none';
            if (smtpP) smtpP.style.display = 'block';
        }
    }

    function switchSmsProvider(prov) {
        const panels = {
            'smart_sms': document.getElementById('sms-panel-smart_sms'),
            'termii':    document.getElementById('sms-panel-termii'),
            'hollatags': document.getElementById('sms-panel-hollatags')
        };
        for (let k in panels) {
            if (panels[k]) {
                panels[k].style.display = (k === prov) ? 'block' : 'none';
            }
        }
    }

    function applySmtpPreset(newPreset) {
        const hostEl = document.getElementById('set-smtp-host');
        const portEl = document.getElementById('set-smtp-port');
        const userEl = document.getElementById('set-smtp-user');
        const passEl = document.getElementById('set-smtp-pass');
        const secEl  = document.getElementById('set-smtp-secure');
        const userLbl = document.getElementById('lbl-smtp-user');
        const passLbl = document.getElementById('lbl-smtp-pass');

        // 1. Save current input fields into active preset in-memory profile
        if (tkaActivePreset && tkaSmtpProfiles[tkaActivePreset]) {
            tkaSmtpProfiles[tkaActivePreset].host   = hostEl.value;
            tkaSmtpProfiles[tkaActivePreset].port   = portEl.value;
            tkaSmtpProfiles[tkaActivePreset].user   = userEl.value;
            tkaSmtpProfiles[tkaActivePreset].pass   = passEl.value;
            tkaSmtpProfiles[tkaActivePreset].secure = secEl.value;
        }

        // 2. Switch preset
        tkaActivePreset = newPreset;

        // 3. Load saved credentials for this preset
        const prof = tkaSmtpProfiles[newPreset] || {};
        hostEl.value = prof.host || '';
        portEl.value = prof.port || 587;
        userEl.value = prof.user || '';
        passEl.value = prof.pass || '';
        secEl.value  = prof.secure || 'tls';

        // 4. Update labels & placeholders dynamically
        if (newPreset === 'gmail') {
            if (userLbl) userLbl.textContent = 'Gmail Address';
            if (passLbl) passLbl.textContent = 'Google App Password';
            userEl.placeholder = 'admin@example.com';
            passEl.placeholder = '16-character App Password';
            if (!userEl.value) userEl.value = document.getElementById('set-from-email').value || 'admin@example.com';
        } else if (newPreset.startsWith('amazon')) {
            if (userLbl) userLbl.textContent = 'SES Username (Access Key)';
            if (passLbl) passLbl.textContent = 'SES SMTP Password';
            userEl.placeholder = 'AKIA...';
            passEl.placeholder = 'Paste Amazon SES SMTP Password';
        } else {
            if (userLbl) userLbl.textContent = 'SMTP Username';
            if (passLbl) passLbl.textContent = 'SMTP Password';
            userEl.placeholder = 'Username / API Key';
            passEl.placeholder = 'Password / Secret';
        }
    }

    function saveSettings() {
        const pin = document.getElementById('set-pin').value;
        const hostEl = document.getElementById('set-smtp-host');
        const portEl = document.getElementById('set-smtp-port');
        const userEl = document.getElementById('set-smtp-user');
        const passEl = document.getElementById('set-smtp-pass');
        const secEl  = document.getElementById('set-smtp-secure');

        // Sync active preset in memory
        if (tkaActivePreset && tkaSmtpProfiles[tkaActivePreset]) {
            tkaSmtpProfiles[tkaActivePreset].host   = hostEl ? hostEl.value : '';
            tkaSmtpProfiles[tkaActivePreset].port   = portEl ? portEl.value : 587;
            tkaSmtpProfiles[tkaActivePreset].user   = userEl ? userEl.value : '';
            tkaSmtpProfiles[tkaActivePreset].pass   = passEl ? passEl.value : '';
            tkaSmtpProfiles[tkaActivePreset].secure = secEl ? secEl.value : 'tls';
        }

        const emailEngine = document.getElementById('set-email-engine') ? document.getElementById('set-email-engine').value : 'smtp';
        const smsProvider = document.getElementById('set-sms-provider') ? document.getElementById('set-sms-provider').value : 'smart_sms';

        const data = {
            action: 'tka_save_broadcast_settings',
            tka_active_sender_profile: document.getElementById('set-active-sender-profile') ? document.getElementById('set-active-sender-profile').value : 'main_church',
            tka_broadcast_from_name:  document.getElementById('set-from-name') ? document.getElementById('set-from-name').value : 'Elevate TKA',
            tka_broadcast_from_email: document.getElementById('set-from-email') ? document.getElementById('set-from-email').value : 'broadcast@elevate.tkamedia.com',
            tka_broadcast_reply_to:   document.getElementById('set-reply-to-email') ? document.getElementById('set-reply-to-email').value : 'admin@example.com',
            tka_broadcast_logo_url:   document.getElementById('set-logo-url') ? document.getElementById('set-logo-url').value : '',
            
            // Email Engine & SES API
            tka_email_engine:         emailEngine,
            tka_ses_region:           document.getElementById('set-ses-region') ? document.getElementById('set-ses-region').value : 'eu-north-1',
            tka_ses_access_key:       document.getElementById('set-ses-access-key') ? document.getElementById('set-ses-access-key').value : '',
            tka_ses_secret_key:       document.getElementById('set-ses-secret-key') ? document.getElementById('set-ses-secret-key').value : '',

            // SMTP Options
            tka_smtp_preset:          tkaActivePreset,
            tka_smtp_profiles:        JSON.stringify(tkaSmtpProfiles),
            tka_smtp_host:            hostEl ? hostEl.value : '',
            tka_smtp_port:            portEl ? portEl.value : 587,
            tka_smtp_user:            userEl ? userEl.value : '',
            tka_smtp_pass:            passEl ? passEl.value : '',
            tka_smtp_secure:          secEl ? secEl.value : 'tls',

            // Multi-Gateway SMS
            tka_sms_provider:         smsProvider,
            tka_smart_sms_token:      document.getElementById('set-smart-sms-token') ? document.getElementById('set-smart-sms-token').value : '',
            tka_smart_sms_sender:     document.getElementById('set-smart-sms-sender') ? document.getElementById('set-smart-sms-sender').value : '',
            tka_termii_api_key:       document.getElementById('set-termii-api-key') ? document.getElementById('set-termii-api-key').value : '',
            tka_termii_sender_id:     document.getElementById('set-termii-sender') ? document.getElementById('set-termii-sender').value : '',
            tka_sms_username:         document.getElementById('set-sms-user') ? document.getElementById('set-sms-user').value : '',
            tka_sms_password:         document.getElementById('set-sms-pass') ? document.getElementById('set-sms-pass').value : '',
            tka_sms_sender:           document.getElementById('set-sms-sender') ? document.getElementById('set-sms-sender').value : '',

            tka_studio_pin:           pin,
        };
        jQuery.post(ajaxurl, data, r=>{
            alert(r.success ? "✅ Settings saved successfully!" : "Error saving settings.");
            if(pin && r.success) {
                const pinEl = document.getElementById('set-pin');
                if (pinEl) pinEl.value = '';
            }
        });
    }

    function testSmtp(btn) {
        const testEmail = document.getElementById('test-smtp-email').value.trim();
        if(!testEmail) { alert("Please enter an email address for testing."); return; }
        btn.textContent = 'Sending...'; btn.disabled = true;

        const emailEngine = document.getElementById('set-email-engine') ? document.getElementById('set-email-engine').value : 'smtp';
        const data = {
            action: 'tka_test_smtp_connection',
            test_email:   testEmail,
            email_engine: emailEngine,
            from:         document.getElementById('set-from-email') ? document.getElementById('set-from-email').value : '',
            name:         document.getElementById('set-from-name') ? document.getElementById('set-from-name').value : '',
            reply_to:     document.getElementById('set-reply-to-email') ? document.getElementById('set-reply-to-email').value : '',
            
            // SES API params
            ses_region:     document.getElementById('set-ses-region') ? document.getElementById('set-ses-region').value : 'eu-north-1',
            ses_access_key: document.getElementById('set-ses-access-key') ? document.getElementById('set-ses-access-key').value : '',
            ses_secret_key: document.getElementById('set-ses-secret-key') ? document.getElementById('set-ses-secret-key').value : '',

            // SMTP params
            host:   document.getElementById('set-smtp-host') ? document.getElementById('set-smtp-host').value : '',
            port:   document.getElementById('set-smtp-port') ? document.getElementById('set-smtp-port').value : 587,
            user:   document.getElementById('set-smtp-user') ? document.getElementById('set-smtp-user').value : '',
            pass:   document.getElementById('set-smtp-pass') ? document.getElementById('set-smtp-pass').value : '',
            secure: document.getElementById('set-smtp-secure') ? document.getElementById('set-smtp-secure').value : 'tls',
        };
        jQuery.post(ajaxurl, data, r => {
            btn.textContent = '🧪 Test Email'; btn.disabled = false;
            alert(r.success ? "✅ " + r.data : "❌ " + r.data);
        });
    }

    function testSms(btn) {
        const testPhone = document.getElementById('test-sms-phone').value.trim();
        if(!testPhone) { alert("Please enter a phone number for testing."); return; }
        btn.textContent = 'Sending...'; btn.disabled = true;

        const provider = document.getElementById('set-sms-provider') ? document.getElementById('set-sms-provider').value : 'smart_sms';
        const data = {
            action: 'tka_test_sms_connection',
            test_phone: testPhone,
            provider:   provider,
            smart_sms_token:  document.getElementById('set-smart-sms-token') ? document.getElementById('set-smart-sms-token').value : '',
            smart_sms_sender: document.getElementById('set-smart-sms-sender') ? document.getElementById('set-smart-sms-sender').value : '',
            termii_api_key:   document.getElementById('set-termii-api-key') ? document.getElementById('set-termii-api-key').value : '',
            termii_sender_id: document.getElementById('set-termii-sender') ? document.getElementById('set-termii-sender').value : '',
            sms_user:         document.getElementById('set-sms-user') ? document.getElementById('set-sms-user').value : '',
            sms_pass:         document.getElementById('set-sms-pass') ? document.getElementById('set-sms-pass').value : '',
            sms_sender:       document.getElementById('set-sms-sender') ? document.getElementById('set-sms-sender').value : '',
        };
        jQuery.post(ajaxurl, data, r => {
            btn.textContent = '🧪 Test SMS'; btn.disabled = false;
            alert(r.success ? "✅ " + r.data : "❌ " + r.data);
        });
    }

    // ===================== MODAL CLOSE & SAFEGUARDS =====================
    window.addEventListener('click', e => {
        ['campaign-modal','tracking-modal','edit-contact-modal','ab-import-modal','ab-add-contact-modal'].forEach(id=>{
            const el=document.getElementById(id);
            if(e.target===el) el.classList.remove('open');
        });
    });

    window.addEventListener('beforeunload', e => {
        const overlay = document.getElementById('bs-send-overlay');
        const closeBtn = document.getElementById('bs-close-overlay');
        if (overlay && overlay.style.display === 'flex' && closeBtn && closeBtn.style.display === 'none') {
            e.preventDefault();
            e.returnValue = 'Broadcast is currently sending. Are you sure you want to navigate away?';
            return e.returnValue;
        }
    });

    // ===================== INIT =====================
    jQuery(document).ready(function($) {
        histRender();
        smsBuildList();
        abRender();

        const engEl = document.getElementById('set-email-engine');
        if (engEl) switchEmailEngine(engEl.value);

        const smsEl = document.getElementById('set-sms-provider');
        if (smsEl) switchSmsProvider(smsEl.value);
    });
    </script>
    </div>
    <?php
}
