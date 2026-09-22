<?php
/** Small, dependency-free FAQ matcher. */
class BSC_Matcher {
    public static function normalize( $text ) {
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        return trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
    }

    /** Return question choices only; answers are fetched after selection. */
    public static function questions( $phrase, $entries ) {
        $phrase = self::normalize( $phrase );
        $matches = array();
        if ( '' === $phrase ) { return $matches; }
        foreach ( $entries as $entry ) {
            if ( false !== strpos( self::normalize( $entry['question'] ), $phrase ) ) {
                $matches[] = array( 'id' => (int) $entry['id'], 'question' => $entry['question'] );
            }
        }
        return $matches;
    }
}
