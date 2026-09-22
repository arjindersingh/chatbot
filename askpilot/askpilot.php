<?php
/**
 * Plugin Name: AskPilot
 * Description: A simple floating chatbot with an editable FAQ knowledge base and phrase search.
 * Version: 0.4.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: askpilot
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/includes/class-bsc-matcher.php';
require_once __DIR__ . '/includes/records.php';

function bsc_defaults() {
    return array( 'enabled' => 1, 'title' => 'AskPilot', 'welcome' => 'Hi! Enter a phrase to find questions, then choose one to see its answer.', 'fallback' => 'Sorry, I do not have an answer yet. Please contact us directly.' );
}
function bsc_settings() { return wp_parse_args( get_option( 'bsc_settings', array() ), bsc_defaults() ); }
function bsc_table() { global $wpdb; return $wpdb->prefix . 'bsc_answers'; }
function bsc_activate( $network_wide ) {
    if ( $network_wide ) { wp_die( 'Please activate AskPilot separately on each site.' ); }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = bsc_table();
    $collate = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        question varchar(255) NOT NULL,
        keywords text NOT NULL,
        answer text NOT NULL,
        PRIMARY KEY  (id)
    ) $collate;" );
    add_option( 'bsc_settings', bsc_defaults() );
    bsc_install_records();
}
register_activation_hook( __FILE__, 'bsc_activate' );
add_action( 'plugins_loaded', function () {
    if ( '1' !== get_option( 'bsc_records_version' ) ) { bsc_install_records(); }
} );

add_action( 'admin_menu', function () {
    add_menu_page( 'AskPilot', 'AskPilot', 'manage_options', 'bsc-chatbot', 'bsc_admin_page', 'dashicons-format-chat' );
    add_submenu_page( 'bsc-chatbot', 'Queries', 'Queries', 'manage_options', 'bsc-queries', 'bsc_queries_page' );
} );

add_action( 'admin_post_bsc_save', function () {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'bsc_save' );
    global $wpdb;
    $operation = isset( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
    $result = true;
    if ( 'settings' === $operation ) {
        $settings = bsc_defaults();
        $settings['enabled'] = isset( $_POST['enabled'] ) ? 1 : 0;
        foreach ( array( 'title', 'welcome', 'fallback' ) as $field ) {
            $value = isset( $_POST[$field] ) && is_string( $_POST[$field] ) ? sanitize_textarea_field( wp_unslash( $_POST[$field] ) ) : '';
            if ( '' !== $value ) { $settings[$field] = $value; }
        }
        update_option( 'bsc_settings', $settings );
    } elseif ( 'delete' === $operation ) {
        $result = $wpdb->delete( bsc_table(), array( 'id' => absint( $_POST['id'] ?? 0 ) ), array( '%d' ) );
    } elseif ( 'answer' === $operation ) {
        $data = array();
        foreach ( array( 'question', 'keywords', 'answer' ) as $field ) {
            $data[$field] = isset( $_POST[$field] ) && is_string( $_POST[$field] ) ? sanitize_textarea_field( wp_unslash( $_POST[$field] ) ) : '';
        }
        if ( '' === $data['question'] || '' === $data['answer'] || strlen( $data['question'] ) > 255 || strlen( $data['answer'] ) > 10000 || strlen( $data['keywords'] ) > 1000 ) {
            wp_die( 'Enter a question (up to 255 bytes), an answer (up to 10,000 bytes), and optional keywords (up to 1,000 bytes).' );
        }
        $id = absint( $_POST['id'] ?? 0 );
        $result = $id ? $wpdb->update( bsc_table(), $data, array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) ) : $wpdb->insert( bsc_table(), $data, array( '%s', '%s', '%s' ) );
    } else { wp_die( 'Unknown action.' ); }
    if ( false === $result ) { wp_die( 'Could not save changes. Please try again.' ); }
    wp_safe_redirect( admin_url( 'admin.php?page=bsc-chatbot&saved=1' ) );
    exit;
} );

function bsc_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    global $wpdb;
    $settings = bsc_settings();
    $entries = $wpdb->get_results( 'SELECT * FROM ' . bsc_table() . ' ORDER BY id ASC', ARRAY_A );
    $edit = array( 'id' => 0, 'question' => '', 'keywords' => '', 'answer' => '' );
    foreach ( $entries as $entry ) { if ( (int) $entry['id'] === absint( $_GET['edit'] ?? 0 ) ) { $edit = $entry; } }
    ?>
    <div class="wrap">
        <h1>AskPilot</h1>
        <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Changes saved.</p></div><?php endif; ?>
        <p>Add answers below, then visitors can use the chat button on your website. Answers are public. No AI account is required.</p>
        <h2>Widget settings</h2>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <?php wp_nonce_field( 'bsc_save' ); ?><input type="hidden" name="action" value="bsc_save"><input type="hidden" name="operation" value="settings">
            <p><label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'], 1 ); ?>> Show chatbot on the website</label></p>
            <?php foreach ( array( 'title' => 'Chat title', 'welcome' => 'Welcome message', 'fallback' => 'Fallback answer' ) as $key => $label ) : ?>
                <p><label for="bsc-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
                <input class="large-text" id="bsc-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $settings[$key] ); ?>" maxlength="1000" required></p>
            <?php endforeach; submit_button( 'Save settings' ); ?>
        </form>
        <hr><h2><?php echo $edit['id'] ? 'Edit answer' : 'Add an answer'; ?></h2>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <?php wp_nonce_field( 'bsc_save' ); ?><input type="hidden" name="action" value="bsc_save"><input type="hidden" name="operation" value="answer"><input type="hidden" name="id" value="<?php echo esc_attr( $edit['id'] ); ?>">
            <p><label for="bsc-question">Question</label><br><input class="large-text" id="bsc-question" name="question" maxlength="255" required value="<?php echo esc_attr( $edit['question'] ); ?>"></p>
            <p><label for="bsc-keywords">Keywords (reserved for future use; current search uses question text)</label><br><input class="large-text" id="bsc-keywords" name="keywords" maxlength="1000" value="<?php echo esc_attr( $edit['keywords'] ); ?>"></p>
            <p><label for="bsc-answer">Answer (plain text)</label><br><textarea class="large-text" rows="4" id="bsc-answer" name="answer" maxlength="10000" required><?php echo esc_textarea( $edit['answer'] ); ?></textarea></p>
            <?php submit_button( $edit['id'] ? 'Update answer' : 'Add answer' ); ?>
            <?php if ( $edit['id'] ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=bsc-chatbot' ) ); ?>">Cancel editing</a><?php endif; ?>
        </form>
        <h2>Knowledge base</h2>
        <table class="widefat striped"><thead><tr><th>Question / keywords</th><th>Answer</th><th>Actions</th></tr></thead><tbody>
        <?php if ( ! $entries ) : ?><tr><td colspan="3">No answers yet. Add your first question and answer above.</td></tr><?php endif; ?>
        <?php foreach ( $entries as $entry ) : ?>
            <tr><td><?php echo esc_html( $entry['question'] ); ?><br><small><?php echo esc_html( $entry['keywords'] ); ?></small></td><td><?php echo nl2br( esc_html( $entry['answer'] ) ); ?></td><td>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bsc-chatbot&edit=' . $entry['id'] ) ); ?>">Edit</a>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <?php wp_nonce_field( 'bsc_save' ); ?><input type="hidden" name="action" value="bsc_save"><input type="hidden" name="operation" value="delete"><input type="hidden" name="id" value="<?php echo esc_attr( $entry['id'] ); ?>"><button class="button-link-delete" type="submit">Delete</button>
                </form>
            </td></tr>
        <?php endforeach; ?></tbody></table>
    </div>
    <?php
}

/** Validate contact details before starting a chat or answering a question. */
function bsc_validate_contact( $request ) {
    $email = $request->get_param( 'email' );
    $mobile = $request->get_param( 'mobile' );
    if ( ! is_string( $email ) || strlen( $email ) > 254 || ! is_email( trim( $email ) ) ) {
        return new WP_Error( 'bsc_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
    }
    if ( ! is_string( $mobile ) || strlen( $mobile ) > 30 || ! preg_match( '/^\+?[0-9 ()-]+$/D', trim( $mobile ) ) ) {
        return new WP_Error( 'bsc_mobile', 'Please enter a valid mobile number.', array( 'status' => 400 ) );
    }
    $digits = preg_replace( '/[^0-9]/', '', $mobile );
    if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
        return new WP_Error( 'bsc_mobile', 'Use 7 to 15 digits for your mobile number, including the country code if needed.', array( 'status' => 400 ) );
    }
    return true;
}

