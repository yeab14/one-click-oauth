<?php
/*
Plugin Name: One Click OAuth Content Unlocker
Description: A powerful WordPress plugin that securely hides content and unlocks it using OAuth2 authentication & authorization with Patreon and SubscribeStar.
Version: 1.4
Author: Yeabsira Dereje
*/

$subscribestar_client_id = '35HsXYySVYlJvq-LQn-zhvbdD61wPp_YJbh_BQdSWe0';
$subscribestar_client_secret = 'CcvyfrIlE0Ml7CbRfpfaD6oLssXjwiakUyYz8eCPFlQ';
$subscribestar_redirect_uri = 'https://kanban.my/';
$patreon_client_id = '9TIY71rz4tfH2AlByTgCTYVOQtn3Ji736oOVCHr9v2QEHcdKX0mB2f0zf2j3shBK';
$patreon_client_secret = 'R-Vz-tH8ozXAw3gdjSP_4_mIGnZxrb5nA7i_vfVn2qD-CYDtvNQHcX-AqiVfWLtf';
$patreon_redirect_uri = 'https://kanban.my/';

function oauth_activate() {
    add_option('locked_amount', '5');
    add_option('lock_message', 'This content is locked.');
    add_option('category_locked_amounts', serialize([])); // Store category settings as a serialized array
}
register_activation_hook(__FILE__, 'oauth_activate');

function oauth_hide_content($content) {
    $post_id = get_the_ID();
    $locked_amounts = unserialize(get_option('category_locked_amounts', serialize([])));
    $categories = get_the_category($post_id);

    $locked_amount = get_option('locked_amount');
    foreach ($categories as $category) {
        if (isset($locked_amounts[$category->term_id])) {
            $locked_amount = $locked_amounts[$category->term_id];
            break;
        }
    }

    if (is_user_logged_in() && get_user_meta(get_current_user_id(), 'oauth_access', true)) {
     
        $user_pledge = get_user_meta(get_current_user_id(), 'user_pledge', true);
        if ($user_pledge >= $locked_amount) {
            return $content; 
        }
    }

    
    return '<p>' . esc_html(get_option('lock_message')) . ' Please log in with Patreon or SubscribeStar to unlock it for $' . esc_html($locked_amount) . '.</p>' . do_shortcode('[oauth_button]');
}
add_filter('the_content', 'oauth_hide_content');

function oauth_authenticate_user() {
    if (isset($_GET['oauth_provider'])) {
        $provider = sanitize_text_field($_GET['oauth_provider']);
        if ($provider == 'patreon') {
            $auth_url = "https://www.patreon.com/oauth2/authorize?response_type=code&client_id={$GLOBALS['patreon_client_id']}&redirect_uri=" . urlencode($GLOBALS['patreon_redirect_uri']);
            wp_redirect($auth_url);
            exit;
        } elseif ($provider == 'subscribestar') {
            $auth_url = "https://www.subscribestar.com/oauth2/authorize?client_id={$GLOBALS['subscribestar_client_id']}&redirect_uri=" . urlencode($GLOBALS['subscribestar_redirect_uri']) . "&response_type=code&scope=" . urlencode("content_provider_profile.read content_provider_profile.subscriptions.read");
            wp_redirect($auth_url);
            exit;
        }
    }
}
add_action('init', 'oauth_authenticate_user');

function oauth_callback() {
    if (isset($_GET['oauth_callback'])) {
        $provider = sanitize_text_field($_GET['oauth_callback']);
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';

        if ($provider == 'patreon' && $code) {
            $token = oauth_get_token('patreon', $code);
            if ($token) {
                $user_info = oauth_get_user_info('patreon', $token);
                if ($user_info) {
                    $email = $user_info->data->attributes->email;
                    $nickname = $user_info->data->attributes->full_name;

                    $user = get_user_by('email', $email);
                    if (!$user) {
                        $user_id = wp_create_user($nickname, wp_generate_password(), $email);
                        if (!is_wp_error($user_id)) {
                            wp_update_user(['ID' => $user_id, 'role' => 'subscriber']);
                        }
                    } else {
                        update_user_meta($user->ID, 'oauth_access', true);
                    }
                    update_user_meta($user->ID, 'patreon_access_token', $token);

                    $pledge_data = oauth_get_user_pledge('patreon', $token);
                    if ($pledge_data) {
                        $pledge_amount = $pledge_data->included[0]->attributes->amount_cents / 100; // Store pledge amount in dollars
                        update_user_meta($user->ID, 'user_pledge', $pledge_amount);
                        
                        // Logic for redirecting based on pledge amount
                        if ($pledge_amount >= 10) {
                            wp_redirect(home_url('/thank-you'));
                        } else {
                            wp_redirect(home_url('/thank-you-low-pledge'));
                        }
                        exit;
                    }
                }
                exit;
            }
        } elseif ($provider == 'subscribestar' && $code) {
            $access_token = oauth_get_token('subscribestar', $code);
            if ($access_token) {
                update_user_meta(get_current_user_id(), 'oauth_access', true);
                update_user_meta(get_current_user_id(), 'subscribestar_access_token', $access_token);

                
                $subscription_data = oauth_get_user_subscription('subscribestar', $access_token);
                if ($subscription_data) {
                    $subscription_amount = $subscription_data->subscription_amount / 100; // Store pledge amount in dollars
                    update_user_meta(get_current_user_id(), 'user_pledge', $subscription_amount);

                    // Logic for redirecting based on pledge amount
                    if ($subscription_amount >= 10) {
                        wp_redirect(home_url('/thank-you'));
                    } else {
                        wp_redirect(home_url('/thank-you-low-pledge'));
                    }
                    exit;
                } else {
                    wp_redirect(home_url('/subscription-error?error=no_subscription_data'));
                }
                exit;
            }
        }
    }
}

