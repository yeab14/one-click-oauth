<?php
/*
Plugin Name: One Click OAuth Content Unlocker
Description: A powerful WordPress plugin that securely hides content and unlocks it using OAuth2 authentication & authorization with Patreon and SubscribeStar.
Version: 1.5
Author: Yeabsira Dereje
*/

$subscribestar_client_id = '35HsXYySVYlJvq-LQn-zhvbdD61wPp_YJbh_BQdSWe0';
$subscribestar_client_secret = 'CcvyfrIlE0Ml7CbRfpfaD6oLssXjwiakUyYz8eCPFlQ';
$subscribestar_redirect_uri = 'https://kanban.my/';
$patreon_client_id = '9TIY71rz4tfH2AlByTgCTYVOQtn3Ji736oOVCHr9v2QEHcdKX0mB2f0zf2j3shBK';
$patreon_client_secret = 'R-Vz-tH8ozXAw3gdjSP_4_mIGnZxrb5nA7i_vfVn2qD-CYDtvNQHcX-AqiVfWLtf';
$patreon_redirect_uri = 'https://kanban.my/';

// Plugin activation to add required options
function oauth_activate() {
    add_option('locked_amount', '5');
    add_option('lock_message', 'This content is locked.');
    add_option('category_locked_amounts', serialize([])); 
    add_option('patreon_join_url', 'https://www.patreon.com/login?ru=%2Foauth2%2Fauthorize%3Fresponse_type%3Dcode%26client_id%3D' . $GLOBALS['patreon_client_id'] . '%26redirect_uri%3D' . urlencode($GLOBALS['patreon_redirect_uri']));
    add_option('patreon_checkout_url', 'https://www.patreon.com/checkout/moonboxgames?rid=8228940');
}
register_activation_hook(__FILE__, 'oauth_activate');


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

function oauth_pledge_button() {
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
    
    $output .= '<a href="' . esc_url(get_option('patreon_checkout_url')) . '" class="oauth-button">Pledge with Patreon</a>';
    $output .= '<a href="' . esc_url(add_query_arg('oauth_provider', 'subscribestar', home_url())) . '" class="oauth-button" style="background-color: #00BFFF;">Pledge with SubscribeStar</a>'; // Different color for SubscribeStar button
    
    return $output;
}
add_shortcode('oauth_pledge_button', 'oauth_pledge_button');

function oauth_hide_content($content) {
    if (is_user_logged_in()) {
        $user_pledge = get_user_meta(get_current_user_id(), 'user_pledge', true);
        $locked_amount = get_option('locked_amount');

        // Check if the user's pledge is empty or less than the locked amount
        if (empty($user_pledge) || !is_numeric($user_pledge) || $user_pledge < $locked_amount) {
            // User does not have enough pledge
            ob_start(); // Start output buffering
            ?>
            <div class="locked-content-notice" style="background-color: #ffefef; border: 1px solid #ff0000; padding: 20px; border-radius: 5px;">
                <p><?php echo esc_html(get_option('lock_message')); ?></p>
                <p>Your current pledge amount is $<?php echo esc_html($user_pledge); ?>, which is less than the required $<?php echo esc_html($locked_amount); ?> to access this content.</p>
                <p>Would you like to:</p>
                <?php echo do_shortcode('[oauth_pledge_button]'); // Use the shortcode to display pledge buttons ?>
            </div>
            <?php
            return ob_get_clean(); // Return the buffered content
        }

        // User has pledged enough, return the protected content
        if (isset($content)) {
            return $content;
        } else {
            return '<p>Content not found. Please check back later.</p>';
        }
    } else {
        // User not logged in, show lock message and OAuth button
        $locked_amount = get_option('locked_amount');
        return '<p>' . esc_html(get_option('lock_message')) . ' Your journey into a realm of exclusive content awaits!</p>' .
               '<p>With just a small pledge of $' . esc_html($locked_amount) . ', you can unlock this content and much more!</p>' .
               do_shortcode('[oauth_button]');
    }
}
add_filter('the_content', 'oauth_hide_content');


