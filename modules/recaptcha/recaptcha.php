<?php

wpcf7_include_module_file( 'recaptcha/service.php' );

add_action( 'wpcf7_init', 'wpcf7_recaptcha_register_service', 15, 0 );

function wpcf7_recaptcha_register_service() {
	$integration = WPCF7_Integration::get_instance();

	$integration->add_service( 'recaptcha',
		WPCF7_RECAPTCHA::get_instance()
	);
}

add_action( 'wp_enqueue_scripts', 'wpcf7_recaptcha_enqueue_scripts', 20, 0 );

function wpcf7_recaptcha_enqueue_scripts() {
	$service = WPCF7_RECAPTCHA::get_instance();

	if ( ! $service->is_active() ) {
		return;
	}

	$url = 'https://www.google.com/recaptcha/api.js';

	if ( apply_filters( 'wpcf7_use_recaptcha_net', false ) ) {
		$url = 'https://www.recaptcha.net/recaptcha/api.js';
	}

	wp_enqueue_script( 'google-recaptcha',
		add_query_arg(
			array(
				'render' => $service->get_sitekey(),
			),
			$url
		),
		array(),
		'3.0',
		true
	);

	$assets = array();
	$asset_file = wpcf7_plugin_path( 'modules/recaptcha/index.asset.php' );

	if ( file_exists( $asset_file ) ) {
		$assets = include( $asset_file );
	}

	$assets = wp_parse_args( $assets, array(
		'src' => wpcf7_plugin_url( 'modules/recaptcha/index.js' ),
		'dependencies' => array(
			'google-recaptcha',
			'wp-polyfill',
		),
		'version' => WPCF7_VERSION,
		'in_footer' => true,
	) );

	wp_register_script(
		'wpcf7-recaptcha',
		$assets['src'],
		$assets['dependencies'],
		$assets['version'],
		$assets['in_footer']
	);

	wp_enqueue_script( 'wpcf7-recaptcha' );

	wp_localize_script( 'wpcf7-recaptcha',
		'wpcf7_recaptcha',
		array(
			'sitekey' => $service->get_sitekey(),
			'actions' => apply_filters( 'wpcf7_recaptcha_actions', array(
				'homepage' => 'homepage',
				'contactform' => 'contactform',
			) ),
		)
	);
}

add_filter( 'wpcf7_form_hidden_fields',
	'wpcf7_recaptcha_add_hidden_fields', 100, 1
);

function wpcf7_recaptcha_add_hidden_fields( $fields ) {
	$service = WPCF7_RECAPTCHA::get_instance();

	if ( ! $service->is_active() ) {
		return $fields;
	}

	return array_merge( $fields, array(
		'_wpcf7_recaptcha_response' => '',
	) );
}

add_filter( 'wpcf7_spam', 'wpcf7_recaptcha_verify_response', 9, 2 );

function wpcf7_recaptcha_verify_response( $spam, $submission ) {
	if ( $spam ) {
		return $spam;
	}

	$service = WPCF7_RECAPTCHA::get_instance();

	if ( ! $service->is_active() ) {
		return $spam;
	}

	$token = isset( $_POST['_wpcf7_recaptcha_response'] )
		? trim( $_POST['_wpcf7_recaptcha_response'] ) : '';

	if ( $service->verify( $token ) ) { // Human
		$spam = false;
	} else { // Bot
		$spam = true;

		if ( '' === $token ) {
			$submission->add_spam_log( array(
				'agent' => 'recaptcha',
				'reason' => __( 'reCAPTCHA response token is empty.', 'contact-form-7' ),
			) );
		} else {
			$submission->add_spam_log( array(
				'agent' => 'recaptcha',
				'reason' => sprintf(
					__( 'reCAPTCHA score (%1$.2f) is lower than the threshold (%2$.2f).', 'contact-form-7' ),
					$service->get_last_score(),
					$service->get_threshold()
				),
			) );
		}
	}

	return $spam;
}

add_action( 'wpcf7_init', 'wpcf7_recaptcha_add_form_tag_recaptcha', 10, 0 );

function wpcf7_recaptcha_add_form_tag_recaptcha() {
	$service = WPCF7_RECAPTCHA::get_instance();

	if ( ! $service->is_active() ) {
		return;
	}

	wpcf7_add_form_tag( 'recaptcha',
		'__return_empty_string', // no output
		array( 'display-block' => true )
	);
}

add_action( 'wpcf7_upgrade', 'wpcf7_upgrade_recaptcha_v2_v3', 10, 2 );

function wpcf7_upgrade_recaptcha_v2_v3( $new_ver, $old_ver ) {
	if ( version_compare( '5.1-dev', $old_ver, '<=' ) ) {
		return;
	}

	$service = WPCF7_RECAPTCHA::get_instance();

	if ( ! $service->is_active() or $service->get_global_sitekey() ) {
		return;
	}

	// Maybe v2 keys are used now. Warning necessary.
	WPCF7::update_option( 'recaptcha_v2_v3_warning', true );
	WPCF7::update_option( 'recaptcha', null );
}

add_action( 'wpcf7_admin_menu', 'wpcf7_admin_init_recaptcha_v2_v3', 10, 0 );

function wpcf7_admin_init_recaptcha_v2_v3() {
	if ( ! WPCF7::get_option( 'recaptcha_v2_v3_warning' ) ) {
		return;
	}

	add_filter( 'wpcf7_admin_menu_change_notice',
		'wpcf7_admin_menu_change_notice_recaptcha_v2_v3', 10, 1 );

	add_action( 'wpcf7_admin_warnings',
		'wpcf7_admin_warnings_recaptcha_v2_v3', 5, 3 );
}

function wpcf7_admin_menu_change_notice_recaptcha_v2_v3( $counts ) {
	$counts['wpcf7-integration'] += 1;
	return $counts;
}

function wpcf7_admin_warnings_recaptcha_v2_v3( $page, $action, $object ) {
	if ( 'wpcf7-integration' !== $page ) {
		return;
	}

	$message = sprintf(
		esc_html( __( "API keys for reCAPTCHA v3 are different from those for v2; keys for v2 don&#8217;t work with the v3 API. You need to register your sites again to get new keys for v3. For details, see %s.", 'contact-form-7' ) ),
		wpcf7_link(
			__( 'https://contactform7.com/recaptcha/', 'contact-form-7' ),
			__( 'reCAPTCHA (v3)', 'contact-form-7' )
		)
	);

	echo sprintf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		$message
	);
}
