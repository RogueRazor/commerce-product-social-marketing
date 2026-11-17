<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPSM_Providers {

	private static $instances = null;

	public static function all() {
		if ( null === self::$instances ) {
			self::$instances = array(
				'facebook'  => new CPSM_Provider_Facebook(),
				'instagram' => new CPSM_Provider_Instagram(),
				'google'    => new CPSM_Provider_Google(),
			);
		}
		return self::$instances;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function enabled() {
		$enabled = array();
		foreach ( self::all() as $provider ) {
			if ( $provider->enabled() ) {
				$enabled[] = $provider;
			}
		}
		return $enabled;
	}
}
