<?php
/**
 * Plugin Name: Ehmar mail identity
 * Description: Every email the store sends (password resets, orders) goes out as the store address, with a matching envelope sender so SPF can align.
 */

const EHMAR_MAIL_FROM = 'info@ehmar.com';

add_filter( 'wp_mail_from', fn() => EHMAR_MAIL_FROM );
add_filter( 'wp_mail_from_name', fn() => 'Ehmar' );

// Without an explicit envelope sender, PHP mail() uses the cPanel user@server address, which never aligns with ehmar.com.
add_action( 'phpmailer_init', function ( $phpmailer ) {
	$phpmailer->Sender = EHMAR_MAIL_FROM;
} );
