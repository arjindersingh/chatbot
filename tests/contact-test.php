<?php
// Lightweight WordPress substitutes to exercise the plugin's contact gate.
define( 'ABSPATH', __DIR__ );
function register_activation_hook( $file, $callback ) {}
function add_action( $hook, $callback ) {}
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
class WP_Error {
    public $code;
    public function __construct( $code, $message, $data ) { $this->code = $code; }
}
class ContactRequest {
    private $data;
    public function __construct( $data ) { $this->data = $data; }
    public function get_param( $key ) { return $this->data[$key] ?? null; }
}
require __DIR__ . '/../askpilot/askpilot.php';
$cases = array(
    array( array(), false ),
    array( array( 'email' => 'person@example.com' ), false ),
    array( array( 'mobile' => '+1 555 123 4567' ), false ),
    array( array( 'email' => 'invalid', 'mobile' => '1234567890' ), false ),
    array( array( 'email' => array(), 'mobile' => '1234567890' ), false ),
    array( array( 'email' => 'person@example.com', 'mobile' => array() ), false ),
    array( array( 'email' => 'person@example.com', 'mobile' => '123456' ), false ),
    array( array( 'email' => 'person@example.com', 'mobile' => '1234567890123456' ), false ),
    array( array( 'email' => 'person@example.com', 'mobile' => '123abc4567' ), false ),
    array( array( 'email' => 'person@example.com', 'mobile' => '+1 (555) 123-4567' ), true ),
    array( array( 'email' => ' person@example.com ', 'mobile' => ' 9876543210 ' ), true ),
);
foreach ( $cases as $i => list( $data, $expected ) ) {
    if ( ( true === bsc_validate_contact( new ContactRequest( $data ) ) ) !== $expected ) {
        fwrite( STDERR, "Contact validation case $i failed\n" ); exit( 1 );
    }
}
echo count( $cases ) . " contact validation tests passed.\n";
