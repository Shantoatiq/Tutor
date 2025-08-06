<?php
/**
 * Getting Started Admin
 *
 * @since 1.0.0
 * @package Getting Started Admin
 */

namespace GS\Classes;

/**
 * GS Admin
 */
class GS_Admin {

	/**
	 * Instance
	 *
	 * @access private
	 * @var object Class Instance.
	 * @since 1.0.0
	 */
	private static $instance = null;

	/**
	 * Initiator
	 *
	 * @since 1.0.0
	 * @return object initialized object of class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if ( ! $this->is_show_setup() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'setup_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts' ) );
		add_action( 'admin_head', array( $this, 'hide_notices_on_getting_started_page' ) );
	}

	/**
	 * Check if show setup wizard.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_show_setup() {
		$is_wizard_showing = apply_filters( 'getting_started_is_setup_wizard_showing', get_option( 'getting_started_is_setup_wizard_showing', false ) );

		if ( $is_wizard_showing ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the icon for the Getting Started page.
	 *
	 * @param string $fill_color The fill color for the icon.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_icon( $fill_color = 'currentColor' ) {
		$icon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M12.002 8.712L13.5087 7.20467C14.0659 6.64757 14.508 5.98615 14.8097 5.25819C15.1113 4.53023 15.2666 3.74998 15.2667 2.962V0.733334H13.038C12.2501 0.733327 11.4699 0.888516 10.7419 1.19004C10.0139 1.49156 9.3525 1.93352 8.79534 2.49067L7.28801 3.99733L3.51667 3.526L0.693341 6.34933L9.65001 15.3067L12.4733 12.4833L12.002 8.712ZM10.8073 9.906L11.0693 12.0013L9.65001 13.4207L8.47134 12.242L10.8073 9.906ZM6.09401 5.192L3.75734 7.528L2.58001 6.34933L3.99934 4.93L6.09401 5.192ZM3.75734 11.2987L1.40067 13.656L0.458008 12.7133L2.81467 10.3567L3.75734 11.2987ZM5.64334 13.1853L3.28601 15.542L2.34334 14.5993L4.70001 12.242L5.64334 13.1853Z" fill="' . $fill_color . '"/>
			</svg>';

		return apply_filters( 'getting_started_icon', $icon );
	}

	/**
	 * Add submenu to admin menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function setup_menu() {
		if ( current_user_can( 'manage_options' ) ) {
			$parent_slug   = 'getting-started';
			$capability    = 'manage_options';
			$menu_priority = apply_filters( 'getting_started_menu_priority', 1 );

			// Get the count of incomplete steps.
			$incomplete_steps = GS_Helper::get_incomplete_actions_count();

			$menu_text   = __( 'Finish Setup', 'astra-sites' );
			$bubble_text = $incomplete_steps
				? '<span class="awaiting-mod">' . $incomplete_steps . '</span>'
				: '<span class="awaiting-mod" style="background-color: #15803d;"><svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="scale: 1.5; translate: 0 1px;"><path d="M20 6 9 17l-5-5"/></svg></span>';

			add_menu_page(
				$menu_text,
				'<span>' . $menu_text . '</span> ' . $bubble_text,
				$capability,
				$parent_slug,
				array( $this, 'render_page' ),
				'data:image/svg+xml;base64,' . base64_encode( self::get_icon( 'white' ) ), //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$menu_priority
			);
		}
	}

	/**
	 * Renders the admin settings.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_page() {
		echo "<div id='getting-started-page' class='getting-started-style'></div>";
	}

	/**
	 * Load script for block editor and elementor editor.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function load_scripts() {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'getting-started' !== sanitize_text_field( $_GET['page'] ) ) {
			return;
		}

		$this->load_script();
	}

	/**
	 * Load all the required files in the importer.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function load_script() {

		$handle            = 'getting-started-script';
		$build_path        = GS_DIR . 'build/';
		$build_url         = GS_URL . 'build/';
		$script_asset_path = $build_path . 'main.asset.php';
		$script_info       = file_exists( $script_asset_path )
			? include $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => GS_VER,
			);

		$script_dep = array_merge( $script_info['dependencies'], array( 'jquery' ) );

		wp_enqueue_script(
			$handle,
			$build_url . 'main.js',
			$script_dep,
			$script_info['version'],
			true
		);

		$content = $this->getting_started_content();

		$data = apply_filters(
			'getting_started_vars',
			array(
				'ajaxurl'                => esc_url( admin_url( 'admin-ajax.php' ) ),
				'_ajax_nonce'            => wp_create_nonce( 'getting-started' ),
				'rest_api_nonce'         => ( current_user_can( 'manage_options' ) ) ? wp_create_nonce( 'wp_rest' ) : '',
				'icon'                   => self::get_icon(),
				'iconURL'                => esc_url( apply_filters( 'getting_started_logo_url', '' ) ),
				'title'                  => sanitize_text_field( $content['title'] ),
				'description'            => sanitize_text_field( $content['description'] ),
				'footerLogoURL'          => esc_url_raw( $content['footer_logo'] ),
				'footerPluginName'       => sanitize_text_field( $content['footer_plugin_name'] ),
				'footerPluginURL'        => esc_url_raw( $content['footer_plugin_url'] ),
				'congratulationsTitle'   => sanitize_text_field( $content['congratulations_title'] ),
				'congratulationsContent' => sanitize_text_field( $content['congratulations_content'] ),
				'adminDashboardURL'      => admin_url(),
			)
		);

		// Add localize JS.
		wp_localize_script(
			$handle,
			'gettingStartedVars',
			$data
		);

		// Enqueue CSS.
		wp_enqueue_style( 'getting-started-style', GS_URL . 'build/style-main.css', array(), GS_VER );
		wp_enqueue_style( 'getting-started-google-fonts', $this->google_fonts_url(), array(), 'all' );
	}

	/**
	 * Get the Getting Started content.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public function getting_started_content() {

		return apply_filters(
			'getting_started_content',
			array(
				'title'                   => $this->get_title(),
				'description'             => __( 'Complete these steps to take full control of your website.', 'astra-sites' ),
				'footer_logo'             => '',
				'footer_plugin_name'      => 'Starter Templates',
				'footer_plugin_url'       => 'https://startertemplates.com/',
				'congratulations_title'   => __( 'Awesome, You did it! 😍', 'astra-sites' ),
				'congratulations_content' => $this->get_congratulations_content(),
			)
		);
	}

	/**
	 * Generate and return the Google fonts url.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function google_fonts_url() {

		$fonts_url     = '';
		$font_families = array(
			'Figtree:400,500,600,700',
		);

		$query_args = array(
			'family' => rawurlencode( implode( '|', $font_families ) ),
			'subset' => rawurlencode( 'latin,latin-ext' ),
		);

		$fonts_url = add_query_arg( $query_args, '//fonts.googleapis.com/css' );

		return $fonts_url;
	}

	/**
	 * Get title for the page.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_title() {
		return __( 'Hi there 👋', 'astra-sites' );
	}

	/**
	 * Get the Congratulations section content.
	 *
	 * @since 1.0.0
	 * @return string|false
	 */
	public function get_congratulations_content() {
		return __(
			'🎉 Congratulations on completing the tasks and instructional videos to take full control of your website.\n 🚀 Now, you\'re well-equipped to make your website thrive. Best of luck with your website journey, and may it bring you great achievements and opportunities in the digital world!',
			'astra-sites'
		);
	}

	/**
	 * Hide all admin notices on the Getting Started page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function hide_notices_on_getting_started_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && 'getting-started' === $_GET['page'] ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );

			// Add custom CSS to hide any remaining notices.
			echo '<style>
				.notice, .updated, .update-nag, .error, .warning {
					display: none !important;
				}
			</style>';
		}
	}
}

/**
 * Kicking this off by calling 'get_instance()' method
 */
GS_Admin::get_instance();
