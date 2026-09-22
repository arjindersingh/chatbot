<?php
require __DIR__ . '/../askpilot/includes/class-bsc-matcher.php';
$entries = array(
    array( 'id' => 1, 'question' => 'What are your opening hours?', 'answer' => 'Private until selected' ),
    array( 'id' => 2, 'question' => 'Do opening hours change on holidays?' ),
    array( 'id' => 3, 'question' => 'How can I contact support?' ),
);
$cases = array(
    array( 'opening hours', array( 1, 2 ) ),
    array( 'OPENING HOURS', array( 1, 2 ) ),
    array( ' opening   hours! ', array( 1, 2 ) ),
    array( 'hours opening', array() ),
    array( 'holiday', array( 2 ) ),
    array( 'contact support', array( 3 ) ),
    array( 'weather', array() ),
    array( '', array() ),
    array( '!!!', array() ),
);
foreach ( $cases as list( $query, $expected ) ) {
    $actual = BSC_Matcher::questions( $query, $entries );
    if ( array_column( $actual, 'id' ) !== $expected ) { fwrite( STDERR, "Failed: $query\n" ); exit( 1 ); }
    foreach ( $actual as $choice ) {
        if ( array_keys( $choice ) !== array( 'id', 'question' ) ) { throw new Exception( 'Search leaked answer data' ); }
    }
}
echo "9 phrase-search tests passed.\n";