/** Shared contact and rate gate for FAQ searches and selected answers. */
function bsc_rate_limit() {
    $key = 'bsc_rate_' . hash_hmac( 'sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', wp_salt( 'auth' ) );
    $rate = get_transient( $key );
    if ( ! is_array( $rate ) || $rate['until'] <= time() ) { $rate = array( 'count' => 0, 'until' => time() + 60 ); }
    if ( $rate['count'] >= 20 ) { return new WP_Error( 'bsc_rate', 'Please wait a minute before sending more messages.', array( 'status' => 429 ) ); }
    ++$rate['count'];
    set_transient( $key, $rate, max( 1, $rate['until'] - time() ) );
    return true;
}
function bsc_chat_access( $request ) {
    if ( ! bsc_settings()['enabled'] ) { return new WP_Error( 'bsc_disabled', 'Chat is unavailable.', array( 'status' => 403 ) ); }
    $limit = bsc_rate_limit();
    if ( is_wp_error( $limit ) ) { return $limit; }
    return bsc_session( $request );
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'askpilot/v1', '/start', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function ( $request ) {
            if ( ! bsc_settings()['enabled'] ) { return new WP_Error( 'bsc_disabled', 'Chat is unavailable.', array( 'status' => 403 ) ); }
            $valid = bsc_validate_contact( $request );
            if ( is_wp_error( $valid ) ) { return $valid; }
            $limit = bsc_rate_limit();
            if ( is_wp_error( $limit ) ) { return $limit; }
            global $wpdb;
            $token = bin2hex( random_bytes( 32 ) );
            $saved = $wpdb->insert( bsc_sessions_table(), array(
                'token_hash' => hash( 'sha256', $token ), 'email' => trim( $request->get_param( 'email' ) ),
                'mobile' => trim( $request->get_param( 'mobile' ) ), 'created_at' => current_time( 'mysql', true ),
            ), array( '%s', '%s', '%s', '%s' ) );
            if ( false === $saved ) { return new WP_Error( 'bsc_save_failed', 'Could not start your chat. Please try again.', array( 'status' => 503 ) ); }
            $recorded = bsc_record( (int) $wpdb->insert_id, 'start', array( 'reply' => bsc_settings()['welcome'] ) );
            if ( is_wp_error( $recorded ) ) { return $recorded; }
            $response = new WP_REST_Response( array( 'ready' => true, 'session' => $token, 'welcome' => bsc_settings()['welcome'] ) );
            $response->header( 'Cache-Control', 'no-store' );
            return $response;
        },
    ) );
    register_rest_route( 'askpilot/v1', '/answer', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'args' => array( 'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ), 'search_id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ) ),
        'callback' => function ( $request ) {
            $valid = bsc_chat_access( $request );
            if ( is_wp_error( $valid ) ) { return $valid; }
            global $wpdb;
            $search = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . bsc_events_table() . " WHERE id = %d AND session_id = %d AND event_type = 'search'", $request->get_param( 'search_id' ), $valid ) );
            if ( $wpdb->last_error ) { return new WP_Error( 'bsc_database', 'Chat is temporarily unavailable.', array( 'status' => 503 ) ); }
            $search_data = $search ? json_decode( $search, true ) : array();
            $ids = array_column( $search_data['questions'] ?? array(), 'id' );
            if ( ! in_array( (int) $request->get_param( 'id' ), $ids, true ) ) { return new WP_Error( 'bsc_selection', 'Please choose a question from your search results.', array( 'status' => 400 ) ); }
            $entry = $wpdb->get_row( $wpdb->prepare( 'SELECT question, answer FROM ' . bsc_table() . ' WHERE id = %d', $request->get_param( 'id' ) ), ARRAY_A );
            if ( $wpdb->last_error ) { return new WP_Error( 'bsc_database', 'Chat is temporarily unavailable.', array( 'status' => 503 ) ); }
            if ( ! $entry ) {
                $selected = array_values( array_filter( $search_data['questions'], function ( $question ) use ( $request ) { return $question['id'] === (int) $request->get_param( 'id' ); } ) );
                $recorded = bsc_record( $valid, 'unavailable', array( 'question' => $selected[0]['question'], 'reply' => 'This question is no longer available. Please search again.' ), (int) $request->get_param( 'search_id' ) );
                if ( is_wp_error( $recorded ) ) { return $recorded; }
                return new WP_Error( 'bsc_missing', 'This question is no longer available. Please search again.', array( 'status' => 404 ) );
            }
            $recorded = bsc_record( $valid, 'answer', array( 'question' => $entry['question'], 'reply' => $entry['answer'], 'question_id' => (int) $request->get_param( 'id' ) ), (int) $request->get_param( 'search_id' ) );
            if ( is_wp_error( $recorded ) ) { return $recorded; }
            $response = new WP_REST_Response( array( 'question' => $entry['question'], 'reply' => $entry['answer'] ) );
            $response->header( 'Cache-Control', 'no-store' );
            return $response;
        },
    ) );
    register_rest_route( 'askpilot/v1', '/message', array(
        'methods' => 'POST',
        // Deliberately public: visitors only receive published FAQ answers.
        'permission_callback' => '__return_true',
        'args' => array( 'message' => array( 'required' => true, 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ) ),
        'callback' => function ( $request ) {
            $settings = bsc_settings();
            $valid = bsc_chat_access( $request );
            if ( is_wp_error( $valid ) ) { return $valid; }
            $message = trim( $request->get_param( 'message' ) );
            if ( '' === trim( $message ) ) { return new WP_Error( 'bsc_empty', 'Please enter a message.', array( 'status' => 400 ) ); }
            global $wpdb;
            $entries = $wpdb->get_results( 'SELECT id, question FROM ' . bsc_table() . ' ORDER BY id ASC', ARRAY_A );
            if ( null === $entries || $wpdb->last_error ) { return new WP_Error( 'bsc_database', 'Chat is temporarily unavailable.', array( 'status' => 503 ) ); }
            $questions = BSC_Matcher::questions( $message, $entries );
            $reply = $questions ? 'Choose a question to see its answer:' : 'No matching questions found. Try another phrase.' . "\n" . $settings['fallback'];
            $recorded = bsc_record( $valid, 'search', array( 'phrase' => $message, 'questions' => $questions, 'reply' => $reply ) );
            if ( is_wp_error( $recorded ) ) { return $recorded; }
            $response = new WP_REST_Response( array( 'questions' => $questions, 'fallback' => $settings['fallback'], 'search_id' => $recorded ) );
            $response->header( 'Cache-Control', 'no-store' );
            return $response;
        },
    ) );
} );

add_action( 'wp_enqueue_scripts', function () {
    $settings = bsc_settings();
    if ( ! $settings['enabled'] ) { return; }
    wp_enqueue_style( 'bsc-widget', plugins_url( 'assets/chatbot.css', __FILE__ ), array(), '0.4.0' );
    wp_enqueue_script( 'bsc-widget', plugins_url( 'assets/chatbot.js', __FILE__ ), array(), '0.4.0', true );
    wp_localize_script( 'bsc-widget', 'bscChat', array( 'answerEndpoint' => rest_url( 'askpilot/v1/answer' ), 'startEndpoint' => rest_url( 'askpilot/v1/start' ), 'endpoint' => rest_url( 'askpilot/v1/message' ), 'title' => $settings['title'], 'welcome' => $settings['welcome'] ) );
} );
