<?php
/**
 * Plugin Name: Confirm Participation Registration
 * Description: Adds an admin-enabled French participation registration form under selected posts, with admin-only registrations and CSV exports.
 * Version: 1.0.0
 * Author: Ardijan Curi
 * Text Domain: confirm-participation-registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CPR_Plugin {
	const DB_VERSION          = '1.0.0';
	const DB_VERSION_OPTION   = 'cpr_db_version';
	const TABLE_SUFFIX        = 'cpr_registrations';
	const META_ENABLED        = '_cpr_registration_enabled';
	const NONCE_ACTION        = 'cpr_submit_registration';
	const NONCE_FIELD         = 'cpr_registration_nonce';
	const FORMS_PAGE_SLUG     = 'cpr-participation-forms';
	const REGISTRATIONS_SLUG  = 'cpr-participation-registrations';
	const EMPTY_COMMENTS_FILE = 'empty-comments.php';

	/**
	 * Boot the plugin.
	 */
	public static function init() {
		$plugin = new self();
		$plugin->register_hooks();
	}

	/**
	 * Create or update the plugin database table on activation.
	 */
	public static function activate() {
		self::install_table();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function register_hooks() {
		add_action( 'init', array( $this, 'maybe_install_table' ) );
		add_filter( 'the_content', array( $this, 'append_registration_form' ), 20 );
		add_filter( 'comments_template', array( $this, 'hide_comments_template_for_enabled_posts' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );

		add_action( 'admin_post_cpr_submit_registration', array( $this, 'handle_registration_submission' ) );
		add_action( 'admin_post_nopriv_cpr_submit_registration', array( $this, 'handle_registration_submission' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
			add_action( 'admin_post_cpr_update_registration_status', array( $this, 'handle_update_registration_status' ) );
			add_action( 'admin_post_cpr_bulk_update_registration_status', array( $this, 'handle_bulk_update_registration_status' ) );
			add_action( 'admin_post_cpr_export_registrations_csv', array( $this, 'handle_csv_export' ) );
		}
	}

	/**
	 * Ensure the custom table exists after plugin rename/reactivation scenarios.
	 */
	public function maybe_install_table() {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install_table();
		}
	}

	/**
	 * Create or update the custom registration table.
	 */
	private static function install_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			first_name varchar(191) NOT NULL,
			last_name varchar(191) NOT NULL,
			hospital_institution varchar(255) NOT NULL,
			email_address varchar(191) NOT NULL,
			created_at datetime NOT NULL,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY email_address (email_address),
			UNIQUE KEY post_email (post_id,email_address)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Get the full custom table name.
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Register admin submenu pages under Posts.
	 */
	public function register_admin_pages() {
		add_submenu_page(
			'edit.php',
			esc_html__( 'Participation Forms', 'confirm-participation-registration' ),
			esc_html__( 'Participation Forms', 'confirm-participation-registration' ),
			'manage_options',
			self::FORMS_PAGE_SLUG,
			array( $this, 'render_forms_page' )
		);

		add_submenu_page(
			'edit.php',
			esc_html__( 'Participation Registrations', 'confirm-participation-registration' ),
			esc_html__( 'Participation Registrations', 'confirm-participation-registration' ),
			'manage_options',
			self::REGISTRATIONS_SLUG,
			array( $this, 'render_registrations_page' )
		);
	}

	/**
	 * Render the admin page for enabling/disabling forms.
	 */
	public function render_forms_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage participation forms.', 'confirm-participation-registration' ) );
		}

		$posts = self::get_published_posts();

		$this->render_admin_styles();
		?>
		<div class="wrap cpr-admin">
			<h1><?php esc_html_e( 'Participation Forms', 'confirm-participation-registration' ); ?></h1>
			<?php $this->render_admin_notice(); ?>

			<div class="cpr-panel">
				<h2><?php esc_html_e( 'Published Posts', 'confirm-participation-registration' ); ?></h2>
				<form class="cpr-inline-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cpr_bulk_update_registration_status" />
					<?php wp_nonce_field( 'cpr_bulk_update_registration_status' ); ?>
					<button type="submit" class="button button-secondary" name="registration_status" value="enabled">
						<?php esc_html_e( 'Enable All Published Posts', 'confirm-participation-registration' ); ?>
					</button>
					<button type="submit" class="button button-secondary" name="registration_status" value="disabled">
						<?php esc_html_e( 'Disable All Published Posts', 'confirm-participation-registration' ); ?>
					</button>
				</form>

				<table class="widefat striped cpr-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'confirm-participation-registration' ); ?></th>
							<th><?php esc_html_e( 'Form Status', 'confirm-participation-registration' ); ?></th>
							<th><?php esc_html_e( 'Registrations', 'confirm-participation-registration' ); ?></th>
							<th><?php esc_html_e( 'Action', 'confirm-participation-registration' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $posts ) ) : ?>
							<tr>
								<td colspan="4"><?php esc_html_e( 'No published posts were found.', 'confirm-participation-registration' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $posts as $post ) : ?>
								<?php $enabled = self::is_registration_enabled( $post->ID ); ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
											<?php echo esc_html( get_the_title( $post ) ); ?>
										</a>
									</td>
									<td>
										<span class="cpr-status <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>">
											<?php echo $enabled ? esc_html__( 'Enabled', 'confirm-participation-registration' ) : esc_html__( 'Disabled', 'confirm-participation-registration' ); ?>
										</span>
									</td>
									<td>
										<a href="<?php echo esc_url( self::get_registrations_page_url( $post->ID ) ); ?>">
											<?php echo esc_html( self::get_registration_count( $post->ID ) ); ?>
										</a>
									</td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="cpr_update_registration_status" />
											<input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>" />
											<input type="hidden" name="registration_status" value="<?php echo esc_attr( $enabled ? 'disabled' : 'enabled' ); ?>" />
											<?php wp_nonce_field( 'cpr_update_registration_status_' . $post->ID ); ?>
											<button type="submit" class="button">
												<?php echo $enabled ? esc_html__( 'Disable Form', 'confirm-participation-registration' ) : esc_html__( 'Enable Form', 'confirm-participation-registration' ); ?>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the admin registrations/statistics page.
	 */
	public function render_registrations_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view participation registrations.', 'confirm-participation-registration' ) );
		}

		$posts            = self::get_published_posts();
		$selected_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$selected_post    = $selected_post_id ? get_post( $selected_post_id ) : null;

		$this->render_admin_styles();
		?>
		<div class="wrap cpr-admin">
			<h1><?php esc_html_e( 'Participation Registrations', 'confirm-participation-registration' ); ?></h1>

			<div class="cpr-panel">
				<h2><?php esc_html_e( 'Published Posts Overview', 'confirm-participation-registration' ); ?></h2>
				<table class="widefat striped cpr-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'confirm-participation-registration' ); ?></th>
							<th><?php esc_html_e( 'Form Status', 'confirm-participation-registration' ); ?></th>
							<th><?php esc_html_e( 'Registrations', 'confirm-participation-registration' ); ?></th>
							<th><?php esc_html_e( 'Exports', 'confirm-participation-registration' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $posts ) ) : ?>
							<tr>
								<td colspan="4"><?php esc_html_e( 'No published posts were found.', 'confirm-participation-registration' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $posts as $post ) : ?>
								<?php $enabled = self::is_registration_enabled( $post->ID ); ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( self::get_registrations_page_url( $post->ID ) ); ?>">
											<?php echo esc_html( get_the_title( $post ) ); ?>
										</a>
									</td>
									<td><?php echo $enabled ? esc_html__( 'Enabled', 'confirm-participation-registration' ) : esc_html__( 'Disabled', 'confirm-participation-registration' ); ?></td>
									<td><?php echo esc_html( self::get_registration_count( $post->ID ) ); ?></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( self::get_csv_export_url( $post->ID ) ); ?>">
											<?php esc_html_e( 'Download CSV', 'confirm-participation-registration' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $selected_post instanceof WP_Post && 'post' === $selected_post->post_type ) : ?>
				<?php $registrations = self::get_registrations_for_post( $selected_post->ID ); ?>
				<div class="cpr-panel">
					<div class="cpr-title-row">
						<h2><?php echo esc_html( get_the_title( $selected_post ) ); ?></h2>
						<a class="button button-primary" href="<?php echo esc_url( self::get_csv_export_url( $selected_post->ID ) ); ?>">
							<?php esc_html_e( 'Download CSV', 'confirm-participation-registration' ); ?>
						</a>
					</div>

					<div class="cpr-stat-card">
						<span><?php esc_html_e( 'Total Registrations', 'confirm-participation-registration' ); ?></span>
						<strong><?php echo esc_html( count( $registrations ) ); ?></strong>
					</div>

					<table class="widefat striped cpr-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'ID', 'confirm-participation-registration' ); ?></th>
								<th><?php esc_html_e( 'Submitted At', 'confirm-participation-registration' ); ?></th>
								<th><?php esc_html_e( 'Nom', 'confirm-participation-registration' ); ?></th>
								<th><?php esc_html_e( 'Prénom', 'confirm-participation-registration' ); ?></th>
								<th><?php esc_html_e( 'Hôpital / Institution', 'confirm-participation-registration' ); ?></th>
								<th><?php esc_html_e( 'Adresse e-mail', 'confirm-participation-registration' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $registrations ) ) : ?>
								<tr>
									<td colspan="6"><?php esc_html_e( 'No registrations yet.', 'confirm-participation-registration' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $registrations as $registration ) : ?>
									<tr>
										<td><?php echo esc_html( $registration->id ); ?></td>
										<td><?php echo esc_html( $registration->created_at ); ?></td>
										<td><?php echo esc_html( $registration->last_name ); ?></td>
										<td><?php echo esc_html( $registration->first_name ); ?></td>
										<td><?php echo esc_html( $registration->hospital_institution ); ?></td>
										<td><?php echo esc_html( $registration->email_address ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php elseif ( $selected_post_id ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'The selected post could not be found.', 'confirm-participation-registration' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Enable or disable registration for one post.
	 */
	public function handle_update_registration_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to update participation forms.', 'confirm-participation-registration' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		check_admin_referer( 'cpr_update_registration_status_' . $post_id );

		if ( ! self::is_valid_published_post( $post_id ) ) {
			wp_die( esc_html__( 'Invalid post.', 'confirm-participation-registration' ) );
		}

		self::set_registration_enabled( $post_id, 'enabled' === self::get_registration_status_from_request() );

		$this->redirect_to_forms_page( 'updated' );
	}

	/**
	 * Bulk enable or disable registration for all published posts.
	 */
	public function handle_bulk_update_registration_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to update participation forms.', 'confirm-participation-registration' ) );
		}

		check_admin_referer( 'cpr_bulk_update_registration_status' );

		$enabled = 'enabled' === self::get_registration_status_from_request();

		foreach ( self::get_published_posts() as $post ) {
			self::set_registration_enabled( $post->ID, $enabled );
		}

		$this->redirect_to_forms_page( 'bulk_updated' );
	}

	/**
	 * Handle logged-in and logged-out public registration submissions.
	 */
	public function handle_registration_submission() {
		$post_id = isset( $_POST['cpr_post_id'] ) ? absint( $_POST['cpr_post_id'] ) : 0;

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION . '_' . $post_id ) ) {
			$this->redirect_to_post( $post_id, 'nonce' );
		}

		if ( ! self::is_valid_published_post( $post_id ) || ! self::is_registration_enabled( $post_id ) ) {
			$this->redirect_to_post( $post_id, 'closed' );
		}

		$email_raw    = self::sanitize_posted_text( 'cpr_email_address' );
		$registration = array(
			'first_name'           => self::sanitize_posted_text( 'cpr_first_name' ),
			'last_name'            => self::sanitize_posted_text( 'cpr_last_name' ),
			'hospital_institution' => self::sanitize_posted_text( 'cpr_hospital_institution' ),
			'email_address'        => sanitize_email( $email_raw ),
		);

		if (
			'' === $registration['first_name']
			|| '' === $registration['last_name']
			|| '' === $registration['hospital_institution']
			|| '' === $email_raw
		) {
			$this->redirect_to_post( $post_id, 'missing' );
		}

		if ( '' === $registration['email_address'] || ! is_email( $registration['email_address'] ) ) {
			$this->redirect_to_post( $post_id, 'email' );
		}

		if ( self::registration_exists( $post_id, $registration['email_address'] ) ) {
			$this->redirect_to_post( $post_id, 'duplicate' );
		}

		$inserted = self::insert_registration( $post_id, $registration );

		if ( ! $inserted ) {
			if ( self::registration_exists( $post_id, $registration['email_address'] ) ) {
				$this->redirect_to_post( $post_id, 'duplicate' );
			}

			$this->redirect_to_post( $post_id, 'error' );
		}

		$this->redirect_to_post( $post_id, 'success' );
	}

	/**
	 * Export registrations for one post as CSV.
	 */
	public function handle_csv_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export participation registrations.', 'confirm-participation-registration' ) );
		}

		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'cpr_export_csv_' . $post_id );

		if ( ! self::is_valid_published_post( $post_id ) ) {
			wp_die( esc_html__( 'Invalid post.', 'confirm-participation-registration' ) );
		}

		$post          = get_post( $post_id );
		$registrations = self::get_registrations_for_post( $post_id );
		$filename      = 'participation-registrations-' . $post_id . '-' . sanitize_title( get_the_title( $post ) ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, array( 'Registration ID', 'Submitted At', 'Post ID', 'Post Title', 'Nom', 'Prénom', 'Hôpital / Institution', 'Adresse e-mail' ) );

		foreach ( $registrations as $registration ) {
			fputcsv(
				$output,
				array(
					$registration->id,
					$registration->created_at,
					$post_id,
					get_the_title( $post ),
					$registration->last_name,
					$registration->first_name,
					$registration->hospital_institution,
					$registration->email_address,
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Append the public French registration form below enabled single posts.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_registration_form( $content ) {
		if ( ! self::should_render_frontend_form() ) {
			return $content;
		}

		return $content . $this->get_registration_form_html( get_the_ID() );
	}

	/**
	 * Hide the native comments template where the registration form is enabled.
	 *
	 * @param string $theme_template Original comments template path.
	 * @return string
	 */
	public function hide_comments_template_for_enabled_posts( $theme_template ) {
		if ( is_singular( 'post' ) && self::is_registration_enabled( get_queried_object_id() ) ) {
			return plugin_dir_path( __FILE__ ) . 'templates/' . self::EMPTY_COMMENTS_FILE;
		}

		return $theme_template;
	}

	/**
	 * Enqueue scoped frontend styles for the registration form.
	 */
	public function enqueue_frontend_styles() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		wp_register_style( 'confirm-participation-registration', false, array(), '1.0.0' );
		wp_enqueue_style( 'confirm-participation-registration' );
		wp_add_inline_style(
			'confirm-participation-registration',
			'
			.cpr-registration {
				--cpr-accent: var(--ast-global-color-0, #005a70);
				--cpr-border: var(--ast-single-post-border, var(--ast-border-color, #dce7ec));
				--cpr-muted: #607681;
				border-top: 1px solid var(--cpr-border);
				margin-top: 40px;
				padding-top: 30px;
			}
			.cpr-registration__title {
				color: var(--cpr-accent);
				font-size: clamp(1.25rem, 2vw, 1.65rem);
				line-height: 1.25;
				margin: 0 0 18px;
			}
			.cpr-registration__notice {
				border: 1px solid var(--cpr-border);
				border-radius: 8px;
				margin: 0 0 18px;
				padding: 12px 14px;
			}
			.cpr-registration__notice.is-success {
				background: #f3fbf6;
				color: #17643a;
			}
			.cpr-registration__notice.is-error {
				background: #fff7f7;
				color: #8a1f1f;
			}
			.cpr-registration__form {
				display: grid;
				gap: 14px;
				max-width: 680px;
			}
			.cpr-registration__field {
				display: grid;
				gap: 7px;
				margin: 0;
			}
			.cpr-registration__field label {
				color: var(--cpr-accent);
				font-size: 0.88rem;
				font-weight: 700;
				line-height: 1.25;
			}
			.cpr-registration__field input {
				box-sizing: border-box;
				min-height: 50px;
				width: 100%;
			}
			.cpr-registration__submit {
				justify-self: start;
				margin-top: 4px;
			}
			@media (max-width: 767px) {
				.cpr-registration {
					margin-top: 32px;
					padding-top: 24px;
				}
				.cpr-registration__form {
					max-width: none;
				}
				.cpr-registration__submit {
					width: 100%;
				}
			}
			'
		);
	}

	/**
	 * Build the public registration form HTML.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_registration_form_html( $post_id ) {
		$notice = $this->get_public_notice_html();

		ob_start();
		?>
		<section id="cpr-registration" class="cpr-registration" aria-labelledby="cpr-registration-title">
			<h2 id="cpr-registration-title" class="cpr-registration__title"><?php esc_html_e( 'Confirmez votre participation', 'confirm-participation-registration' ); ?></h2>
			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<form class="cpr-registration__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cpr_submit_registration" />
				<input type="hidden" name="cpr_post_id" value="<?php echo esc_attr( $post_id ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION . '_' . $post_id, self::NONCE_FIELD ); ?>

				<p class="cpr-registration__field">
					<label for="cpr_last_name"><?php esc_html_e( 'Nom', 'confirm-participation-registration' ); ?></label>
					<input id="cpr_last_name" name="cpr_last_name" type="text" autocomplete="family-name" required aria-required="true" />
				</p>

				<p class="cpr-registration__field">
					<label for="cpr_first_name"><?php esc_html_e( 'Prénom', 'confirm-participation-registration' ); ?></label>
					<input id="cpr_first_name" name="cpr_first_name" type="text" autocomplete="given-name" required aria-required="true" />
				</p>

				<p class="cpr-registration__field">
					<label for="cpr_hospital_institution"><?php esc_html_e( 'Hôpital / Institution', 'confirm-participation-registration' ); ?></label>
					<input id="cpr_hospital_institution" name="cpr_hospital_institution" type="text" autocomplete="organization" required aria-required="true" />
				</p>

				<p class="cpr-registration__field">
					<label for="cpr_email_address"><?php esc_html_e( 'Adresse e-mail', 'confirm-participation-registration' ); ?></label>
					<input id="cpr_email_address" name="cpr_email_address" type="email" autocomplete="email" required aria-required="true" />
				</p>

				<button class="button cpr-registration__submit" type="submit"><?php esc_html_e( 'S’inscrire', 'confirm-participation-registration' ); ?></button>
			</form>
		</section>
		<?php

		return ob_get_clean();
	}

	/**
	 * Get the current public notice from a redirect status.
	 *
	 * @return string
	 */
	private function get_public_notice_html() {
		$status = isset( $_GET['cpr_status'] ) ? sanitize_key( wp_unslash( $_GET['cpr_status'] ) ) : '';

		if ( '' === $status ) {
			return '';
		}

		$messages = array(
			'success'   => array( 'success', __( 'Merci, votre inscription a bien été enregistrée.', 'confirm-participation-registration' ) ),
			'duplicate' => array( 'error', __( 'Vous êtes déjà inscrit pour cet article.', 'confirm-participation-registration' ) ),
			'missing'   => array( 'error', __( 'Veuillez compléter tous les champs obligatoires.', 'confirm-participation-registration' ) ),
			'email'     => array( 'error', __( 'Veuillez saisir une adresse e-mail valide.', 'confirm-participation-registration' ) ),
			'closed'    => array( 'error', __( 'Les inscriptions ne sont pas ouvertes pour cet article.', 'confirm-participation-registration' ) ),
			'nonce'     => array( 'error', __( 'La session du formulaire a expiré. Veuillez réessayer.', 'confirm-participation-registration' ) ),
			'error'     => array( 'error', __( 'Une erreur est survenue. Veuillez réessayer.', 'confirm-participation-registration' ) ),
		);

		if ( ! isset( $messages[ $status ] ) ) {
			return '';
		}

		return sprintf(
			'<div class="cpr-registration__notice is-%1$s" role="status">%2$s</div>',
			esc_attr( $messages[ $status ][0] ),
			esc_html( $messages[ $status ][1] )
		);
	}

	/**
	 * Render admin scoped styles.
	 */
	private function render_admin_styles() {
		?>
		<style>
			.cpr-admin .cpr-panel {
				background: #fff;
				border: 1px solid #dcdcde;
				margin-top: 20px;
				padding: 18px;
			}
			.cpr-admin .cpr-inline-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin: 12px 0 16px;
			}
			.cpr-admin .cpr-title-row {
				align-items: center;
				display: flex;
				gap: 12px;
				justify-content: space-between;
				margin-bottom: 16px;
			}
			.cpr-admin .cpr-title-row h2 {
				margin: 0;
			}
			.cpr-admin .cpr-status {
				border-radius: 999px;
				display: inline-block;
				font-weight: 600;
				padding: 3px 9px;
			}
			.cpr-admin .cpr-status.is-enabled {
				background: #edfaef;
				color: #17643a;
			}
			.cpr-admin .cpr-status.is-disabled {
				background: #f6f7f7;
				color: #50575e;
			}
			.cpr-admin .cpr-stat-card {
				border: 1px solid #dcdcde;
				display: inline-block;
				margin: 0 0 16px;
				min-width: 160px;
				padding: 14px;
			}
			.cpr-admin .cpr-stat-card span {
				display: block;
				margin-bottom: 6px;
			}
			.cpr-admin .cpr-stat-card strong {
				font-size: 24px;
			}
			.cpr-admin .cpr-table td,
			.cpr-admin .cpr-table th {
				vertical-align: middle;
			}
		</style>
		<?php
	}

	/**
	 * Render admin redirect notices.
	 */
	private function render_admin_notice() {
		$notice = isset( $_GET['cpr_notice'] ) ? sanitize_key( wp_unslash( $_GET['cpr_notice'] ) ) : '';

		$messages = array(
			'updated'      => __( 'Participation form status updated.', 'confirm-participation-registration' ),
			'bulk_updated' => __( 'Participation form statuses updated.', 'confirm-participation-registration' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $messages[ $notice ] )
		);
	}

	/**
	 * Insert one registration.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string,string> $registration Registration data.
	 * @return bool
	 */
	private static function insert_registration( $post_id, $registration ) {
		global $wpdb;

		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			self::get_table_name(),
			array(
				'post_id'              => $post_id,
				'first_name'           => $registration['first_name'],
				'last_name'            => $registration['last_name'],
				'hospital_institution' => $registration['hospital_institution'],
				'email_address'        => strtolower( $registration['email_address'] ),
				'created_at'           => $now,
				'created_at_gmt'       => $now_gmt,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Check whether an email has already registered for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $email Email address.
	 * @return bool
	 */
	private static function registration_exists( $post_id, $email ) {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE post_id = %d AND email_address = %s',
				$post_id,
				strtolower( $email )
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Get registrations for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,object>
	 */
	private static function get_registrations_for_post( $post_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE post_id = %d ORDER BY created_at_gmt DESC, id DESC',
				$post_id
			)
		);
	}

	/**
	 * Get the number of registrations for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private static function get_registration_count( $post_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::get_table_name() . ' WHERE post_id = %d',
				$post_id
			)
		);
	}

	/**
	 * Get published standard posts.
	 *
	 * @return WP_Post[]
	 */
	private static function get_published_posts() {
		return get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Check if a post is a valid published standard post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_valid_published_post( $post_id ) {
		$post = get_post( $post_id );

		return $post instanceof WP_Post && 'post' === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * Check whether registration is enabled for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_registration_enabled( $post_id ) {
		return '1' === (string) get_post_meta( $post_id, self::META_ENABLED, true );
	}

	/**
	 * Set registration status for a post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $enabled Whether registration is enabled.
	 */
	private static function set_registration_enabled( $post_id, $enabled ) {
		if ( $enabled ) {
			update_post_meta( $post_id, self::META_ENABLED, '1' );
		} else {
			delete_post_meta( $post_id, self::META_ENABLED );
		}
	}

	/**
	 * Determine whether the public form should render in the content loop.
	 *
	 * @return bool
	 */
	private static function should_render_frontend_form() {
		return is_singular( 'post' )
			&& in_the_loop()
			&& is_main_query()
			&& self::is_registration_enabled( get_the_ID() );
	}

	/**
	 * Sanitize a posted text value.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private static function sanitize_posted_text( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Sanitize a posted email value.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private static function sanitize_posted_email( $key ) {
		return isset( $_POST[ $key ] ) ? strtolower( sanitize_email( wp_unslash( $_POST[ $key ] ) ) ) : '';
	}

	/**
	 * Get sanitized registration status from admin requests.
	 *
	 * @return string
	 */
	private static function get_registration_status_from_request() {
		$status = isset( $_POST['registration_status'] ) ? sanitize_key( wp_unslash( $_POST['registration_status'] ) ) : '';

		return in_array( $status, array( 'enabled', 'disabled' ), true ) ? $status : 'disabled';
	}

	/**
	 * Redirect to the forms admin page.
	 *
	 * @param string $notice Notice key.
	 */
	private function redirect_to_forms_page( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'  => 'post',
					'page'       => self::FORMS_PAGE_SLUG,
					'cpr_notice' => $notice,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect to a post after public submission.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status Status key.
	 */
	private function redirect_to_post( $post_id, $status ) {
		$url = $post_id ? get_permalink( $post_id ) : home_url( '/' );

		wp_safe_redirect( add_query_arg( 'cpr_status', $status, $url ) . '#cpr-registration' );
		exit;
	}

	/**
	 * Build the registrations admin page URL.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_registrations_page_url( $post_id ) {
		return add_query_arg(
			array(
				'post_type' => 'post',
				'page'      => self::REGISTRATIONS_SLUG,
				'post_id'   => $post_id,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Build the CSV export URL.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_csv_export_url( $post_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'cpr_export_registrations_csv',
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'cpr_export_csv_' . $post_id
		);
	}
}

register_activation_hook( __FILE__, array( 'CPR_Plugin', 'activate' ) );
CPR_Plugin::init();
