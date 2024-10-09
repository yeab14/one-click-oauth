<?php
/*
Plugin Name: One Click OAuth Content Unlocker
Description: A plugin that hides content and unlocks it using Patreon and SubscribeStar OAuth2 APIs. Users can log in with either platform to access hidden content.
Version: 1.3
Author: Yeabsira Dereje
*/

// Define SubscribeStar and Patreon credentials
$subscribestar_client_id = 'v8Jli0gQupBmYkYYoAUATr3TLQjHxZyHO66npeir0mY';
$subscribestar_client_secret = 'k-_qKnbfeYcJ-LzPcojxz5T7WbPbgis7f8y4085-w0A';
$subscribestar_redirect_uri = 'http://localhost/mywordpress/?oauth_callback=subscribestar';
$patreon_client_id = 'sBpOWAGt609aJOgedSwaP3yenNvXLWMqVXazfyzviUtrPx4chhXhyz1mAYRsAhSp';
$patreon_client_secret = 'nJjjISDfnmmLJQgjtkFmYbfWpeYk9-rrH6l2VdxyhdOP-26wTcKZ-jTMChv';
$patreon_redirect_uri = 'http://localhost/mywordpress/?oauth_callback=patreon';

// Activation hook to set default options
function oauth_activate() {
    add_option('locked_amount', '5');
    add_option('lock_message', 'This content is locked.');
}
register_activation_hook(__FILE__, 'oauth_activate');

// Function to hide content after the <read more> tag
function oauth_hide_content($content) {
    $post_id = get_the_ID();
    $locked_amount = get_post_meta($post_id, 'locked_amount', true);
    
    if (is_user_logged_in() && get_user_meta(get_current_user_id(), 'oauth_access', true)) {
        return $content; // Show full content if user is authenticated via OAuth
    }

    // Check if the post belongs to a locked category
    $locked_categories = get_option('locked_categories', []);
    if (has_term($locked_categories, 'category', $post_id)) {
        return '<p>This content is locked. Please log in with Patreon or SubscribeStar to unlock it for $' . esc_html($locked_amount) . '.</p>' . do_shortcode('[oauth_button]');
    }

    // Check for the presence of the <read more> tag
    if (strpos($content, '<!--more-->') !== false) {
        list($before_more, $after_more) = explode('<!--more-->', $content);
        $locked_message = '<p>This content is locked. Please log in with Patreon or SubscribeStar to unlock it for $' . esc_html($locked_amount) . '.</p>';
        return $before_more . $locked_message . do_shortcode('[oauth_button]');
    }

    // If no <read more> tag, lock the entire post
    return '<p>This content is locked. Please log in with Patreon or SubscribeStar to unlock it for $' . esc_html($locked_amount) . '.</p>' . do_shortcode('[oauth_button]');
}
add_filter('the_content', 'oauth_hide_content');

// Function to initiate OAuth login for Patreon and SubscribeStar
function oauth_authenticate_user() {
    global $subscribestar_client_id, $subscribestar_redirect_uri, $patreon_client_id, $patreon_redirect_uri;

    if (isset($_GET['oauth_provider'])) {
        $provider = sanitize_text_field($_GET['oauth_provider']);

        if ($provider == 'patreon') {
            $auth_url = "https://www.patreon.com/oauth2/authorize?response_type=code&client_id={$patreon_client_id}&redirect_uri=" . urlencode($patreon_redirect_uri);
            wp_redirect($auth_url);
            exit;
        } elseif ($provider == 'subscribestar') {
            $auth_url = "https://www.subscribestar.com/oauth2/authorize?client_id={$subscribestar_client_id}&redirect_uri=" . urlencode($subscribestar_redirect_uri) . "&response_type=code&scope=" . urlencode("content_provider_profile.read content_provider_profile.subscriptions.read");
            wp_redirect($auth_url);
            exit;
        }
    }
}
add_action('init', 'oauth_authenticate_user');

// Function to handle OAuth callback for both Patreon and SubscribeStar
function oauth_callback() {
    global $subscribestar_client_id, $subscribestar_client_secret, $subscribestar_redirect_uri, $patreon_client_id, $patreon_client_secret, $patreon_redirect_uri;

    if (isset($_GET['oauth_callback'])) {
        $provider = sanitize_text_field($_GET['oauth_callback']);
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';

        if ($provider == 'patreon' && $code) {
            $token_url = "https://www.patreon.com/api/oauth2/token";
            $response = wp_remote_post($token_url, [
                'body' => [
                    'client_id' => $patreon_client_id,
                    'client_secret' => $patreon_client_secret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $patreon_redirect_uri,
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            handle_auth_response($response, 'patreon');
        } elseif ($provider == 'subscribestar' && $code) {
            $access_token = request_subscribestar_token($code);
            if ($access_token) {
                update_user_meta(get_current_user_id(), 'oauth_access', true);
                update_user_meta(get_current_user_id(), 'subscribestar_access_token', $access_token);

                // Make API call to check subscription status
                $subscription_data = send_subscribestar_api_request($access_token, "{ subscriber { subscription { active } } }");
                
                if ($subscription_data && $subscription_data->subscriber->subscription->active) {
                    wp_redirect(home_url());
                } else {
                    wp_redirect(home_url('/subscription-error'));
                }
                exit;
            } else {
                wp_redirect(home_url('?error=subscribestar_auth_failed'));
                exit;
            }
        }
    }
}
add_action('init', 'oauth_callback');

// Function to handle the authentication response
function handle_auth_response($response, $provider) {
    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['access_token'])) {
            update_user_meta(get_current_user_id(), 'oauth_access', true);
            update_user_meta(get_current_user_id(), "{$provider}_access_token", $data['access_token']);
            wp_redirect(home_url());
            exit;
        } else {
            // Handle error
            wp_redirect(home_url('?error=auth_failed&provider=' . $provider));
            exit;
        }
    } else {
        // Handle request error
        wp_redirect(home_url('?error=request_failed&provider=' . $provider));
        exit;
    }
}

