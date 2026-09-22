<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function bsc_sessions_table() { global $wpdb; return $wpdb->prefix . 'bsc_sessions'; }
function bsc_events_table() { global $wpdb; return $wpdb->prefix . 'bsc_events'; }

function bsc_install_records() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sessions = bsc_sessions_table();
    $events = bsc_events_table();
    $collate = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE $sessions (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        token_hash char(64) NOT NULL,
        email varchar(254) NOT NULL,
        mobile varchar(30) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY token_hash (token_hash)
    ) $collate;" );
    dbDelta( "CREATE TABLE $events (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        session_id bigint(20) unsigned NOT NULL,
        search_id bigint(20) unsigned NOT NULL DEFAULT 0,
        event_type varchar(20) NOT NULL,
        payload longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY session_id (session_id)
    ) $collate;" );
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $sessions ) ) ) === $sessions &&
         $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $events ) ) ) === $events ) {
        update_option( 'bsc_records_version', '1' );
    }
}

function bsc_record( $session_id, $type, $payload, $search_id = 0 ) {
    global $wpdb;
    $saved = $wpdb->insert( bsc_events_table(), array(
        'session_id' => $session_id, 'search_id' => $search_id, 'event_type' => $type,
        'payload' => wp_json_encode( $payload ), 'created_at' => current_time( 'mysql', true ),
    ), array( '%d', '%d', '%s', '%s', '%s' ) );
    return false === $saved ? new WP_Error( 'bsc_save_failed', 'Could not save your query. Please try again.', array( 'status' => 503 ) ) : (int) $wpdb->insert_id;
}

function bsc_session( $request ) {
    global $wpdb;
    $token = $request->get_param( 'session' );
    if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
        return new WP_Error( 'bsc_session', 'Please enter your contact details to start a new chat.', array( 'status' => 401 ) );
    }
    $session = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . bsc_sessions_table() . ' WHERE token_hash = %s AND created_at >= %s', hash( 'sha256', $token ), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), ARRAY_A );
    if ( $wpdb->last_error ) { return new WP_Error( 'bsc_database', 'Chat is temporarily unavailable.', array( 'status' => 503 ) ); }
    return $session ? (int) $session['id'] : new WP_Error( 'bsc_session', 'Your chat has expired. Please enter your contact details again.', array( 'status' => 401 ) );
}

function bsc_record_time( $utc ) {
    return get_date_from_gmt( $utc, get_option( 'date_format' ) . ' H:i:s' );
}

function bsc_queries_page() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    global $wpdb;
    $session_id = absint( $_GET['session_id'] ?? 0 );
    echo '<div class="wrap"><h1>AskPilot Queries</h1><p>Dates and times use the WordPress site timezone (' . esc_html( wp_timezone_string() ) . ').</p>';
    if ( $session_id ) {
        $session = $wpdb->get_row( $wpdb->prepare( 'SELECT id, email, mobile, created_at FROM ' . bsc_sessions_table() . ' WHERE id = %d', $session_id ), ARRAY_A );
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=bsc-queries' ) ) . '">← All queries</a></p>';
        if ( ! $session ) { echo '<p>Chat not found.</p></div>'; return; }
        echo '<h2>Chat #' . esc_html( $session['id'] ) . '</h2><p><strong>Email:</strong> ' . esc_html( $session['email'] ) . '<br><strong>Mobile:</strong> ' . esc_html( $session['mobile'] ) . '<br><strong>Started:</strong> ' . esc_html( bsc_record_time( $session['created_at'] ) ) . '</p>';
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . bsc_events_table() . ' WHERE session_id = %d', $session_id ) );
        $events = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . bsc_events_table() . ' WHERE session_id = %d ORDER BY id ASC LIMIT 50 OFFSET %d', $session_id, ( $page - 1 ) * 50 ), ARRAY_A );
        echo '<table class="widefat striped"><thead><tr><th>Date / time</th><th>Activity</th><th>Full record</th></tr></thead><tbody>';
        foreach ( $events as $event ) {
            $payload = json_decode( $event['payload'], true );
            echo '<tr><td>' . esc_html( bsc_record_time( $event['created_at'] ) ) . '</td><td>' . esc_html( ucfirst( $event['event_type'] ) ) . ' #' . esc_html( $event['id'] );
            if ( $event['search_id'] ) { echo '<br>Search #' . esc_html( $event['search_id'] ); }
            echo '</td><td>';
            foreach ( array( 'phrase' => 'Search phrase', 'question' => 'Selected question', 'reply' => 'Answer / message' ) as $key => $label ) {
                if ( isset( $payload[$key] ) ) { echo '<p><strong>' . esc_html( $label ) . ':</strong><br>' . nl2br( esc_html( $payload[$key] ) ) . '</p>'; }
            }
            if ( isset( $payload['questions'] ) ) {
                echo '<strong>Matching questions:</strong><ul>';
                foreach ( $payload['questions'] as $question ) { echo '<li>' . esc_html( $question['question'] ) . '</li>'; }
                if ( ! $payload['questions'] ) { echo '<li>No matches</li>'; }
                echo '</ul>';
            }
            echo '</td></tr>';
        }
        if ( ! $events ) { echo '<tr><td colspan="3">No queries yet.</td></tr>'; }
        echo '</tbody></table>';
        bsc_queries_pagination( $page, $count, 50, array( 'session_id' => $session_id ) );
    } else {
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . bsc_sessions_table() );
        $sessions = $wpdb->get_results( $wpdb->prepare( 'SELECT id, email, mobile, created_at FROM ' . bsc_sessions_table() . ' ORDER BY id DESC LIMIT 20 OFFSET %d', ( $page - 1 ) * 20 ), ARRAY_A );
        echo '<table class="widefat striped"><thead><tr><th>Date / time</th><th>Email</th><th>Mobile</th><th>Queries</th></tr></thead><tbody>';
        foreach ( $sessions as $session ) {
            echo '<tr><td>' . esc_html( bsc_record_time( $session['created_at'] ) ) . '</td><td>' . esc_html( $session['email'] ) . '</td><td>' . esc_html( $session['mobile'] ) . '</td><td><a href="' . esc_url( add_query_arg( array( 'page' => 'bsc-queries', 'session_id' => $session['id'] ), admin_url( 'admin.php' ) ) ) . '">View full record</a></td></tr>';
        }
        if ( ! $sessions ) { echo '<tr><td colspan="4">No chats recorded yet.</td></tr>'; }
        echo '</tbody></table>';
        bsc_queries_pagination( $page, $count, 20 );
    }
    echo '</div>';
}

function bsc_queries_pagination( $page, $count, $size, $extra = array() ) {
    echo '<p>';
    foreach ( array( $page - 1 => 'Previous', $page + 1 => 'Next' ) as $number => $label ) {
        if ( $number >= 1 && $number <= (int) ceil( $count / $size ) ) {
            echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( array( 'page' => 'bsc-queries', 'paged' => $number ), $extra ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a> ';
        }
    }
    echo '</p>';
}