function oauth_authenticate_user() {
    if (isset($_GET['oauth_provider'])) {
        $provider = sanitize_text_field($_GET['oauth_provider']);
        $state = urlencode(home_url(add_query_arg([]))); 
        if ($provider == 'patreon') {
            $scopes = "identity identity[email] identity.memberships";
            $auth_url = "https://www.patreon.com/oauth2/authorize?response_type=code&client_id={$GLOBALS['patreon_client_id']}&redirect_uri=" . urlencode($GLOBALS['patreon_redirect_uri']) . "&scope=" . urlencode($scopes) . "&state={$state}";
            wp_redirect($auth_url);
            exit;
        } elseif ($provider == 'subscribestar') {
            $auth_url = "https://www.subscribestar.com/oauth2/authorize?client_id={$GLOBALS['subscribestar_client_id']}&redirect_uri=" . urlencode($GLOBALS['subscribestar_redirect_uri']) . "&response_type=code&scope=" . urlencode("content_provider_profile.read content_provider_profile.subscriptions.read") . "&state={$state}";
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
        $state = isset($_GET['state']) ? urldecode($_GET['state']) : home_url(); // Default to home if state is missing

        if ($provider == 'patreon' && $code) {
            // Exchange the authorization code for an access token
            $token = oauth_get_token('patreon', $code); // Fetch the token using a separate function
            if ($token) {
                // Use the token to get user information from Patreon
                $user_info = oauth_get_user_info('patreon', $token);
                if ($user_info) {
                    // Extract the user email and other details
                    $email = $user_info->data->attributes->email;
                    $nickname = $user_info->data->attributes->full_name;

                    // Check if user exists, if not, create one
                    $user = get_user_by('email', $email);
                    if (!$user) {
                        // Create the user if they don't exist
                        $user_id = wp_create_user($nickname, wp_generate_password(), $email);
                        if (!is_wp_error($user_id)) {
                            wp_update_user(['ID' => $user_id, 'role' => 'subscriber']);
                        }
                    } else {
                        update_user_meta($user->ID, 'oauth_access', true);
                    }

                    // Store the access token for future use
                    update_user_meta($user->ID, 'patreon_access_token', $token);

                    // Retrieve user pledge data
                    $pledge_data = oauth_get_user_pledge('patreon', $token);
                    if ($pledge_data) {
                        // Convert the pledge amount from cents to dollars
                        $pledge_amount = $pledge_data->included[0]->attributes->amount_cents / 100; 
                        update_user_meta($user->ID, 'user_pledge', $pledge_amount);

                        // Redirect back to the original state (page user was trying to access)
                        wp_redirect($state);
                        exit;
                    }
                }
                exit;
            }
        } elseif ($provider == 'subscribestar' && $code) {
            // Handle SubscribeStar token exchange similarly
            $access_token = oauth_get_token('subscribestar', $code);
            if ($access_token) {
                // Process the SubscribeStar access token and user data similarly
                update_user_meta(get_current_user_id(), 'oauth_access', true);
                update_user_meta(get_current_user_id(), 'subscribestar_access_token', $access_token);

                $subscription_data = oauth_get_user_subscription('subscribestar', $access_token);
                if ($subscription_data) {
                    $subscription_amount = $subscription_data->subscription_amount / 100; 
                    update_user_meta(get_current_user_id(), 'user_pledge', $subscription_amount);

                    wp_redirect($state);
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


function oauth_add_settings_page() {
    add_options_page('OAuth Settings', 'OAuth Settings', 'manage_options', 'oauth-settings', 'oauth_render_settings_page');
}
add_action('admin_menu', 'oauth_add_settings_page');

function oauth_enqueue_color_picker($hook_suffix) {
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('oauth_color_picker', plugins_url('oauth_color_picker.js', __FILE__), array('wp-color-picker'), false, true);
}
add_action('admin_enqueue_scripts', 'oauth_enqueue_color_picker');


function oauth_add_admin_notice($message, $type = 'error') {
    add_action('admin_notices', function() use ($message, $type) {
        ?>
        <div class="<?php echo esc_attr($type); ?>">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    });
}


function oauth_render_settings_page() {
    if (isset($_POST['save_settings'])) {
        update_option('locked_amount', sanitize_text_field($_POST['locked_amount']));
        update_option('lock_message', sanitize_text_field($_POST['lock_message']));
        update_option('patreon_join_url', sanitize_text_field($_POST['patreon_join_url']));
        update_option('patreon_checkout_url', sanitize_text_field($_POST['patreon_checkout_url']));

        $category_amounts = array_map('sanitize_text_field', $_POST['category_locked_amount']);
        $locked_amounts = [];
        foreach ($category_amounts as $cat_id => $amount) {
            $locked_amounts[$cat_id] = intval($amount);
        }
        update_option('category_locked_amounts', serialize($locked_amounts)); 

        oauth_add_admin_notice('Settings saved successfully!', 'updated');
    }

    $locked_amount = get_option('locked_amount', '5');
    $lock_message = get_option('lock_message', 'This content is locked.');
    $patreon_join_url = get_option('patreon_join_url', '');
    $patreon_checkout_url = get_option('patreon_checkout_url', '');
    $category_locked_amounts = unserialize(get_option('category_locked_amounts', serialize([])));

    ?>
    <div class="wrap" style="background-color: #f9f9f9; padding: 20px; border-radius: 8px;">
        <h1 style="font-family: 'Arial', sans-serif; color: #333;">OAuth Settings</h1>
        <form method="POST" style="max-width: 800px; margin: auto;">
            <div style="margin-bottom: 20px;">
                <label for="locked_amount" style="font-weight: bold;">Locked Amount ($):</label>
                <input type="text" id="locked_amount" name="locked_amount" value="<?php echo esc_attr($locked_amount); ?>" style="padding: 10px; border: 1px solid #ccc; border-radius: 5px; width: calc(100% - 22px);" required /><br />
            </div>
            <div style="margin-bottom: 20px;">
                <label for="lock_message" style="font-weight: bold;">Lock Message:</label>
                <input type="text" id="lock_message" name="lock_message" value="<?php echo esc_attr($lock_message); ?>" style="padding: 10px; border: 1px solid #ccc; border-radius: 5px; width: calc(100% - 22px);" required /><br />
            </div>
            <div style="margin-bottom: 20px;">
                <label for="patreon_join_url" style="font-weight: bold;">Patreon Join URL:</label>
                <input type="text" id="patreon_join_url" name="patreon_join_url" value="<?php echo esc_attr($patreon_join_url); ?>" style="padding: 10px; border: 1px solid #ccc; border-radius: 5px; width: calc(100% - 22px);" required /><br />
            </div>
            <div style="margin-bottom: 20px;">
                <label for="patreon_checkout_url" style="font-weight: bold;">Patreon Checkout URL:</label>
                <input type="text" id="patreon_checkout_url" name="patreon_checkout_url" value="<?php echo esc_attr($patreon_checkout_url); ?>" style="padding: 10px; border: 1px solid #ccc; border-radius: 5px; width: calc(100% - 22px);" required /><br />
            </div>
            <h3 style="color: #0073aa;">Category Locked Amounts:</h3>
            <div id="category-locked-amounts" style="margin-bottom: 20px;">
                <?php
                $categories = get_categories();
                foreach ($categories as $category) {
                    $amount = isset($category_locked_amounts[$category->term_id]) ? $category_locked_amounts[$category->term_id] : '';
                    echo '<div class="category-row" style="display: flex; align-items: center; margin-bottom: 10px;">';
                    echo '<label style="flex: 1;">' . esc_html($category->name) . ':</label>';
                    echo '<input type="text" name="category_locked_amount[' . esc_attr($category->term_id) . ']" value="' . esc_attr($amount) . '" style="flex: 2; padding: 8px; border: 1px solid #ccc; border-radius: 5px;" required />';
                    echo '<button type="button" class="remove-category" style="background-color: #FF2E3A; color: white; border: none; border-radius: 5px; padding: 5px; cursor: pointer; margin-left: 10px;">Remove</button>';
                    echo '</div>';
                }
                ?>
            </div>
            <button type="button" id="add-category" style="background-color: #28a745; color: white; border: none; border-radius: 5px; padding: 10px; cursor: pointer; margin-bottom: 20px;">Add Category</button>
            <input type="submit" name="save_settings" value="Save Settings" style="background-color: #0073aa; color: white; border: none; border-radius: 5px; padding: 10px; cursor: pointer;" />
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add Category Button
            document.getElementById('add-category').addEventListener('click', function() {
                const categoryRow = document.createElement('div');
                categoryRow.className = 'category-row';
                categoryRow.style.display = 'flex';
                categoryRow.style.alignItems = 'center';
                categoryRow.style.marginBottom = '10px';

                const label = document.createElement('label');
                label.style.flex = '1';
                label.textContent = 'New Category:';
                categoryRow.appendChild(label);

                const input = document.createElement('input');
                input.type = 'text';
                input.name = 'category_locked_amount[new_category]';
                input.style.flex = '2';
                input.style.padding = '8px';
                input.style.border = '1px solid #ccc';
                input.style.borderRadius = '5px';
                categoryRow.appendChild(input);

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'remove-category';
                removeButton.style.backgroundColor = '#FF2E3A';
                removeButton.style.color = 'white';
                removeButton.style.border = 'none';
                removeButton.style.borderRadius = '5px';
                removeButton.style.padding = '5px';
                removeButton.style.cursor = 'pointer';
                removeButton.style.marginLeft = '10px';
                removeButton.textContent = 'Remove';
                removeButton.addEventListener('click', function() {
                    categoryRow.remove();
                });
                categoryRow.appendChild(removeButton);

                document.getElementById('category-locked-amounts').appendChild(categoryRow);
            });

            // Remove Category Button
            document.querySelectorAll('.remove-category').forEach(button => {
                button.addEventListener('click', function() {
                    this.parentElement.remove();
                });
            });
        });
    </script>
    <?php
}





