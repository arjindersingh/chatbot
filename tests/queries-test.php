<?php
// Exercise actual route callbacks against an in-memory WordPress/database test double.
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
$hooks = $routes = array();
function add_action( $hook, $callback ) { $GLOBALS['hooks'][$hook][] = $callback; }
function register_activation_hook( $file, $callback ) {}
function register_rest_route( $namespace, $path, $config ) { $GLOBALS['routes'][$path] = $config['callback']; }
function get_option( $name, $default = false ) { return $default; }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_salt( $context ) { return 'test-salt'; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl ) {}
function current_time( $type, $utc ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
class WP_Error {
    public $code;
    public function __construct( $code, $message, $data ) { $this->code = $code; }
}
class WP_REST_Response {
    public $data;
    public function __construct( $data ) { $this->data = $data; }
    public function header( $name, $value ) {}
}
class Request {
    private $data;
    public function __construct( $data ) { $this->data = $data; }
    public function get_param( $name ) { return $this->data[$name] ?? null; }
}
class TestDatabase {
    public $prefix = 'wp_', $last_error = '', $insert_id = 0, $rows = array(), $fail = false;
    public $faq = array( 'id' => 7, 'question' => 'What are opening hours?', 'answer' => 'Nine to five.' );
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function insert( $table, $row, $formats ) {
        if ( $this->fail ) { return false; }
        $row['id'] = ++$this->insert_id;
        $this->rows[$table][$row['id']] = $row;
        return 1;
    }
    public function get_row( $query, $format ) {
        list( $sql, $args ) = $query;
        if ( strpos( $sql, 'bsc_sessions' ) !== false ) {
            foreach ( $this->rows['wp_bsc_sessions'] ?? array() as $row ) {
                if ( $row['token_hash'] === $args[0] && $row['created_at'] >= $args[1] ) { return $row; }
            }
            return null;
        }
        return $this->faq && $this->faq['id'] === (int) $args[0] ? $this->faq : null;
    }
    public function get_results( $sql, $format ) { return $this->faq ? array( $this->faq ) : array(); }
    public function get_var( $query ) {
        list( $sql, $args ) = $query;
        $row = $this->rows['wp_bsc_events'][$args[0]] ?? null;
        return $row && $row['session_id'] === $args[1] && $row['event_type'] === 'search' ? $row['payload'] : null;
    }
}
$wpdb = new TestDatabase();
require __DIR__ . '/../askpilot/askpilot.php';
foreach ( $hooks['rest_api_init'] as $callback ) { $callback(); }
function call_route( $path, $data ) { return $GLOBALS['routes'][$path]( new Request( $data ) ); }
$count = 0;
function check( $condition, $label ) {
    ++$GLOBALS['count'];
    if ( ! $condition ) { throw new Exception( $label ); }
}
$start = call_route( '/start', array( 'email' => 'person@example.com', 'mobile' => '+15551234567' ) );
check( $start instanceof WP_REST_Response, 'Contact starts a session' );
$token = $start->data['session'];
$session = array_values( $wpdb->rows['wp_bsc_sessions'] )[0];
check( $session['email'] === 'person@example.com' && $session['mobile'] === '+15551234567', 'Contact is saved' );
check( $session['token_hash'] === hash( 'sha256', $token ) && $session['created_at'], 'Only token hash stored, with timestamp' );
check( is_wp_error( call_route( '/message', array( 'message' => 'hours' ) ) ), 'Search requires session' );
$search = call_route( '/message', array( 'session' => $token, 'message' => 'hours' ) )->data;
check( $search['questions'][0] === array( 'id' => 7, 'question' => 'What are opening hours?' ), 'Search returns choices without answers' );
$event = $wpdb->rows['wp_bsc_events'][$search['search_id']];
check( $event['session_id'] === $session['id'] && json_decode( $event['payload'], true )['phrase'] === 'hours' && $event['created_at'], 'Search is saved with visitor and timestamp' );
$answer = call_route( '/answer', array( 'session' => $token, 'id' => 7, 'search_id' => $search['search_id'] ) );
check( $answer->data['reply'] === 'Nine to five.', 'Selected answer returned' );
$snapshot = end( $wpdb->rows['wp_bsc_events'] );
$wpdb->faq['answer'] = 'Changed later';
check( json_decode( $snapshot['payload'], true )['reply'] === 'Nine to five.' && $snapshot['search_id'] === $search['search_id'], 'Answer snapshot preserves original and search link' );
$empty = call_route( '/message', array( 'session' => $token, 'message' => 'unknown' ) )->data;
check( !$empty['questions'] && isset( $wpdb->rows['wp_bsc_events'][$empty['search_id']] ), 'No-match searches saved' );
$other = call_route( '/start', array( 'email' => 'other@example.com', 'mobile' => '+15551234568' ) )->data['session'];
check( is_wp_error( call_route( '/answer', array( 'session' => $other, 'id' => 7, 'search_id' => $search['search_id'] ) ) ), 'Cannot use another visitor search' );
check( is_wp_error( call_route( '/answer', array( 'session' => $token, 'id' => 8, 'search_id' => $search['search_id'] ) ) ), 'Cannot select unlisted answer' );
$wpdb->fail = true;
check( is_wp_error( call_route( '/message', array( 'session' => $token, 'message' => 'hours' ) ) ), 'Failed save is reported' );
echo "$count query workflow tests passed.\n";
