<?php
/**
 * One-time installer for shared hosting without WP-CLI.
 *
 * Upload next to wp-config.php, then open:
 *   /ehmar-install.php?key=KEY&step=1   install WordPress + activate plugins
 *   /ehmar-install.php?key=KEY&step=2   let WooCommerce finish its own install (fresh request)
 *   /ehmar-install.php?key=KEY&step=3   run provision/setup.php, then delete this file and the provision folder
 *
 * KEY, admin user and admin email are substituted when the deploy package is built.
 */

const EHMAR_KEY         = '__EHMAR_KEY__';
const EHMAR_ADMIN_USER  = '__EHMAR_ADMIN_USER__';
const EHMAR_ADMIN_EMAIL = '__EHMAR_ADMIN_EMAIL__';
const EHMAR_ADMIN_PASS  = '__EHMAR_ADMIN_PASS__';

header( 'Content-Type: text/plain; charset=utf-8' );
header( 'Cache-Control: no-store' );

// A placeholder that was never substituted is shorter than a real key, so this also refuses an unbuilt file.
if ( strlen( EHMAR_KEY ) < 24 || ! hash_equals( EHMAR_KEY, (string) ( $_GET['key'] ?? '' ) ) ) {
	http_response_code( 403 );
	exit( "Forbidden\n" );
}

$step = (int) ( $_GET['step'] ?? 0 );

if ( 1 === $step ) {
	define( 'WP_INSTALLING', true );
	require __DIR__ . '/wp-load.php';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	if ( ! is_blog_installed() ) {
		$result = wp_install( 'Ehmar', EHMAR_ADMIN_USER, EHMAR_ADMIN_EMAIL, true, '', EHMAR_ADMIN_PASS );
		echo "WordPress installed (user id {$result['user_id']}).\n";
	} else {
		echo "WordPress already installed.\n";
	}
	update_option( 'permalink_structure', '/%postname%/' );

	$activated = activate_plugin( 'woocommerce/woocommerce.php', '', false, true );
	echo is_wp_error( $activated ) ? 'WooCommerce activation failed: ' . $activated->get_error_message() . "\n" : "WooCommerce activated.\n";
	echo "STEP1 OK\n";
	exit;
}

if ( 2 === $step ) {
	require __DIR__ . '/wp-load.php';
	if ( ! class_exists( 'WooCommerce' ) ) {
		http_response_code( 500 );
		exit( "WooCommerce is not loaded.\n" );
	}
	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::install();
	}
	echo 'WooCommerce ' . WC()->version . " ready.\n";
	echo "STEP2 OK\n";
	exit;
}

if ( 3 === $step ) {
	require __DIR__ . '/wp-load.php';
	$provision = __DIR__ . '/wp-content/ehmar-provision';
	ob_start();
	require $provision . '/provision/setup.php';
	echo ob_get_clean();

	// Nothing of the installer may stay reachable on the live site.
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $provision, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $provision );
	unlink( __FILE__ );
	echo "STEP3 OK (installer removed)\n";
	exit;
}

http_response_code( 400 );
echo "Missing step.\n";
