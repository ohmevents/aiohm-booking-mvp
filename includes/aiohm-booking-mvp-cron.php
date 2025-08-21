<?php
if (!defined('ABSPATH')) { exit; }

class AIOHM_Booking_MVP_Cron {

    public static function init() {
        add_action('aiohm_booking_mvp_fifteen_minutes_cron', [__CLASS__, 'run_ical_sync']);

        if (!wp_next_scheduled('aiohm_booking_mvp_fifteen_minutes_cron')) {
            wp_schedule_event(time(), '15_minutes', 'aiohm_booking_mvp_fifteen_minutes_cron');
        }

        // Add custom cron interval
        add_filter('cron_schedules', [__CLASS__, 'add_cron_interval']);
    }

    public static function add_cron_interval($schedules) {
        $schedules['15_minutes'] = array(
            'interval' => 15 * 60,
            'display'  => esc_html__('Every 15 Minutes', 'aiohm-booking-mvp'),
        );
        return $schedules;
    }

    public static function run_ical_sync() {
        $settings = get_option('aiohm_booking_mvp_settings', []);
        
        // Get both platform URLs
        $booking_com_url = $settings['booking_com_ical_url'] ?? '';
        $airbnb_url = $settings['airbnb_ical_url'] ?? '';
        
        $blocked_dates = get_option('aiohm_booking_mvp_blocked_dates', []);

        // Clear existing external bookings to avoid duplicates
        foreach ($blocked_dates as $room_id => &$dates) {
            foreach ($dates as $date => $details) {
                if (isset($details['status']) && $details['status'] === 'external') {
                    unset($dates[$date]);
                }
            }
        }

        $available_rooms = intval($settings['available_rooms'] ?? 7);
        
        // Sync Booking.com
        if (!empty($booking_com_url) && filter_var($booking_com_url, FILTER_VALIDATE_URL)) {
            $events = self::fetch_ical_events($booking_com_url);
            if (!empty($events)) {
                $blocked_dates = self::process_external_events($events, $blocked_dates, $available_rooms, 'Booking.com');
            }
        }
        
        // Sync Airbnb  
        if (!empty($airbnb_url) && filter_var($airbnb_url, FILTER_VALIDATE_URL)) {
            $events = self::fetch_ical_events($airbnb_url);
            if (!empty($events)) {
                $blocked_dates = self::process_external_events($events, $blocked_dates, $available_rooms, 'Airbnb');
            }
        }

        update_option('aiohm_booking_mvp_blocked_dates', $blocked_dates);
        
        // Log successful sync
        update_option('aiohm_booking_mvp_last_sync', current_time('mysql'));
    }
    
    private static function fetch_ical_events($url) {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'AIOHM Booking iCal Sync/1.0'
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        return self::parse_ical($body);
    }
    
    private static function process_external_events($events, $blocked_dates, $available_rooms, $platform) {
        foreach ($events as $event) {
            try {
                $start_date = new DateTime($event['DTSTART']);
                $end_date = new DateTime($event['DTEND']);
                $summary = $event['SUMMARY'] ?? 'External Booking';
                $uid = $event['UID'] ?? '';

                $period = new DatePeriod($start_date, new DateInterval('P1D'), $end_date);

                foreach ($period as $date) {
                    for ($i = 1; $i <= $available_rooms; $i++) {
                        $date_key = $date->format('Y-m-d');
                        if (!isset($blocked_dates[$i])) {
                            $blocked_dates[$i] = [];
                        }
                        $blocked_dates[$i][$date_key] = [
                            'status' => 'external',
                            'reason' => "[$platform] $summary",
                            'platform' => strtolower($platform),
                            'external_uid' => $uid,
                            'blocked_at' => current_time('mysql'),
                        ];
                    }
                }
            } catch (Exception $e) {
                // Skip invalid events
                continue;
            }
        }
        
        return $blocked_dates;
    }

    private static function parse_ical($ical_string) {
        $events = [];
        $lines = explode("\n", $ical_string);
        $current_event = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'BEGIN:VEVENT') !== false) {
                $current_event = [];
            } elseif (strpos($line, 'END:VEVENT') !== false) {
                $events[] = $current_event;
            } elseif ($current_event !== null) {
                if (preg_match('/^(DTSTART|DTEND|SUMMARY|UID|DESCRIPTION|LOCATION|STATUS|SEQUENCE|TRANSP)(;VALUE=DATE)?[:;](.*)$/', $line, $matches)) {
                    $key = $matches[1];
                    $value = $matches[3];
                    $current_event[$key] = $value;
                }
            }
        }

        return $events;
    }
}

AIOHM_Booking_MVP_Cron::init();