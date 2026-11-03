<?php
/**
 * Small local log of recent submissions, so a lead is never lost if the GHL webhook fails.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGWC_Leads {

	const OPTION = 'dgwc_leads';
	const MAX    = 100;

	public static function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function add( $lead ) {
		$list = self::all();
		array_unshift( $list, $lead );
		$list = array_slice( $list, 0, self::MAX );
		update_option( self::OPTION, $list, 'no' );
	}

	public static function clear() {
		update_option( self::OPTION, array(), 'no' );
	}
}
