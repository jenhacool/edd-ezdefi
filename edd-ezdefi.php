<?php
/**
 * Plugin Name: ezDeFi - Bitcoin, Ethereum and Cryptocurrencies Payment Gateway for Easy Digital Downloads
 * Description: EAccept Bitcoin, Ethereum and Cryptocurrencies on your Easy Digital Downloads store with ezDeFi
 * Version: 1.0
 * Author: Nexty Platform
 * Author URI: https://nexty.io
 * License: GPL
 * Text Domain: edd-ezdefi
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) or exit;

if( ! EDD_Ezdefi_Loader::is_edd_active() ) {
	return;
}

class EDD_Ezdefi_Loader
{
	protected static $instance;

	protected $notices = array();

	/**
	 * Check if EDD is active
	 */
	public static function is_edd_active()
	{
		$active_plugins = (array) get_option( 'active_plugins', array() );

		if( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
		}

		return in_array( 'easy-digital-downloads/easy-digital-downloads.php', $active_plugins ) || array_key_exists( 'easy-digital-downloads/easy-digital-downloads.php', $active_plugins );
	}

	/**
	 * EDD_Ezdefi_Loader constructor.
	 */
	protected function __construct()
	{
		add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );

		register_activation_hook( __FILE__, array( $this, 'activate' ) );

		$this->define_constants();
	}

	/**
	 * Initializes the plugin.
	 */
	public function init_plugin()
	{
		require_once plugin_dir_path( __FILE__ ) . 'class-edd-ezdefi.php';

		require_once plugin_dir_path( __FILE__ ) . 'functions.php';

		load_plugin_textdomain( 'edd-ezdefi', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );

		edd_ezdefi();
	}

	/**
	 * Run when activate plugin
	 */
	public function activate()
	{
		global $wpdb;

		$sql = array();

		$table_name = $wpdb->prefix . 'edd_ezdefi_amount';

		$charset_collate = $wpdb->get_charset_collate();

		// Create new table
		$sql[] = "CREATE TABLE $table_name (
			id int(11) NOT NULL AUTO_INCREMENT,
			amount_key int(11) NOT NULL,
			price decimal(20,12) NOT NULL,
			amount_id decimal(20,12) NOT NULL,
			currency varchar(10) NOT NULL,
			expired_time timestamp default current_timestamp,
			PRIMARY KEY (id),
			UNIQUE (amount_id, currency)
		) $charset_collate;";

		$exception_table_name = $wpdb->prefix . 'edd_ezdefi_exception';

		$sql[] = "CREATE TABLE $exception_table_name (
			id int(11) NOT NULL AUTO_INCREMENT,
			amount_id decimal(20,12) NOT NULL,
			currency varchar(10) NOT NULL,
			order_id int(11),
			status varchar(20),
			payment_method varchar(100),
			explorer_url varchar(200),
			PRIMARY KEY (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		// Add procedure
		$wpdb->query("DROP PROCEDURE IF EXISTS `edd_ezdefi_generate_amount_id`" );
		$wpdb->query("
	        CREATE PROCEDURE `edd_ezdefi_generate_amount_id`(
	            IN value DECIMAl(20,12),
			    IN token VARCHAR(10),
			    IN decimal_number INT(2),
                IN life_time INT(11),
			    OUT amount_id DECIMAL(20,12)
			)
			BEGIN
			    DECLARE unique_id INT(11) DEFAULT 0;
			    IF EXISTS (SELECT 1 FROM $table_name WHERE `currency` = token AND `price` = value) THEN
                    IF EXISTS (SELECT 1 FROM $table_name WHERE `currency` = token AND `price` = value AND `amount_key` = 0 AND `expired_time` > NOW()) THEN
				        SELECT MIN(t1.amount_key+1) INTO unique_id FROM $table_name t1 LEFT JOIN $table_name t2 ON t1.amount_key + 1 = t2.amount_key AND t2.price = value AND t2.currency = token AND t2.expired_time > NOW() WHERE t2.amount_key IS NULL;
				        IF((unique_id % 2) = 0) THEN
				            SET amount_id = value + ((unique_id / 2) / POW(10, decimal_number));
				        ELSE
				            SET amount_id = value - ((unique_id - (unique_id DIV 2)) / POW(10, decimal_number));
				        END IF;
			        ELSE
			            SET amount_id = value;
			        END IF;
			    ELSE
			        SET amount_id = value;
			    END IF;
			    INSERT INTO $table_name (amount_key, price, amount_id, currency, expired_time)
                    VALUES (unique_id, value, amount_id, token, NOW() + INTERVAL life_time SECOND  + INTERVAL 10 SECOND)
                    ON DUPLICATE KEY UPDATE `expired_time` = NOW() + INTERVAL life_time SECOND  + INTERVAL 10 SECOND; 
			END
		" );

		// Add schedule event to clear amount table
		$wpdb->query( "
			CREATE EVENT IF NOT EXISTS `edd_ezdefi_clear_amount_table`
			ON SCHEDULE EVERY 3 DAY
			DO
				DELETE FROM $table_name;
		" );

		$wpdb->query( "
			CREATE EVENT IF NOT EXISTS `edd_ezdefi_clear_exception_table`
			ON SCHEDULE EVERY 7 DAY
			DO
				DELETE FROM $exception_table_name;
		" );
	}

	/**
	 * Define constants
	 */
	public function define_constants()
	{
		define( 'EDD_EZDEFI_VERSION', '1.0.0' );
		define( 'EDD_EZDEFI_MAIN_FILE', __FILE__ );
	}

	/**
	 * Get EDD_Ezdefi_Loader instance
	 */
	public static function instance()
	{
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

EDD_Ezdefi_Loader::instance();