<?php
/*
Plugin Name: One Click OAuth
Description: A plugin to hide content and unlock it using Patreon and Subscribestar APIs.
Version: 1.0
Author: Yeabsira Dereje
*/

// Function to hide content after <read more> tag
function oauth_hide_content($content) {
    // Check if the content contains the <!--more--> tag
    if (strpos($content, '<!--more-->') !== false) {
        // Split the content into two parts
        list($before_more, $after_more) = explode('<!--more-->', $content);

        // Here you can add logic to check if the user is authorized
        // For now, we'll just show a locked message
        return $before_more . '<p>This content is locked. Please log in to access it.</p>';
    }
    return $content;
}
add_filter('the_content', 'oauth_hide_content');

// Function to authenticate user with OAuth
function oauth_authenticate_user() {
    if (isset($_GET['oauth_provider'])) {
        $provider = $_GET['oauth_provider'];
        
        if ($provider == 'patreon') {
            // Redirect to Patreon OAuth endpoint
            $client_id = 'sBpOWAGt609aJOgedSwaP3yenNvXLWMqVXazfyzviUtrPx4chhXhyz1mAYRsAhSp'; 
            $redirect_url = 'http://localhost/mywordpress/?oauth_callback=patreon';
            $auth_url = "https://www.patreon.com/oauth2/authorize?response_type=code&client_id={$client_id}&redirect_uri=" . urlencode($redirect_url);
            wp_redirect($auth_url);
            exit;
        } elseif ($provider == 'subscribestar') {
            // Redirect to Subscribestar OAuth endpoint
            $client_id = 'gnfAs4BMsnfcqX6-8i-DgTBfQ_xGBiuiWcCdgUEsSC4'; // Your Subscribestar Client ID
            $redirect_url = 'http://localhost/mywordpress/?oauth_callback=subscribestar';
            $auth_url = "https://www.subscribestar.com/api/oauth/authorize?response_type=code&client_id={$client_id}&redirect_uri=" . urlencode($redirect_url);
            wp_redirect($auth_url);
            exit;
        }
    }
}
add_action('init', 'oauth_authenticate_user');

// Add a button to initiate OAuth
function oauth_add_button() {
    return '<a href="?oauth_provider=patreon" class="oauth-button">Unlock with Patreon</a> | <a href="?oauth_provider=subscribestar" class="oauth-button">Unlock with Subscribestar</a>';
}
add_shortcode('oauth_button', 'oauth_add_button');

// Example callback function after successful OAuth
function oauth_callback() {
    if (isset($_GET['oauth_callback'])) {
        $provider = $_GET['oauth_callback'];
        
        if ($provider == 'patreon' && isset($_GET['code'])) {
            $code = $_GET['code'];

            // Exchange the authorization code for an access token
            $client_id = 'sBpOWAGt609aJOgedSwaP3yenNvXLWMqVXazfyzviUtrPx4chhXhyz1mAYRsAhSp';
            $client_secret = 'weiBiJ8jhb6Ow5e0uhORDZxSLipqqrK1MQ5kOBMfNHwNLP6TOUlAIuoo9bnhh9FB'; // Your Patreon Client Secret
            $redirect_uri = 'http://localhost/mywordpress/?oauth_callback=patreon';

            $token_url = "https://www.patreon.com/api/oauth2/token";
            $response = wp_remote_post($token_url, [
                'body' => [
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirect_uri,
                ]
            ]);

            // Handle the response and store the access token
            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                $access_token = $data['access_token'];

                // Store the access token (e.g., in user meta or session)
                // For simplicity, we'll just echo it here
                echo "Patreon Access Token: " . esc_html($access_token);
            }
        } elseif ($provider == 'subscribestar' && isset($_GET['code'])) {
            $code = $_GET['code'];

            // Exchange the authorization code for an access token
            $client_id = 'gnfAs4BMsnfcqX6-8i-DgTBfQ_xGBiuiWcCdgUEsSC4'; // Your Subscribestar Client ID
            $client_secret = 'dtqq5ulTrU8e6TcX5RKuxCeV_xrhPsGz6bEbWo03wT8'; // Your Subscribestar Client Secret
            $redirect_uri = 'http://localhost/mywordpress/?oauth_callback=subscribestar';

            $token_url = "https://www.subscribestar.com/api/oauth/token"; // Adjust based on their API
            $response = wp_remote_post($token_url, [
                'body' => [
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirect_uri,
                ]
            ]);

            // Handle the response and store the access token
            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                $access_token = $data['access_token'];

                // Store the access token (e.g., in user meta or session)
                // For simplicity, we'll just echo it here
                echo "Subscribestar Access Token: " . esc_html($access_token);
            }
        }
    }
}
add_action('init', 'oauth_callback');