add_action('init', 'oauth_callback');

function oauth_get_token($provider, $code) {
    $url = $provider == 'patreon' ? "https://www.patreon.com/api/oauth2/token" : "https://www.subscribestar.com/oauth2/token";
    $response = wp_remote_post($url, [
        'body' => [
            'client_id' => $provider == 'patreon' ? $GLOBALS['patreon_client_id'] : $GLOBALS['subscribestar_client_id'],
            'client_secret' => $provider == 'patreon' ? $GLOBALS['patreon_client_secret'] : $GLOBALS['subscribestar_client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $provider == 'patreon' ? $GLOBALS['patreon_redirect_uri'] : $GLOBALS['subscribestar_redirect_uri'],
        ],
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
    ]);

    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['access_token'] ?? null;
    }
    return null;
}

function oauth_get_user_pledge($provider, $token) {
    if ($provider == 'patreon') {
        $url = "https://www.patreon.com/api/oauth2/v2/identity?include=pledges";
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ],
        ]);

        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response));
            return $data; 
        }
    }
    return null; 
}


function oauth_check_user_pledges() {
    $users = get_users(['meta_key' => 'oauth_access', 'meta_value' => true]);
    foreach ($users as $user) {
        $token = get_user_meta($user->ID, 'patreon_access_token', true);
        if ($token) {
            $pledge_data = oauth_get_user_pledge('patreon', $token);
            if ($pledge_data) {
                $current_pledge = $pledge_data->included[0]->attributes->amount_cents / 100;
                update_user_meta($user->ID, 'user_pledge', $current_pledge);
                
            }
        }
    }
}
add_action('oauth_check_user_pledges_hook', 'oauth_check_user_pledges');

// Schedule the event (for example, daily)
if (!wp_next_scheduled('oauth_check_user_pledges_hook')) {
    wp_schedule_event(time(), 'daily', 'oauth_check_user_pledges_hook');
}


function oauth_get_user_subscription($provider, $token) {
    if ($provider == 'subscribestar') {
        $url = "https://www.subscribestar.com/api/v1/subscription"; // Ensure this is the correct endpoint
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ],
        ]);

        if (!is_wp_error($response)) {
            return json_decode(wp_remote_retrieve_body($response));
        }
    }
    return null;
}


function oauth_add_button() {
    $output = '<style>
        .oauth-button {
            display: inline-block;
            background-color: #FF424D; /* Default color for Patreon button */
            color: white;
            padding: 10px 20px;
            margin: 10px;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .oauth-button:hover {
            background-color: #FF2E3A; /* Darker shade for hover */
        }
    </style>';
    $output .= '<a href="' . esc_url(add_query_arg('oauth_provider', 'patreon', home_url())) . '" class="oauth-button">Log in with Patreon</a>';
    $output .= '<a href="' . esc_url(add_query_arg('oauth_provider', 'subscribestar', home_url())) . '" class="oauth-button" style="background-color: #00BFFF;">Log in with SubscribeStar</a>'; // Different color for SubscribeStar button
    return $output;
}
add_shortcode('oauth_button', 'oauth_add_button');

function oauth_add_settings_page() {
    add_options_page('OAuth Settings', 'OAuth Settings', 'manage_options', 'oauth-settings', 'oauth_render_settings_page');
}
add_action('admin_menu', 'oauth_add_settings_page');

function oauth_render_settings_page() {
    if (isset($_POST['save_settings'])) {
        update_option('locked_amount', sanitize_text_field($_POST['locked_amount']));
        update_option('lock_message', sanitize_text_field($_POST['lock_message']));
        $category_amounts = array_map('sanitize_text_field', $_POST['category_locked_amount']);
        $locked_amounts = [];
        foreach ($category_amounts as $cat_id => $amount) {
            $locked_amounts[$cat_id] = intval($amount);
        }
        update_option('category_locked_amounts', serialize($locked_amounts)); // Store as serialized array
    }

    $locked_amount = get_option('locked_amount', '5');
    $lock_message = get_option('lock_message', 'This content is locked.');
    $category_locked_amounts = unserialize(get_option('category_locked_amounts', serialize([])));

    // Render settings page HTML
    ?>
    <div class="wrap">
        <h1>OAuth Settings</h1>
        <form method="POST">
            <label for="locked_amount">Locked Amount ($):</label>
            <input type="text" id="locked_amount" name="locked_amount" value="<?php echo esc_attr($locked_amount); ?>" /><br />
            <label for="lock_message">Lock Message:</label>
            <input type="text" id="lock_message" name="lock_message" value="<?php echo esc_attr($lock_message); ?>" /><br />
            <h3>Category Locked Amounts:</h3>
            <?php
            $categories = get_categories();
            foreach ($categories as $category) {
                $amount = isset($category_locked_amounts[$category->term_id]) ? $category_locked_amounts[$category->term_id] : '';
                echo '<label>' . esc_html($category->name) . ':</label>';
                echo '<input type="text" name="category_locked_amount[' . esc_attr($category->term_id) . ']" value="' . esc_attr($amount) . '" /><br />';
            }
            ?>
            <input type="submit" name="save_settings" value="Save Settings" />
        </form>
    </div>
    <?php
}




