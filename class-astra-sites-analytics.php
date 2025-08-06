<?php
/**
 * Astra Sites Analytics
 *
 * @since  4.4.27
 * @package Astra Sites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Astra_Sites_Analytics' ) ) {

	/**
	 * Astra_Sites_Analytics
	 */
	class Astra_Sites_Analytics {

		/**
		 * Instance of Astra_Sites_Analytics
		 *
		 * @since  4.4.27
		 * @var self Astra_Sites_Analytics
		 */
		private static $instance = null;

		/**
		 * Instance of Astra_Sites_Analytics.
		 *
		 * @since  4.4.27
		 *
		 * @return self Class object.
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor.
		 *
		 * @since  4.4.27
		 */
		private function __construct() {
			add_action( 'astra_sites_after_plugin_activation', array( $this, 'update_settings_after_plugin_activation' ), 10, 2 );
			add_action( 'wp_ajax_astra_sites_set_woopayments_analytics', array( $this, 'set_woopayments_analytics' ) );
			add_filter( 'bsf_core_stats', array( $this, 'add_astra_sites_analytics_data' ), 10, 1 );
		}

		/**
		 * Update settings after plugin activation.
		 *
		 * @param string $plugin_init The plugin initialization path.
		 * @param array  $data        Additional data (optional).
		 *
		 * @since 4.4.27
		 * @return void
		 */
		public function update_settings_after_plugin_activation( $plugin_init, $data = array() ) {
			// Bail if the plugin slug is not set or empty.
			if ( ! isset( $data['plugin_slug'] ) || '' === $data['plugin_slug'] ) {
				return;
			}

			// Set WooPayments related settings.
			$this->maybe_woopayments_included( $plugin_init, $data );

			$plugin_slug      = $data['plugin_slug'];
			$required_plugins = Astra_Sites_Page::get_instance()->get_setting( 'required_plugins', array() );

			// If the plugin is already activated by starter templates, return early.
			if ( ( isset( $required_plugins[ $plugin_slug ] ) && 'activated' === $required_plugins[ $plugin_slug ] ) ) {
				return;
			}

			// If required plugins is not an array, initialize it.
			if ( ! is_array( $required_plugins ) ) {
				$required_plugins = array();
			}

			// Set the plugin activation status and update in settings.
			$required_plugins[ $plugin_slug ] = isset( $data['was_plugin_active'] ) && $data['was_plugin_active'] ? 'was_active' : 'activated';
			Astra_Sites_Page::get_instance()->update_settings(
				array(
					'required_plugins' => $required_plugins,
				)
			);
		}

		/**
		 * Check if WooCommerce Payments plugin is included and update settings accordingly.
		 * 
		 * @param string $plugin_init The plugin initialization path.
		 * @param array  $data Additional data (optional).
		 *
		 * @since 4.4.23
		 * @return void
		 */
		public function maybe_woopayments_included( $plugin_init, $data = array() ) {
			if ( 'woocommerce-payments/woocommerce-payments.php' === $plugin_init ) {
				// Prevent showing the banner if plugin was already active.
				if ( ! isset( $data['was_plugin_active'] ) || ! $data['was_plugin_active'] ) {
					Astra_Sites_Page::get_instance()->update_settings(
						array(
							'woopayments_ref' => true,
						)
					);
				}

				Astra_Sites_Page::get_instance()->update_settings(
					array(
						'woopayments_included' => true,
					)
				);
			}
		}

		/**
		 * Set WooPayments analytics.
		 *
		 * @since 4.4.23
		 * @return void
		 */
		public function set_woopayments_analytics() {
			// Verify nonce.
			if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'woopayments_nonce' ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid nonce', 'astra-sites' ) ) );
				exit;
			}

			$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
			if ( ! in_array( $source, array( 'banner', 'onboarding' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid source', 'astra-sites' ) ) );
				exit;
			}

			$key = "woopayments_{$source}_clicked";
			Astra_Sites_Page::get_instance()->update_settings( array( $key => true ) );

			wp_send_json_success( array( 'message' => 'WooPayments analytics updated!' ) );
			exit;
		}

		/**
		 * Check if WooPayments is configured and connected to Stripe.
		 *
		 * @since 4.4.24
		 * @return bool True if WooPayments is active and connected to Stripe, false otherwise.
		 */
		public static function is_woo_payments_configured() {
			// Check if WCPay account is connected to Stripe.
			if ( class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_account_service' ) ) {
				$account_service = WC_Payments::get_account_service();
				if ( method_exists( $account_service, 'is_stripe_connected' ) ) {
					return $account_service->is_stripe_connected();
				}
			}

			return false;
		}

		/**
		 * Get all posts that have been imported and not modified since import.
		 *
		 * These are posts that were imported via Starter Templates and haven't been
		 * edited by the user since import (within a 3-minute buffer for background processing).
		 *
		 * @param string $post_type The post type to check. Default is 'any'.
		 * 
		 * @since 4.4.33
		 * @return array Post IDs that have not been modified since their import.
		 */
		private static function get_unmodified_imported_post_ids( $post_type = 'any' ) {
			// Get all posts that have been imported.
			$args  = array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for analytics and run in background.
					array(
						'key'     => '_astra_sites_imported_post',
						'value'   => '1',
						'compare' => '=',
					),
				),
			);
			$posts = get_posts( $args );

			// Get all post IDs that have not been modified since their import.
			return array_reduce(
				$posts,
				function ( $carry, $post ) {
					$post_date     = strtotime( $post->post_date );
					$modified_date = strtotime( $post->post_modified );

					// Check if modified time is within 3 minutes (180 seconds) of post date. 3 mins buffer for background post processing.
					if ( abs( $modified_date - $post_date ) <= 180 ) {
						$carry[] = $post->ID;
					}
					return $carry;
				},
				array()
			);
		}

		/**
		 * Check if any user-engaged posts exist for a given post type.
		 *
		 * "User-engaged" means either:
		 * - Posts created by the user (not imported).
		 * - Imported posts that have been modified by the user since import.
		 *
		 * @param string $post_type The post type to check.
		 * @param array  $args      Optional arguments for WP_Query.
		 *
		 * @since 4.4.33
		 * @return bool True if any qualifying posts exist, false otherwise.
		 */
		private static function has_user_engaged_posts_of_type( $post_type = 'any', $args = array() ) {
			// If post type is not 'any', ensure it exists.
			if ( 'any' !== $post_type && ! post_type_exists( $post_type ) ) {
				return false;
			}

			// Merge default arguments with provided ones.
			$args = array_merge(
				array(
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'fields'      => 'ids',
					'numberposts' => 1,
				),
				$args
			);

			// Exclude post__not_in if already set.
			if ( ! isset( $args['post__not_in'] ) ) {
				// Exclude posts that have been imported and not modified since import.
				$excluded_post_ids = self::get_unmodified_imported_post_ids( $post_type );
				if ( ! empty( $excluded_post_ids ) ) {
					$args['post__not_in'] = $excluded_post_ids;
				}
			}

			$query = new \WP_Query( $args );
			$found = $query->have_posts();
			wp_reset_postdata();

			return $found;
		}

		/**
		 * Checks if any Spectra block is used on the site.
		 *
		 * ACTIVE CONDITION:
		 * - Imported post/page is updated, published and has at least one Spectra block.
		 * - New post/page is published and has at least one Spectra block.
		 *
		 * @since 4.4.27
		 *
		 * @return bool
		 */
		public static function is_spectra_blocks_used() {
			if ( ! is_plugin_active( 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php' ) ) {
				return false;
			}

			// Check for Spectra v2 blocks (wp:uagb/).
			$has_spectra_v2_blocks = self::has_user_engaged_posts_of_type( 'any', array( 's' => '<!-- wp:uagb/' ) );
			if ( $has_spectra_v2_blocks ) {
				return true;
			}

			// Check for Spectra v3 blocks (wp:spectra/).
			return self::has_user_engaged_posts_of_type( 'any', array( 's' => '<!-- wp:spectra/' ) );
		}

		/**
		 * Checks if any UAE Header Footer Layout is published or any UAE widget is used on the site.
		 *
		 * ACTIVE CONDITION:
		 * - Imported header footer post is updated, published.
		 * - New header footer post is published.
		 *
		 * @since 4.4.27
		 *
		 * @return bool
		 */
		public static function is_uae_widgets_used() {
			if ( ! is_plugin_active( 'header-footer-elementor/header-footer-elementor.php' ) ) {
				return false;
			}

			// Check for user-engaged elementor-hf posts.
			if ( self::has_user_engaged_posts_of_type( 'elementor-hf' ) ) {
				return true;
			}

			// Check UAE widget usage data (fallback for older detection method).
			$uae_used_widgets = get_option( 'uae_widgets_usage_data_option', array() );
			if ( is_array( $uae_used_widgets ) && ! empty( $uae_used_widgets ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Checks if any SureForms form is published.
		 *
		 * ACTIVE CONDITION:
		 * - Imported form is updated and published.
		 * - New form is published.
		 *
		 * @since 4.4.27
		 *
		 * @return bool
		 */
		public static function is_sureforms_form_published() {
			return self::has_user_engaged_posts_of_type( 'sureforms_form' );
		}

		/**
		 * Checks if SureMail has at least one connection configured.
		 *
		 * ACTIVE CONDITION: SureMails is connected.
		 *
		 * @since 4.4.27
		 *
		 * @return bool
		 */
		public static function is_suremails_connected() {
			if ( ! is_plugin_active( 'suremails/suremails.php' ) ) {
				return false;
			}

			// Get SureMails connections from options.
			$suremails_connections_option = defined( 'SUREMAILS_CONNECTIONS' ) ? SUREMAILS_CONNECTIONS : 'suremails_connections';
			$suremails_connections        = get_option( $suremails_connections_option, array() );
			if ( is_array( $suremails_connections ) && isset( $suremails_connections['connections'] ) && ! empty( $suremails_connections['connections'] ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Checks if SureCart has any published product.
		 *
		 * ACTIVE CONDITION:
		 * - Imported SureCart product is updated and published.
		 * - New SureCart product is published.
		 *
		 * @since 4.4.27
		 *
		 * @return bool
		 */
		public static function is_surecart_product_published() {
			return self::has_user_engaged_posts_of_type( 'sc_product' );
		}

		/**
		 * Checks if CartFlows has any published funnel.
		 *
		 * ACTIVE CONDITION:
		 * - Imported funnel is updated and published.
		 * - New funnel is published.
		 *
		 * @since 4.4.27
		 *
		 * @return bool
		 */
		public static function is_cartflows_funnel_published() {
			return self::has_user_engaged_posts_of_type( 'cartflows_flow' );
		}

		/**
		 * Checks if LatePoint booking/appointment is created or managed by user.
		 *
		 * ACTIVE CONDITION: Booking form is managed or created in last 30 days.
		 *
		 * @since 4.4.27
		 *
		 * @return bool
		 */
		public static function is_latepoint_booking_managed() {
			if ( ! is_plugin_active( 'latepoint/latepoint.php' ) ) {
				return false;
			}

			global $wpdb;
			$latepoint_activities_table = defined( 'LATEPOINT_TABLE_ACTIVITIES' ) ? LATEPOINT_TABLE_ACTIVITIES : $wpdb->prefix . 'latepoint_activities';
			$last_30_days               = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

			// Table names can't be parameterized in wpdb::prepare(), safe to ignore PHPCS here.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$latepoint_activities_table} WHERE code = %s AND updated_at >= %s",
					'booking_created',
					$last_30_days
				)
			);
			// phpcs:enable

			return $count > 0;
		}

		/**
		 * Checks if a Presto Player video is embedded on the site.
		 * 
		 * ACTIVE CONDITION: Presto Player block/shortcode used on any post/page
		 *
		 * @since 4.4.27
		 *
		 * @return bool
		 */
		public static function is_presto_player_used() {
			if ( ! is_plugin_active( 'presto-player/presto-player.php' ) ) {
				return false;
			}

			// Exclude 'pp_video_block' post type to avoid false positives.
			$args = array(
				'post_type'     => array_diff(
					get_post_types(
						array( 'public' => true ),
						'names'
					),
					array( 'pp_video_block' )
				),
				'posts__not_in' => array(), // To search in all posts including imported ones.
				's'             => '<!-- /wp:presto-player',
			);

			// Check for Presto Player block in posts.
			$is_presto_player_block_used = self::has_user_engaged_posts_of_type( 'any', $args );
			if ( $is_presto_player_block_used ) {
				return true;
			}

			// Check for Presto Player shortcode in posts.
			$args['s'] = '[presto_player id=';

			return self::has_user_engaged_posts_of_type( 'any', $args );
		}

		/**
		 * Add required plugins analytics data.
		 *
		 * @param array $stats Stats array.
		 *
		 * @since 4.4.27
		 * @return void
		 */
		private static function add_required_plugins_analytics( &$stats ) {
			$required_plugins = Astra_Sites_Page::get_instance()->get_setting( 'required_plugins', array() );
			if ( ! is_array( $required_plugins ) ) {
				return;
			}

			$stats['plugins_data'] = ! empty( $required_plugins ) ? wp_json_encode( $required_plugins ) : '';
		}

		/**
		 * Add plugin active analytics data.
		 *
		 * @param array $stats Stats array.
		 *
		 * @since 4.4.27
		 * @return void
		 */
		private static function add_plugin_active_analytics( &$stats ) {
			$stats = array_merge(
				$stats,
				array(
					'spectra_blocks_used'         => self::is_spectra_blocks_used(),
					'uae_widgets_used'            => self::is_uae_widgets_used(),
					'sureforms_form_published'    => self::is_sureforms_form_published(),
					'suremails_connected'         => self::is_suremails_connected(),
					'surecart_product_published'  => self::is_surecart_product_published(),
					'cartflows_funnel_published'  => self::is_cartflows_funnel_published(),
					'latepoint_booking_managed'   => self::is_latepoint_booking_managed(),
					'presto_player_used'          => self::is_presto_player_used(),
				)
			);
		}

		/**
		 * Add finish setup analytics data.
		 *
		 * @param array $stats Stats array.
		 *
		 * @since 4.4.28
		 * @return void
		 */
		private static function add_finish_setup_analytics( &$stats ) {
			$is_setup_wizard_showing = get_option( 'getting_started_is_setup_wizard_showing', false );
			$action_items_status     = get_option( 'getting_started_action_items', array() );
			$courses_status          = array();
			$no_of_completed_courses = 0;

			// Get the courses status from action items.
			if ( is_array( $action_items_status ) ) {
				foreach ( $action_items_status as $key => $action_item ) {
					$status                 = isset( $action_item['status'] ) ? $action_item['status'] : false;
					$courses_status[ $key ] = $status ? 'done' : 'not_done';
					if ( $status ) {
						$no_of_completed_courses++;
					}
				}
			}

			// Total number of courses.
			$total_courses = count( $courses_status );

			// Boolean and numeric values.
			$stats['boolean_values']['is_finish_setup_showing'] = $is_setup_wizard_showing;
			$stats['numeric_values']['total_courses']           = $total_courses;
			$stats['numeric_values']['no_of_completed_courses'] = $no_of_completed_courses;
			$stats['boolean_values']['course_completed']        = 0 !== $total_courses && $no_of_completed_courses >= $total_courses;

			// Plain Json data.
			$stats['courses_status'] = ! empty( $courses_status ) ? wp_json_encode( $courses_status ) : '';
		}

		/**
		 * Add astra sites analytics data.
		 *
		 * @param array $stats stats array.
		 *
		 * @since 4.4.27
		 * @return array
		 */
		public function add_astra_sites_analytics_data( $stats ) {
			// Load the plugin.php file to use is_plugin_active function.
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$import_complete = get_option( 'astra_sites_import_complete', 'no' ) === 'yes';

			$stats['plugin_data']['astra_sites'] = array(
				'version'        => defined( 'ASTRA_PRO_SITES_NAME' ) ? 'premium' : 'free',
				'site_language'  => get_locale(),
				'plugin_version' => defined( 'ASTRA_SITES_VER' ) ? ASTRA_SITES_VER : 'unknown',
				'page_builder'   => Astra_Sites_Page::get_instance()->get_setting( 'page_builder' ),
				'boolean_values' => array(
					'import_complete'                => $import_complete,
					'woopayments_included'           => Astra_Sites_Page::get_instance()->get_setting( 'woopayments_included' ),
					'was_woopayments_referred'       => Astra_Sites_Page::get_instance()->get_setting( 'woopayments_ref' ),
					'woopayments_banner_clicked'     => Astra_Sites_Page::get_instance()->get_setting( 'woopayments_banner_clicked' ),
					'woopayments_onboarding_clicked' => Astra_Sites_Page::get_instance()->get_setting( 'woopayments_onboarding_clicked' ),
					'woopayments_configured'         => self::is_woo_payments_configured(),
				),
				'numeric_values' => array(
					'woopayments_banner_dismissed_count' => Astra_Sites_Page::get_instance()->get_setting( 'woopayments_banner_dismissed_count' ),
				),
			);

			if ( $import_complete ) {
				self::add_required_plugins_analytics( $stats['plugin_data']['astra_sites'] );
				self::add_plugin_active_analytics( $stats['plugin_data']['astra_sites']['boolean_values'] );
				self::add_finish_setup_analytics( $stats['plugin_data']['astra_sites'] );
			}

			return $stats;
		}
	}

	/**
	 * Kicking this off by calling 'get_instance()' method
	 */
	Astra_Sites_Analytics::get_instance();
}