// Function to send API request to SubscribeStar
function send_subscribestar_api_request($access_token, $query) {
    $api_endpoint = "https://www.subscribestar.com/api/graphql/v1";
    $response = wp_remote_post($api_endpoint, [
        'body' => json_encode(['query' => $query]),
        'headers' => [
            'Authorization' => "Bearer {$access_token}",
            'Content-Type' => 'application/json',
        ],
    ]);

    if (!is_wp_error($response)) {
        return json_decode(wp_remote_retrieve_body($response));
    }
    return null;
}

// Function to request token for SubscribeStar using the received code
function request_subscribestar_token($code) {
    global $subscribestar_client_id, $subscribestar_client_secret, $subscribestar_redirect_uri;

    $url = "https://www.subscribestar.com/oauth2/token";
    $response = wp_remote_post($url, [
        'body' => [
            'client_id' => $subscribestar_client_id,
            'client_secret' => $subscribestar_client_secret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $subscribestar_redirect_uri,
        ],
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
    ]);

    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['access_token'])) {
            return $data['access_token'];
        }
    }
    return null;
}

// Add shortcode to display OAuth login buttons
function oauth_add_button() {
    // Style the buttons with custom CSS
    $output = '<style>
        .oauth-button {
            display: inline-block;
            background-color: #FF424D; /* Default color for Patreon button */
            color: white;
            padding: 10px 20px;
            margin: 10px;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }
        .oauth-button:hover {
            background-color: #D93E45; /* Darker shade on hover */
        }
        .subscribestar-button {
            background-color: #FFAB20; /* Color for SubscribeStar button */
        }
        .subscribestar-button:hover {
            background-color: #D8911B; /* Darker shade on hover */
        }
    </style>';
    $output .= '<a href="' . esc_url(add_query_arg('oauth_provider', 'patreon')) . '" class="oauth-button">Log in with Patreon</a>';
    $output .= '<a href="' . esc_url(add_query_arg('oauth_provider', 'subscribestar')) . '" class="oauth-button subscribestar-button">Log in with SubscribeStar</a>';
    return $output;
}
add_shortcode('oauth_button', 'oauth_add_button');

// Add settings page for plugin options
function oauth_add_admin_menu() {
    add_options_page('OAuth Content Unlocker', 'OAuth Content Unlocker', 'manage_options', 'oauth_content_unlocker', 'oauth_options_page');
}
add_action('admin_menu', 'oauth_add_admin_menu');

// Options page HTML
function oauth_options_page() {
    if (isset($_POST['submit'])) {
        check_admin_referer('oauth_save_settings');

        // Update settings
        update_option('locked_amount', sanitize_text_field($_POST['locked_amount']));
        $locked_categories = isset($_POST['locked_categories']) ? $_POST['locked_categories'] : [];
        update_option('locked_categories', $locked_categories);

        echo '<div class="updated"><p>Settings saved!</p></div>';
    }

    // Fetch current settings
    $locked_amount = get_option('locked_amount', '5');
    $locked_categories = get_option('locked_categories', []);

    // Display the options form
    ?>
    <div class="wrap">
        <h1>OAuth Content Unlocker Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field('oauth_save_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="locked_amount">Locked Amount ($)</label></th>
                    <td><input type="text" name="locked_amount" value="<?php echo esc_attr($locked_amount); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="locked_categories">Lock Categories</label></th>
                    <td>
                        <?php
                        $categories = get_categories();
                        foreach ($categories as $category) {
                            $checked = in_array($category->term_id, (array)$locked_categories) ? 'checked' : '';
                            echo '<label><input type="checkbox" name="locked_categories[]" value="' . esc_attr($category->term_id) . '" ' . $checked . ' /> ' . esc_html($category->name) . '</label><br />';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            <p class="submit"><input type="submit" name="submit" class="button button-primary" value="Save Changes" /></p>
        </form>
    </div>
    <?php
}

// Function to add error messages
function oauth_display_errors() {
    if (isset($_GET['error'])) {
        $error_message = sanitize_text_field($_GET['error']);
        echo '<div class="error"><p>' . esc_html($error_message) . '</p></div>';
    }
}
add_action('admin_notices', 'oauth_display_errors');



