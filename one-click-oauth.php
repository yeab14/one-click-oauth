<?php
/*
Plugin Name: One Click OAuth Content Unlocker
Description: A plugin that hides content and unlocks it using Patreon and SubscribeStar OAuth2 APIs. Users can log in with either platform to access hidden content.
Version: 1.1
Author: Yeabsira Dereje
*/

// Define SubscribeStar and Patreon credentials
$subscribestar_client_id = 'gnfAs4BMsnfcqX6-8i-DgTBfQ_xGBiuiWcCdgUEsSC4';
$subscribestar_client_secret = 'dtqq5ulTrU8e6TcX5RKuxCeV_xrhPsGz6bEbWo03wT8';
$subscribestar_redirect_uri = 'http://localhost/mywordpress/?oauth_callback=subscribestar';
$patreon_client_id = 'sBpOWAGt609aJOgedSwaP3yenNvXLWMqVXazfyzviUtrPx4chhXhyz1mAYRsAhSp';
$patreon_client_secret = 'nJjjISDfnmmLJQgjtkFmYbfWpeYk9-rrH6l2VdxyhdOP-26wTcKZ-jTMChv';
$patreon_redirect_uri = 'http://localhost/mywordpress/?oauth_callback=patreon';

// Function to hide content after the <read more> tag
function oauth_hide_content($content) {
    if (is_user_logged_in() && get_user_meta(get_current_user_id(), 'oauth_access', true)) {
        return $content; // Show full content if user is authenticated via OAuth
    }

    if (strpos($content, '<!--more-->') !== false) {
        list($before_more, $after_more) = explode('<!--more-->', $content);
        return $before_more . '<p>This content is locked. Please log in with Patreon or SubscribeStar to access it.</p>' . do_shortcode('[oauth_button]');
    }

    return $content;
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
            $auth_url = "https://www.subscribestar.com/oauth2/authorize?client_id={$subscribestar_client_id}&redirect_uri=" . urlencode($subscribestar_redirect_uri) . "&response_type=code&scope=subscriber.read+subscriber.payments.read+user.read+user.email.read";
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

            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($data['access_token'])) {
                    update_user_meta(get_current_user_id(), 'oauth_access', true); // Mark user as authenticated
                    update_user_meta(get_current_user_id(), 'patreon_access_token', $data['access_token']); // Save Patreon access token
                    wp_redirect(home_url()); // Redirect to homepage or content
                    exit;
                }
            }
        } elseif ($provider == 'subscribestar' && $code) {
            // Follow the SubscribeStar sample structure
            $token_url = "https://www.subscribestar.com/oauth2/token";
            $response = wp_remote_post($token_url, [
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
                    update_user_meta(get_current_user_id(), 'oauth_access', true); // Mark user as authenticated
                    update_user_meta(get_current_user_id(), 'subscribestar_access_token', $data['access_token']); // Save SubscribeStar access token
                    
                    // Make API call to get subscription status
                    $subscription_data = send_subscribestar_api_request($data['access_token'], "{ subscriber { subscription { active } } }");
                    
                    if ($subscription_data && $subscription_data->subscriber->subscription->active) {
                        wp_redirect(home_url()); // User is subscribed, redirect to content
                    } else {
                        wp_redirect(home_url('/subscription-error')); // Subscription failed or inactive
                    }
                    exit;
                }
            }
        }
    }
}
add_action('init', 'oauth_callback');

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

// Add shortcode to display OAuth login buttons
function oauth_add_button() {
    $output = '<a href="?oauth_provider=patreon" class="oauth-button" style="background-color:#FF424D;color:white;padding:10px;margin:10px;">Unlock with Patreon</a>';
    $output .= '<a href="?oauth_provider=subscribestar" class="oauth-button" style="background-color:#FFD700;color:black;padding:10px;margin:10px;">Unlock with SubscribeStar</a>';
    return $output;
}
add_shortcode('oauth_button', 'oauth_add_button');

// Display message after login or error handling
function oauth_message() {
    if (isset($_GET['oauth_callback'])) {
        $provider = sanitize_text_field($_GET['oauth_callback']);
        if (isset($_GET['error'])) {
            echo '<p style="color:red;">Error: ' . esc_html($_GET['error_description']) . '</p>';
        } else {
            echo '<p style="color:green;">Login successful with ' . ucfirst($provider) . '!</p>';
        }
    }
}
add_action('wp_footer', 'oauth_message');
