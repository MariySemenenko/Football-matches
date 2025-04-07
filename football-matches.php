<?php
/*
Plugin Name: Football Matches Plugin
Plugin URI: https://www.dev-07.semenenko.pp.ua/
Description: Match display plugin.
Version: 1.0
Author: Semenenko Maria
Author https://www.dev-07.semenenko.pp.ua/
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit;
}
 
function get_football_leagues() {
    $api_key = get_option('football_api_key');
    if (empty($api_key)) {
		echo '<div style="color: red;">' . __('API key not configured. Please specify it in the plugin settings.', 'football-matches') . '</div>';
        return [];
    } 	
   $url = "https://api.football-data.org/v4/competitions";  
	
    $args = [
        'headers' => [
            'X-Auth-Token' => $api_key,
        ],
    ];
    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        return [];
    }
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    return $data['competitions'] ?? [];
}

function football_matches_form() {
    $leagues = get_football_leagues();
    ?>
    <form method="post">
	<?php wp_nonce_field('football_matches_action', 'football_matches_nonce'); ?>
       <label for="league"><?php _e('League:', 'football-matches'); ?></label>
        <select name="league" id="league">
            <option value="all" <?php echo isset($_POST['league']) && $_POST['league'] == 'all' ? 'selected' : ''; ?>><?php _e('All Leagues', 'football-matches'); ?>
            <?php foreach ($leagues as $league) : ?>
                <option value="<?php echo esc_attr($league['code']); ?>" <?php echo isset($_POST['league']) && $_POST['league'] == $league['code'] ? 'selected' : ''; ?>>
                    <?php echo esc_html($league['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="start_date"><?php _e('Start date:', 'football-matches'); ?></label>
        <input type="date" name="start_date" id="start_date" value="<?php echo isset($_POST['start_date']) ? esc_attr($_POST['start_date']) : ''; ?>">

        <label for="end_date"><?php _e('End date:', 'football-matches'); ?></label>
        <input type="date" name="end_date" id="end_date" value="<?php echo isset($_POST['end_date']) ? esc_attr($_POST['end_date']) : ''; ?>">
        <input type="submit" value="<?php esc_attr_e('Show matches', 'football-matches'); ?>">
    </form>
    <?php
}
  
function get_football_matches($league, $start_date, $end_date) {
    $api_key = get_option('football_api_key');
    if (empty($api_key)) {
        echo '<div style="color: red;">' . __('API key not configured.', 'football-matches') . '</div>';
        return [];
    }	
    if ($league == 'all') {
        $url = "https://api.football-data.org/v4/matches?dateFrom=$start_date&dateTo=$end_date";
    } else {
        $url = "https://api.football-data.org/v4/matches?competitions=$league&dateFrom=$start_date&dateTo=$end_date";
    }  
    $args = [
        'headers' => [
            'X-Auth-Token' => $api_key,
        ],
    ];
    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        return [];
    }
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    return $data['matches'] ?? [];
}

function get_cached_football_matches($league, $start_date, $end_date) {
    $cache_key = "football_matches_{$league}_{$start_date}_{$end_date}";
    $matches = get_transient($cache_key);

    if ($matches === false) {
        $matches = get_football_matches($league, $start_date, $end_date);
        set_transient($cache_key, $matches, 1 * HOUR_IN_SECONDS);
    }
    return $matches;
}

function display_football_matches() {
    if (isset($_POST['league']) && isset($_POST['start_date']) && isset($_POST['end_date'])) {
        if (!isset($_POST['football_matches_nonce']) || !wp_verify_nonce($_POST['football_matches_nonce'], 'football_matches_action')) {
            echo '<div style="color: red;">Security error.</div>';
            return;
        }

        $league = sanitize_text_field($_POST['league']);
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);

        $matches = get_cached_football_matches($league, $start_date, $end_date);

        if (empty($matches)) {
            _e('There are no matches for this date range.', 'football-matches');
            return;
        }

        echo '<table>';
        echo '<tr><th>Date</th><th>Teams</th></tr>';
        foreach ($matches as $match) {
            $date = date('d.m.Y H:i', strtotime($match['utcDate']));
            echo '<tr>';
            echo '<td>' . esc_html($date) . '</td>';
            echo '<td>' . esc_html($match['homeTeam']['name']) . ' vs ' . esc_html($match['awayTeam']['name']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
}


function football_matches_shortcode() {
    ob_start();
    football_matches_form();
    display_football_matches();  
    return ob_get_clean();
}

add_shortcode('football_matches', 'football_matches_shortcode');   

/*
Setting up an API key in the admin panel wordpress.
*/

add_action('admin_menu', 'football_api_settings_menu');
function football_api_settings_menu() {
    add_options_page(
        __('Football API Settings', 'football-matches'),
        __('Football API', 'football-matches'), 
        'manage_options',
        'football-api-settings',
        'football_api_settings_page'
    );
}


function football_api_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Football API Settings', 'football-matches'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('football_api_settings_group');
            do_settings_sections('football-api-settings');  
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'football_api_register_settings');
function football_api_register_settings() {
    register_setting('football_api_settings_group', 'football_api_key');

    add_settings_section(
    'football_api_main_section',
    __('API Key Configuration', 'football-matches'),
    null,
    'football-api-settings'
);

    add_settings_field(
    'football_api_key',
    __('API Key', 'football-matches'),
    'football_api_key_input_callback',
    'football-api-settings',
    'football_api_main_section'
);
}

function football_api_key_input_callback() {
    $api_key = get_option('football_api_key');
    echo '<input type="text" name="football_api_key" value="' . esc_attr($api_key) . '" size="50">';
}

/*
Translation download function
*/
add_action('plugins_loaded', 'football_matches_load_textdomain');
function football_matches_load_textdomain() {
    load_plugin_textdomain('football-matches', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

/*
Connecting styles
*/
function football_matches_enqueue_styles() {
    wp_enqueue_style('football-matches-style', plugin_dir_url(__FILE__) . 'style.css');
}
add_action('wp_enqueue_scripts', 'football_matches_enqueue_styles');
