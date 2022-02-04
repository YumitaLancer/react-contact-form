<?php

class WPCF7_SWV_MaxFileSizeRule extends WPCF7_SWV_Rule {

	const rule_name = 'maxfilesize';

	public function match( $context ) {
		if ( false === parent::match( $context ) ) {
			return false;
		}

		if ( empty( $context['file'] ) ) {
			return false;
		}

		return true;
	}

	public function validate( $context ) {
		$field = $this->get_property( 'field' );
		$input = isset( $_FILES[$field]['size'] ) ? $_FILES[$field]['size'] : '';
		$input = wpcf7_array_flatten( $input );
		$input = wpcf7_exclude_blank( $input );

		if ( empty( $input ) ) {
			return true;
		}

		$threshold = $this->get_property( 'threshold' );

		if ( $threshold < array_sum( $input ) ) {
			return new WP_Error( 'wpcf7_invalid_maxfilesize',
				$this->get_property( 'message' )
			);
		}

		return true;
	}

	public function to_array() {
		return array( 'rule' => self::rule_name ) + (array) $this->properties;
	}
}
