<?php
/**
 * Plugin Name: Chirurgie Comment Discussions
 * Description: Adds configurable comment fields, a Hospital / University field, per-news comment statistics, and CSV exports.
 * Version: 1.0.0
 * Author: Ardijan Curi
 * Text Domain: chirurgie-comment-discussions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CCDS_Plugin {
	const OPTION_NAME        = 'ccds_settings';
	const META_INSTITUTION   = '_ccds_hospital_university';
	const PLACEHOLDER_PREFIX = '[ccds-no-comment-text]';

	/**
	 * Boot the plugin.
	 */
	public static function init() {
		$plugin = new self();
		$plugin->register_hooks();
	}

	/**
	 * Add default settings on activation.
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::default_settings() );
		}
	}

	/**
	 * Register WordPress hooks.
	 */
	private function register_hooks() {
		add_filter( 'comment_form_default_fields', array( $this, 'filter_default_comment_fields' ), 99 );
		add_filter( 'comment_form_fields', array( $this, 'filter_comment_form_fields' ), 99 );
		add_filter( 'pre_option_require_name_email', array( $this, 'filter_require_name_email' ) );
		add_filter( 'allow_empty_comment', array( $this, 'allow_empty_comment_when_text_disabled' ), 10, 2 );
		add_filter( 'preprocess_comment', array( $this, 'add_placeholder_comment_text' ) );
		add_action( 'pre_comment_on_post', array( $this, 'validate_custom_comment_submission' ) );
		add_action( 'comment_post', array( $this, 'save_comment_institution' ) );
		add_filter( 'comment_text', array( $this, 'filter_comment_text' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
			add_action( 'admin_post_ccds_save_settings', array( $this, 'handle_save_settings' ) );
			add_action( 'admin_post_ccds_update_discussion', array( $this, 'handle_update_discussion' ) );
			add_action( 'admin_post_ccds_bulk_update_discussions', array( $this, 'handle_bulk_update_discussions' ) );
			add_action( 'admin_post_ccds_export_news_comments_csv', array( $this, 'handle_csv_export' ) );
		}
	}

	/**
	 * Default plugin settings.
	 *
	 * @return array<string,int>
	 */
	private static function default_settings() {
		return array(
			'field_author'               => 1,
			'field_author_required'      => 1,
			'field_email'                => 1,
			'field_email_required'       => 1,
			'field_url'                  => 1,
			'field_url_required'         => 0,
			'field_cookies'              => 1,
			'field_cookies_required'     => 0,
			'field_comment'              => 1,
			'field_comment_required'     => 1,
			'field_institution'          => 1,
			'field_institution_required' => 0,
		);
	}

	/**
	 * Get normalized settings.
	 *
	 * @return array<string,int>
	 */
	private static function get_settings() {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, self::default_settings() );

		foreach ( self::default_settings() as $key => $default ) {
			$settings[ $key ] = empty( $settings[ $key ] ) ? 0 : 1;
		}

		foreach ( self::get_configurable_fields() as $field_key => $field ) {
			$required_key = $field['required_key'];

			if ( empty( $settings[ $field_key ] ) ) {
				$settings[ $required_key ] = 0;
			}
		}

		if ( empty( $settings['field_comment'] ) ) {
			$settings['field_institution']          = 1;
			$settings['field_institution_required'] = 1;
		}

		return $settings;
	}

	/**
	 * Field map for settings, rendering, and validation.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function get_configurable_fields() {
		return array(
			'field_author'      => array(
				'required_key' => 'field_author_required',
				'post_key'     => 'author',
				'label'        => __( 'Name', 'chirurgie-comment-discussions' ),
			),
			'field_email'       => array(
				'required_key' => 'field_email_required',
				'post_key'     => 'email',
				'label'        => __( 'Email', 'chirurgie-comment-discussions' ),
			),
			'field_url'         => array(
				'required_key' => 'field_url_required',
				'post_key'     => 'url',
				'label'        => __( 'Website', 'chirurgie-comment-discussions' ),
			),
			'field_cookies'     => array(
				'required_key' => 'field_cookies_required',
				'post_key'     => 'wp-comment-cookies-consent',
				'label'        => __( 'Cookies consent', 'chirurgie-comment-discussions' ),
			),
			'field_comment'     => array(
				'required_key' => 'field_comment_required',
				'post_key'     => 'comment',
				'label'        => __( 'Comment text', 'chirurgie-comment-discussions' ),
			),
			'field_institution' => array(
				'required_key' => 'field_institution_required',
				'post_key'     => 'ccds_hospital_university',
				'label'        => __( 'Hospital / University', 'chirurgie-comment-discussions' ),
			),
		);
	}

	/**
	 * Remove disabled standard comment fields.
	 *
	 * @param array<string,string> $fields Default fields.
	 * @return array<string,string>
	 */
	public function filter_default_comment_fields( $fields ) {
		$settings = self::get_settings();

		if ( empty( $settings['field_author'] ) ) {
			unset( $fields['author'] );
		}

		if ( empty( $settings['field_email'] ) ) {
			unset( $fields['email'] );
		}

		if ( empty( $settings['field_url'] ) ) {
			unset( $fields['url'] );
		}

		if ( empty( $settings['field_cookies'] ) ) {
			unset( $fields['cookies'] );
		}

		$fields = $this->balance_astra_default_field_wrappers( $fields );

		if ( isset( $fields['author'] ) ) {
			$fields['author'] = $this->set_text_input_required_state(
				$fields['author'],
				'author',
				! empty( $settings['field_author_required'] )
			);
		}

		if ( isset( $fields['email'] ) ) {
			$fields['email'] = $this->set_text_input_required_state(
				$fields['email'],
				'email',
				! empty( $settings['field_email_required'] )
			);
		}

		if ( isset( $fields['url'] ) ) {
			$fields['url'] = $this->set_text_input_required_state(
				$fields['url'],
				'url',
				! empty( $settings['field_url_required'] )
			);
		}

		if ( isset( $fields['cookies'] ) ) {
			$fields['cookies'] = $this->set_cookies_required_state(
				$fields['cookies'],
				! empty( $settings['field_cookies_required'] )
			);
		}

		return $fields;
	}

	/**
	 * Add/remove fields in the complete comment form, including the comment textarea.
	 *
	 * @param array<string,string> $fields Comment fields.
	 * @return array<string,string>
	 */
	public function filter_comment_form_fields( $fields ) {
		$settings = self::get_settings();

		if ( empty( $settings['field_comment'] ) ) {
			unset( $fields['comment'] );
		} elseif ( isset( $fields['comment'] ) ) {
			$fields['comment'] = $this->set_comment_field_required_state(
				$fields['comment'],
				! empty( $settings['field_comment_required'] )
			);
		}

		if ( ! empty( $settings['field_institution'] ) ) {
			$fields = $this->insert_field_after(
				$fields,
				$this->get_institution_insert_after_key( $fields ),
				'ccds_hospital_university',
				$this->get_institution_field_html(
					empty( $settings['field_comment'] ) || ! empty( $settings['field_institution_required'] )
				)
			);
		}

		return $this->move_comment_textarea_after_input_fields( $fields );
	}

	/**
	 * Disable core name/email validation when either field is hidden.
	 *
	 * @param mixed $pre_option Pre-option value.
	 * @return mixed
	 */
	public function filter_require_name_email( $pre_option ) {
		return false;
	}

	/**
	 * Allow submissions without comment text when that field is disabled.
	 *
	 * @param bool  $allow_empty_comment Whether empty comments are allowed.
	 * @param array $commentdata Comment data.
	 * @return bool
	 */
	public function allow_empty_comment_when_text_disabled( $allow_empty_comment, $commentdata ) {
		$settings = self::get_settings();

		if ( empty( $settings['field_comment'] ) || empty( $settings['field_comment_required'] ) ) {
			return true;
		}

		return $allow_empty_comment;
	}

	/**
	 * Add a unique internal placeholder so WordPress duplicate checks do not reject repeated empty-body submissions.
	 *
	 * @param array $commentdata Comment data.
	 * @return array
	 */
	public function add_placeholder_comment_text( $commentdata ) {
		$settings = self::get_settings();

		if (
			( empty( $settings['field_comment'] ) || empty( $settings['field_comment_required'] ) )
			&& empty( trim( (string) $commentdata['comment_content'] ) )
		) {
			$commentdata['comment_content'] = self::PLACEHOLDER_PREFIX . ' ' . wp_generate_uuid4();
		}

		return $commentdata;
	}

	/**
	 * Validate custom comment fields before WordPress saves the comment.
	 *
	 * @param int $post_id Post ID.
	 */
	public function validate_custom_comment_submission( $post_id ) {
		$settings = self::get_settings();

		if ( ! is_user_logged_in() && ! empty( $settings['field_author'] ) && ! empty( $settings['field_author_required'] ) ) {
			$this->require_posted_field( 'author', __( 'Please enter your name.', 'chirurgie-comment-discussions' ) );
		}

		if ( ! is_user_logged_in() && ! empty( $settings['field_email'] ) ) {
			$email = $this->get_posted_field_value( 'email' );

			if ( ! empty( $settings['field_email_required'] ) && '' === $email ) {
				$this->stop_comment_submission( __( 'Please enter your email address.', 'chirurgie-comment-discussions' ) );
			}

			if ( '' !== $email && ! is_email( $email ) ) {
				$this->stop_comment_submission( __( 'Please enter a valid email address.', 'chirurgie-comment-discussions' ) );
			}
		}

		if ( ! empty( $settings['field_url'] ) && ! empty( $settings['field_url_required'] ) ) {
			$this->require_posted_field( 'url', __( 'Please enter your website.', 'chirurgie-comment-discussions' ) );
		}

		if ( ! empty( $settings['field_cookies'] ) && ! empty( $settings['field_cookies_required'] ) && empty( $_POST['wp-comment-cookies-consent'] ) ) {
			$this->stop_comment_submission( __( 'Please accept the comment cookies consent.', 'chirurgie-comment-discussions' ) );
		}

		if ( ! empty( $settings['field_comment'] ) && ! empty( $settings['field_comment_required'] ) ) {
			$this->require_posted_field( 'comment', __( 'Please type your comment text.', 'chirurgie-comment-discussions' ) );
		}

		if (
			! empty( $settings['field_institution'] )
			&& ( ! empty( $settings['field_institution_required'] ) || empty( $settings['field_comment'] ) )
		) {
			$this->require_posted_field( 'ccds_hospital_university', __( 'Please enter your Hospital / University.', 'chirurgie-comment-discussions' ) );
		}
	}

	/**
	 * Require a posted text field.
	 *
	 * @param string $field_name POST field name.
	 * @param string $message Error message.
	 */
	private function require_posted_field( $field_name, $message ) {
		if ( '' === $this->get_posted_field_value( $field_name ) ) {
			$this->stop_comment_submission( $message );
		}
	}

	/**
	 * Get a sanitized POST field value.
	 *
	 * @param string $field_name POST field name.
	 * @return string
	 */
	private function get_posted_field_value( $field_name ) {
		if ( ! isset( $_POST[ $field_name ] ) || ! is_scalar( $_POST[ $field_name ] ) ) {
			return '';
		}

		return trim( sanitize_text_field( wp_unslash( $_POST[ $field_name ] ) ) );
	}

	/**
	 * Stop comment submission with a WordPress-style back link.
	 *
	 * @param string $message Error message.
	 */
	private function stop_comment_submission( $message ) {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Comment Submission Failure', 'chirurgie-comment-discussions' ),
			array(
				'response'  => 200,
				'back_link' => true,
			)
		);
	}

	/**
	 * Save Hospital / University comment meta.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function save_comment_institution( $comment_id ) {
		if ( ! isset( $_POST['ccds_hospital_university'] ) ) {
			return;
		}

		$institution = sanitize_text_field( wp_unslash( $_POST['ccds_hospital_university'] ) );

		if ( '' === trim( $institution ) ) {
			delete_comment_meta( $comment_id, self::META_INSTITUTION );
			return;
		}

		update_comment_meta( $comment_id, self::META_INSTITUTION, $institution );
	}

	/**
	 * Hide internal placeholders and append public Hospital / University display.
	 *
	 * @param string          $comment_text Rendered comment text.
	 * @param WP_Comment|int $comment Comment object or ID.
	 * @param array          $args Comment display args.
	 * @return string
	 */
	public function filter_comment_text( $comment_text, $comment = null, $args = array() ) {
		$comment_object = $comment instanceof WP_Comment ? $comment : get_comment( $comment );

		if ( ! $comment_object instanceof WP_Comment ) {
			return $comment_text;
		}

		if ( self::is_placeholder_comment_content( $comment_object->comment_content ) ) {
			$comment_text = '';
		}

		if ( is_admin() || is_feed() ) {
			return $comment_text;
		}

		$institution = self::get_comment_institution( $comment_object->comment_ID );

		if ( '' === $institution ) {
			return $comment_text;
		}

		$institution_html = sprintf(
			'<p class="ccds-comment-institution"><strong>%s</strong> %s</p>',
			esc_html__( 'Hospital / University:', 'chirurgie-comment-discussions' ),
			esc_html( $institution )
		);

		return $comment_text . $institution_html;
	}

	/**
	 * Add scoped frontend styles for the custom comment field.
	 */
	public function enqueue_frontend_styles() {
		if ( ! is_singular() ) {
			return;
		}

		wp_register_style( 'ccds-comment-discussions', false, array(), '1.0.0' );
		wp_enqueue_style( 'ccds-comment-discussions' );
		wp_add_inline_style(
			'ccds-comment-discussions',
			'
			.comments-area form.comment-form {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				column-gap: 20px;
				row-gap: 16px;
			}
			.comments-area form.comment-form .comment-notes,
			.comments-area form.comment-form .logged-in-as,
			.comments-area form.comment-form .must-log-in,
			.comments-area form.comment-form .ast-row.comment-textarea,
			.comments-area form.comment-form .comment-form-comment,
			.comments-area form.comment-form .comment-form-cookies-consent,
			.comments-area form.comment-form .form-submit,
			.comments-area form.comment-form .ast-comment-formwrap {
				grid-column: 1 / -1;
			}
			.comments-area form.comment-form .ast-row.comment-textarea,
			.comments-area form.comment-form .ast-comment-formwrap {
				margin-left: 0 !important;
				margin-right: 0 !important;
			}
			.comments-area form.comment-form .ast-comment-formwrap {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				column-gap: 20px;
				row-gap: 16px;
			}
			.comments-area form.comment-form .comment-form-author,
			.comments-area form.comment-form .comment-form-email,
			.comments-area form.comment-form .comment-form-url,
			.comments-area form.comment-form .comment-form-ccds-hospital-university {
				box-sizing: border-box;
				float: none !important;
				margin: 0 !important;
				max-width: none !important;
				padding-left: 0 !important;
				padding-right: 0 !important;
				width: auto !important;
			}
			.comments-area form.comment-form .comment-form-textarea {
				padding-left: 0 !important;
				padding-right: 0 !important;
			}
			.comments-area form.comment-form textarea#comment,
			.comments-area form.comment-form .comment-form-author input[type="text"],
			.comments-area form.comment-form .comment-form-email input[type="text"],
			.comments-area form.comment-form .comment-form-url input[type="text"],
			.comments-area form.comment-form .comment-form-ccds-hospital-university input[type="text"] {
				box-sizing: border-box;
				width: 100%;
			}
			.comments-area .comment-form-ccds-hospital-university {
				box-sizing: border-box;
			}
			.comments-area .comment-form-ccds-hospital-university input[type="text"] {
				box-sizing: border-box;
				min-height: 52px;
				width: 100%;
			}
			.comments-area .comment-form-ccds-hospital-university input[type="text"]::placeholder {
				opacity: 1;
			}
			.comments-area {
				--ccds-accent: var(--ast-global-color-0, #005a70);
				--ccds-border: var(--ast-single-post-border, var(--ast-border-color, #dce7ec));
				--ccds-muted: #607681;
				--ccds-soft: #f7fafb;
			}
			.comments-area .comments-title {
				border-bottom: 1px solid var(--ccds-border);
				font-size: clamp(1.2rem, 1.7vw, 1.45rem);
				line-height: 1.3;
				margin: 42px 0 18px;
				padding: 0 0 12px;
			}
			.comments-area .ast-comment-list,
			.comments-area .ast-comment-list .children {
				list-style: none;
				margin: 0;
				padding: 0;
			}
			.comments-area .ast-comment-list {
				display: grid;
				gap: 14px;
				padding-bottom: 0;
			}
			.comments-area .ast-comment-list li.comment {
				margin: 0 !important;
				padding: 0 !important;
			}
			.comments-area .ast-comment-list .children {
				border-left: 2px solid var(--ccds-border);
				display: grid;
				gap: 12px;
				margin: 12px 0 0 28px;
				padding-left: 14px;
			}
			.comments-area .ast-comment-list li.depth-1 .ast-comment,
			.comments-area .ast-comment-list li.depth-2 .ast-comment,
			.comments-area .ast-comment-list .ast-comment {
				background: #fff;
				border: 1px solid var(--ccds-border);
				border-radius: 8px;
				box-shadow: 0 1px 0 rgba(0, 0, 0, 0.02);
				font-size: 0.92rem;
				padding: 14px 16px !important;
			}
			.comments-area .ast-comment-list .children .ast-comment {
				background: var(--ccds-soft);
			}
			.comments-area .ast-comment-info {
				align-items: center;
				display: flex;
				gap: 10px;
				margin-bottom: 10px;
			}
			.comments-area .ast-comment-avatar-wrap {
				flex: 0 0 34px;
			}
			.comments-area .ast-comment-avatar-wrap img,
			.comments-area .ast-comment-info img {
				border: 1px solid var(--ccds-border);
				border-radius: 50%;
				box-shadow: none;
				display: block;
				height: 34px;
				width: 34px;
			}
			.comments-area header.ast-comment-meta,
			.comments-area .ast-comment-meta {
				align-items: flex-start;
				display: flex;
				flex-direction: column;
				gap: 2px;
				margin: 0;
				padding: 0 !important;
				text-transform: none;
				width: auto;
			}
			.comments-area .ast-comment-cite-wrap {
				margin: 0;
				text-align: left;
			}
			.comments-area .ast-comment-cite-wrap cite,
			.comments-area .ast-comment-cite-wrap .fn {
				color: var(--ccds-accent);
				font-size: 0.82rem;
				font-style: normal;
				font-weight: 700;
				line-height: 1.25;
			}
			.comments-area .ast-comment-time {
				color: var(--ccds-muted);
				display: block;
				font-size: 0.72rem;
				font-weight: 500;
				line-height: 1.35;
				margin: 0;
			}
			.comments-area .ast-comment-time a {
				color: inherit;
				text-decoration: none;
			}
			.comments-area section.ast-comment-content.comment,
			.comments-area .ast-comment-content.comment {
				clear: none;
				display: grid;
				font-size: 0.88rem;
				grid-template-columns: minmax(0, 1fr) auto;
				line-height: 1.55;
				padding-left: 44px !important;
				row-gap: 10px;
				column-gap: 12px;
			}
			.comments-area .ast-comment-content.comment > p:not(.ccds-comment-institution) {
				grid-column: 1 / -1;
				margin: 0;
			}
			.comments-area .ast-comment-content.comment > *:not(.ccds-comment-institution):not(.ast-comment-edit-reply-wrap) {
				grid-column: 1 / -1;
			}
			.comments-area .ccds-comment-institution {
				align-items: baseline;
				background: transparent;
				border: 0;
				color: var(--ccds-muted);
				display: inline-flex;
				flex-wrap: wrap;
				font-size: 0.78rem;
				gap: 4px;
				grid-column: 1;
				line-height: 1.45;
				margin: 0;
				min-width: 0;
				padding: 0;
				word-break: break-word;
			}
			.comments-area .ccds-comment-institution strong {
				color: var(--ccds-accent);
				font-weight: 700;
			}
			.comments-area .ast-comment-list .ast-comment-edit-reply-wrap {
				align-items: center;
				display: flex;
				grid-column: 2;
				justify-content: flex-end;
				justify-self: end;
				margin-top: 0;
			}
			.comments-area .ast-comment-list a.comment-reply-link,
			.comments-area .ast-comment-list a.comment-edit-link {
				align-items: center;
				border: 1px solid var(--ccds-accent);
				border-radius: 6px;
				display: inline-flex;
				font-size: 0.78rem;
				font-weight: 700;
				line-height: 1.2;
				padding: 6px 10px;
				text-decoration: none;
				transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
			}
			.comments-area .ast-comment-list a.comment-reply-link {
				background: var(--ccds-accent);
				color: #fff;
			}
			.comments-area .ast-comment-list a.comment-reply-link::before {
				content: "\21a9";
				display: inline-block;
				font-size: 0.9em;
				line-height: 1;
				margin-right: 6px;
			}
			.comments-area .ast-comment-list a.comment-edit-link {
				background: #fff;
				color: var(--ccds-accent);
			}
			.comments-area .ast-comment-list a.comment-reply-link:hover,
			.comments-area .ast-comment-list a.comment-reply-link:focus,
			.comments-area .ast-comment-list a.comment-edit-link:hover,
			.comments-area .ast-comment-list a.comment-edit-link:focus {
				background: #fff;
				border-color: var(--ccds-accent);
				color: var(--ccds-accent);
			}
			@media (max-width: 767px) {
				.comments-area form.comment-form,
				.comments-area form.comment-form .ast-comment-formwrap {
					grid-template-columns: 1fr;
					column-gap: 0;
					row-gap: 16px;
				}
				.comments-area form.comment-form .comment-form-author,
				.comments-area form.comment-form .comment-form-email,
				.comments-area form.comment-form .comment-form-url,
				.comments-area .comment-form-ccds-hospital-university {
					clear: both;
					grid-column: 1 / -1;
					width: 100%;
				}
				.comments-area .comments-title {
					margin-top: 36px;
				}
				.comments-area .ast-comment-list {
					gap: 12px;
				}
				.comments-area .ast-comment-list .children {
					margin-left: 0;
					padding-left: 12px;
				}
				.comments-area .ast-comment-list li.depth-1 .ast-comment,
				.comments-area .ast-comment-list li.depth-2 .ast-comment,
				.comments-area .ast-comment-list .ast-comment {
					padding: 14px !important;
				}
				.comments-area .ast-comment-info {
					align-items: flex-start;
				}
				.comments-area .ast-comment-avatar-wrap {
					flex-basis: 32px;
				}
				.comments-area .ast-comment-avatar-wrap img,
				.comments-area .ast-comment-info img {
					height: 32px;
					width: 32px;
				}
				.comments-area section.ast-comment-content.comment,
				.comments-area .ast-comment-content.comment {
					column-gap: 10px;
					padding-left: 0 !important;
				}
				.comments-area .ccds-comment-institution {
					display: flex;
				}
			}
			'
		);
	}

	/**
	 * Register admin submenu pages.
	 */
	public function register_admin_pages() {
		add_submenu_page(
			'edit-comments.php',
			esc_html__( 'Discussion Fields', 'chirurgie-comment-discussions' ),
			esc_html__( 'Discussion Fields', 'chirurgie-comment-discussions' ),
			'manage_options',
			'ccds-discussion-fields',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'edit-comments.php',
			esc_html__( 'News Statistics', 'chirurgie-comment-discussions' ),
			esc_html__( 'News Statistics', 'chirurgie-comment-discussions' ),
			'moderate_comments',
			'ccds-news-statistics',
			array( $this, 'render_statistics_page' )
		);
	}

	/**
	 * Save field toggle settings.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'chirurgie-comment-discussions' ) );
		}

		check_admin_referer( 'ccds_save_settings' );

		$settings = array();

		foreach ( self::get_configurable_fields() as $field_key => $field ) {
			$required_key = $field['required_key'];

			$settings[ $field_key ]    = isset( $_POST[ $field_key ] ) ? 1 : 0;
			$settings[ $required_key ] = isset( $_POST[ $required_key ] ) ? 1 : 0;

			if ( empty( $settings[ $field_key ] ) ) {
				$settings[ $required_key ] = 0;
			}
		}

		if ( empty( $settings['field_comment'] ) ) {
			$settings['field_institution']          = 1;
			$settings['field_institution_required'] = 1;
		}

		update_option( self::OPTION_NAME, $settings );

		$this->redirect_to_settings_page( 'settings_saved' );
	}

	/**
	 * Update one news post discussion status.
	 */
	public function handle_update_discussion() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to update discussions.', 'chirurgie-comment-discussions' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		check_admin_referer( 'ccds_update_discussion_' . $post_id );

		if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
			wp_die( esc_html__( 'Invalid news post.', 'chirurgie-comment-discussions' ) );
		}

		$comment_status = $this->sanitize_comment_status_from_request();

		wp_update_post(
			array(
				'ID'             => $post_id,
				'comment_status' => $comment_status,
			)
		);

		$this->redirect_to_settings_page( 'discussion_updated' );
	}

	/**
	 * Bulk update all published news post discussion statuses.
	 */
	public function handle_bulk_update_discussions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to update discussions.', 'chirurgie-comment-discussions' ) );
		}

		check_admin_referer( 'ccds_bulk_update_discussions' );

		$comment_status = $this->sanitize_comment_status_from_request();
		$posts          = self::get_published_news_posts();

		foreach ( $posts as $post ) {
			wp_update_post(
				array(
					'ID'             => $post->ID,
					'comment_status' => $comment_status,
				)
			);
		}

		$this->redirect_to_settings_page( 'discussions_bulk_updated' );
	}

	/**
	 * Render field settings and discussion controls.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'chirurgie-comment-discussions' ) );
		}

		$settings = self::get_settings();
		$posts    = self::get_published_news_posts();

		$this->render_admin_styles();
		?>
		<div class="wrap ccds-admin">
			<h1><?php esc_html_e( 'Discussion Fields', 'chirurgie-comment-discussions' ); ?></h1>
			<?php $this->render_admin_notice(); ?>

			<div class="ccds-panel">
				<h2><?php esc_html_e( 'Comment Form Fields', 'chirurgie-comment-discussions' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ccds_save_settings" />
					<?php wp_nonce_field( 'ccds_save_settings' ); ?>

					<table class="widefat striped ccds-table ccds-field-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Field', 'chirurgie-comment-discussions' ); ?></th>
								<th><?php esc_html_e( 'Enabled', 'chirurgie-comment-discussions' ); ?></th>
								<th><?php esc_html_e( 'Required', 'chirurgie-comment-discussions' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$this->render_field_toggle_row( 'field_author', __( 'Name', 'chirurgie-comment-discussions' ), $settings );
							$this->render_field_toggle_row( 'field_email', __( 'Email', 'chirurgie-comment-discussions' ), $settings );
							$this->render_field_toggle_row( 'field_url', __( 'Website', 'chirurgie-comment-discussions' ), $settings );
							$this->render_field_toggle_row( 'field_cookies', __( 'Cookies consent', 'chirurgie-comment-discussions' ), $settings );
							$this->render_field_toggle_row( 'field_comment', __( 'Comment text', 'chirurgie-comment-discussions' ), $settings );
							$this->render_field_toggle_row(
								'field_institution',
								__( 'Hospital / University', 'chirurgie-comment-discussions' ),
								$settings,
								empty( $settings['field_comment'] ),
								empty( $settings['field_comment'] )
							);
							?>
						</tbody>
					</table>

					<?php if ( empty( $settings['field_comment'] ) ) : ?>
						<p class="description">
							<?php esc_html_e( 'Hospital / University is required while the comment text field is disabled.', 'chirurgie-comment-discussions' ); ?>
						</p>
					<?php endif; ?>

					<?php submit_button( __( 'Save Field Settings', 'chirurgie-comment-discussions' ) ); ?>
				</form>
				<?php $this->render_settings_page_script(); ?>
			</div>

			<div class="ccds-panel">
				<h2><?php esc_html_e( 'News Discussion Controls', 'chirurgie-comment-discussions' ); ?></h2>
				<form class="ccds-inline-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ccds_bulk_update_discussions" />
					<?php wp_nonce_field( 'ccds_bulk_update_discussions' ); ?>
					<button type="submit" class="button button-secondary" name="comment_status" value="open">
						<?php esc_html_e( 'Open Comments for All Published News', 'chirurgie-comment-discussions' ); ?>
					</button>
					<button type="submit" class="button button-secondary" name="comment_status" value="closed">
						<?php esc_html_e( 'Close Comments for All Published News', 'chirurgie-comment-discussions' ); ?>
					</button>
				</form>

				<table class="widefat striped ccds-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'News', 'chirurgie-comment-discussions' ); ?></th>
							<th><?php esc_html_e( 'Comment Status', 'chirurgie-comment-discussions' ); ?></th>
							<th><?php esc_html_e( 'Comments', 'chirurgie-comment-discussions' ); ?></th>
							<th><?php esc_html_e( 'Action', 'chirurgie-comment-discussions' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $posts ) ) : ?>
							<tr>
								<td colspan="4"><?php esc_html_e( 'No published news posts were found.', 'chirurgie-comment-discussions' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $posts as $post ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
											<?php echo esc_html( get_the_title( $post ) ); ?>
										</a>
									</td>
									<td><?php echo esc_html( ucfirst( $post->comment_status ) ); ?></td>
									<td><?php echo esc_html( get_comments_number( $post->ID ) ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="ccds_update_discussion" />
											<input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>" />
											<input type="hidden" name="comment_status" value="<?php echo esc_attr( 'open' === $post->comment_status ? 'closed' : 'open' ); ?>" />
											<?php wp_nonce_field( 'ccds_update_discussion_' . $post->ID ); ?>
											<button type="submit" class="button">
												<?php
												echo 'open' === $post->comment_status
													? esc_html__( 'Close Comments', 'chirurgie-comment-discussions' )
													: esc_html__( 'Open Comments', 'chirurgie-comment-discussions' );
												?>
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
	 * Render the statistics page.
	 */
	public function render_statistics_page() {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			wp_die( esc_html__( 'You are not allowed to view comment statistics.', 'chirurgie-comment-discussions' ) );
		}

		$posts           = self::get_published_news_posts();
		$selected_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$selected_post   = $selected_post_id ? get_post( $selected_post_id ) : null;

		$this->render_admin_styles();
		?>
		<div class="wrap ccds-admin">
			<h1><?php esc_html_e( 'News Comment Statistics', 'chirurgie-comment-discussions' ); ?></h1>

			<div class="ccds-panel">
				<h2><?php esc_html_e( 'Published News Overview', 'chirurgie-comment-discussions' ); ?></h2>
				<table class="widefat striped ccds-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'News', 'chirurgie-comment-discussions' ); ?></th>
							<th><?php esc_html_e( 'Total', 'chirurgie-comment-discussions' ); ?></th>
							<th><?php esc_html_e( 'Approved (Not Spam)', 'chirurgie-comment-discussions' ); ?></th>
							<th><?php esc_html_e( 'Spam / Trash', 'chirurgie-comment-discussions' ); ?></th>
							<th><?php esc_html_e( 'Exports', 'chirurgie-comment-discussions' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $posts ) ) : ?>
							<tr>
								<td colspan="5"><?php esc_html_e( 'No published news posts were found.', 'chirurgie-comment-discussions' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $posts as $post ) : ?>
								<?php $stats = self::get_comment_statistics_for_post( $post->ID ); ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( self::get_statistics_page_url( $post->ID ) ); ?>">
											<?php echo esc_html( get_the_title( $post ) ); ?>
										</a>
									</td>
									<td><?php echo esc_html( $stats['summary']['total'] ); ?></td>
									<td><?php echo esc_html( $stats['summary']['approved'] ); ?></td>
									<td><?php echo esc_html( $stats['summary']['spam_trash'] ); ?></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( self::get_csv_export_url( $post->ID ) ); ?>">
											<?php esc_html_e( 'Download CSV', 'chirurgie-comment-discussions' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $selected_post instanceof WP_Post && 'post' === $selected_post->post_type ) : ?>
				<?php $selected_stats = self::get_comment_statistics_for_post( $selected_post->ID ); ?>
				<div class="ccds-panel">
					<div class="ccds-title-row">
						<h2><?php echo esc_html( get_the_title( $selected_post ) ); ?></h2>
						<a class="button button-primary" href="<?php echo esc_url( self::get_csv_export_url( $selected_post->ID ) ); ?>">
							<?php esc_html_e( 'Download CSV', 'chirurgie-comment-discussions' ); ?>
						</a>
					</div>

					<div class="ccds-stat-cards">
						<?php
						$this->render_stat_card( __( 'Total', 'chirurgie-comment-discussions' ), $selected_stats['summary']['total'] );
						$this->render_stat_card( __( 'Approved (Not Spam)', 'chirurgie-comment-discussions' ), $selected_stats['summary']['approved'] );
						$this->render_stat_card( __( 'Spam / Trash', 'chirurgie-comment-discussions' ), $selected_stats['summary']['spam_trash'] );
						?>
					</div>

					<h3><?php esc_html_e( 'Hospital / University Counts', 'chirurgie-comment-discussions' ); ?></h3>
					<table class="widefat striped ccds-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Hospital / University', 'chirurgie-comment-discussions' ); ?></th>
								<th><?php esc_html_e( 'Comments', 'chirurgie-comment-discussions' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $selected_stats['institutions'] ) ) : ?>
								<tr>
									<td colspan="2"><?php esc_html_e( 'No institution data yet.', 'chirurgie-comment-discussions' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $selected_stats['institutions'] as $institution => $count ) : ?>
									<tr>
										<td><?php echo esc_html( $institution ); ?></td>
										<td><?php echo esc_html( $count ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

					<h3><?php esc_html_e( 'Comment Details', 'chirurgie-comment-discussions' ); ?></h3>
					<table class="widefat striped ccds-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'ID', 'chirurgie-comment-discussions' ); ?></th>
								<th><?php esc_html_e( 'Date', 'chirurgie-comment-discussions' ); ?></th>
								<th><?php esc_html_e( 'Author', 'chirurgie-comment-discussions' ); ?></th>
								<th><?php esc_html_e( 'Email', 'chirurgie-comment-discussions' ); ?></th>
								<th><?php esc_html_e( 'Status', 'chirurgie-comment-discussions' ); ?></th>
								<th><?php esc_html_e( 'Hospital / University', 'chirurgie-comment-discussions' ); ?></th>
								<th><?php esc_html_e( 'Comment', 'chirurgie-comment-discussions' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $selected_stats['comments'] ) ) : ?>
								<tr>
									<td colspan="7"><?php esc_html_e( 'No comments yet.', 'chirurgie-comment-discussions' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $selected_stats['comments'] as $comment ) : ?>
									<tr>
										<td><?php echo esc_html( $comment->comment_ID ); ?></td>
										<td><?php echo esc_html( $comment->comment_date ); ?></td>
										<td><?php echo esc_html( $comment->comment_author ); ?></td>
										<td><?php echo esc_html( $comment->comment_author_email ); ?></td>
										<td><?php echo esc_html( self::get_comment_status_label( $comment->comment_approved ) ); ?></td>
										<td><?php echo esc_html( self::get_comment_institution( $comment->comment_ID ) ); ?></td>
										<td><?php echo esc_html( wp_trim_words( self::get_visible_comment_content( $comment ), 24 ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php elseif ( $selected_post_id ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'The selected news post could not be found.', 'chirurgie-comment-discussions' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Export a selected post's statistics as CSV.
	 */
	public function handle_csv_export() {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			wp_die( esc_html__( 'You are not allowed to export comment statistics.', 'chirurgie-comment-discussions' ) );
		}

		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'ccds_export_csv_' . $post_id );

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			wp_die( esc_html__( 'Invalid news post.', 'chirurgie-comment-discussions' ) );
		}

		$stats    = self::get_comment_statistics_for_post( $post_id );
		$filename = 'news-comments-' . $post_id . '-' . sanitize_title( get_the_title( $post ) ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, array( 'News ID', $post_id ) );
		fputcsv( $output, array( 'News title', get_the_title( $post ) ) );
		fputcsv( $output, array( 'Generated at', current_time( 'mysql' ) ) );
		fputcsv( $output, array() );

		fputcsv( $output, array( 'Summary' ) );
		fputcsv( $output, array( 'Status', 'Count' ) );
		fputcsv( $output, array( 'Total', $stats['summary']['total'] ) );
		fputcsv( $output, array( 'Approved (Not Spam)', $stats['summary']['approved'] ) );
		fputcsv( $output, array( 'Spam / Trash', $stats['summary']['spam_trash'] ) );
		fputcsv( $output, array() );

		fputcsv( $output, array( 'Hospital / University Counts' ) );
		fputcsv( $output, array( 'Hospital / University', 'Count' ) );
		foreach ( $stats['institutions'] as $institution => $count ) {
			fputcsv( $output, array( $institution, $count ) );
		}
		fputcsv( $output, array() );

		fputcsv( $output, array( 'Comment Details' ) );
		fputcsv( $output, array( 'Comment ID', 'Date', 'Author', 'Email', 'Status', 'Hospital / University', 'Comment' ) );

		foreach ( $stats['comments'] as $comment ) {
			fputcsv(
				$output,
				array(
					$comment->comment_ID,
					$comment->comment_date,
					$comment->comment_author,
					$comment->comment_author_email,
					self::get_comment_status_label( $comment->comment_approved ),
					self::get_comment_institution( $comment->comment_ID ),
					self::get_visible_comment_content( $comment ),
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Render one settings checkbox row.
	 *
	 * @param string              $key Setting key.
	 * @param string              $label Field label.
	 * @param array<string,int>   $settings Settings.
	 * @param bool                $enabled_disabled Whether the enabled checkbox should be disabled.
	 * @param bool                $required_disabled Whether the required checkbox should be disabled.
	 */
	private function render_field_toggle_row( $key, $label, $settings, $enabled_disabled = false, $required_disabled = false ) {
		$field_map    = self::get_configurable_fields();
		$required_key = isset( $field_map[ $key ] ) ? $field_map[ $key ]['required_key'] : $key . '_required';
		$is_enabled   = ! empty( $settings[ $key ] );
		$is_required  = ! empty( $settings[ $required_key ] );
		$is_forced    = $enabled_disabled || $required_disabled;
		?>
		<tr data-ccds-field-row>
			<th scope="row">
				<?php echo esc_html( $label ); ?>
				<?php if ( $is_forced ) : ?>
					<p class="description"><?php esc_html_e( 'Required while comment text is disabled.', 'chirurgie-comment-discussions' ); ?></p>
				<?php endif; ?>
			</th>
			<td>
				<label>
					<input
						type="checkbox"
						class="ccds-enabled-toggle"
						name="<?php echo esc_attr( $key ); ?>"
						value="1"
						<?php checked( $is_enabled ); ?>
						<?php disabled( $enabled_disabled ); ?>
					/>
					<span><?php esc_html_e( 'Show this field', 'chirurgie-comment-discussions' ); ?></span>
				</label>
				<?php if ( $enabled_disabled ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="1" />
				<?php endif; ?>
			</td>
			<td>
				<label>
					<input
						type="checkbox"
						class="ccds-required-toggle"
						name="<?php echo esc_attr( $required_key ); ?>"
						value="1"
						<?php checked( $is_required ); ?>
						<?php disabled( $required_disabled || ! $is_enabled ); ?>
						<?php echo $required_disabled ? 'data-ccds-forced="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					/>
					<span><?php esc_html_e( 'Make required', 'chirurgie-comment-discussions' ); ?></span>
				</label>
				<?php if ( $required_disabled && $is_required ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $required_key ); ?>" value="1" />
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Keep enabled/required checkboxes in sync while editing settings.
	 */
	private function render_settings_page_script() {
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				document.querySelectorAll('[data-ccds-field-row]').forEach(function (row) {
					var enabled = row.querySelector('.ccds-enabled-toggle');
					var required = row.querySelector('.ccds-required-toggle');

					if (!enabled || !required || enabled.disabled || required.dataset.ccdsForced === '1') {
						return;
					}

					var syncRequired = function () {
						if (!enabled.checked) {
							required.checked = false;
							required.disabled = true;
						} else {
							required.disabled = false;
						}
					};

					enabled.addEventListener('change', syncRequired);
					syncRequired();
				});
			});
		</script>
		<?php
	}

	/**
	 * Render a statistic card.
	 *
	 * @param string $label Label.
	 * @param int    $value Value.
	 */
	private function render_stat_card( $label, $value ) {
		?>
		<div class="ccds-stat-card">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( $value ); ?></strong>
		</div>
		<?php
	}

	/**
	 * Render admin notices from redirect flags.
	 */
	private function render_admin_notice() {
		$notice = isset( $_GET['ccds_notice'] ) ? sanitize_key( $_GET['ccds_notice'] ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'settings_saved'           => __( 'Field settings saved.', 'chirurgie-comment-discussions' ),
			'discussion_updated'       => __( 'Discussion status updated.', 'chirurgie-comment-discussions' ),
			'discussions_bulk_updated' => __( 'Discussion statuses updated.', 'chirurgie-comment-discussions' ),
		);

		if ( empty( $messages[ $notice ] ) ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $messages[ $notice ] ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render small admin-only layout styles.
	 */
	private function render_admin_styles() {
		?>
		<style>
			.ccds-admin .ccds-panel {
				background: #fff;
				border: 1px solid #dcdcde;
				margin: 18px 0;
				padding: 18px;
			}
			.ccds-admin .ccds-inline-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				margin: 8px 0 16px;
			}
			.ccds-admin .ccds-table {
				margin-top: 12px;
			}
			.ccds-admin .ccds-field-table th,
			.ccds-admin .ccds-field-table td {
				vertical-align: middle;
			}
			.ccds-admin .ccds-field-table th:first-child {
				width: 34%;
			}
			.ccds-admin .ccds-field-table label {
				display: inline-flex;
				gap: 8px;
				align-items: center;
			}
			.ccds-admin .ccds-title-row {
				align-items: center;
				display: flex;
				gap: 12px;
				justify-content: space-between;
			}
			.ccds-admin .ccds-stat-cards {
				display: grid;
				gap: 12px;
				grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
				margin: 16px 0;
			}
			.ccds-admin .ccds-stat-card {
				border: 1px solid #dcdcde;
				padding: 14px;
			}
			.ccds-admin .ccds-stat-card span {
				display: block;
				margin-bottom: 6px;
			}
			.ccds-admin .ccds-stat-card strong {
				font-size: 24px;
			}
		</style>
		<?php
	}

	/**
	 * Build the Hospital / University field HTML.
	 *
	 * @param bool $required Whether the field is required.
	 * @return string
	 */
	private function get_institution_field_html( $required ) {
		$required_attr = $required ? ' required aria-required="true"' : '';
		$label         = __( 'Hospital / University', 'chirurgie-comment-discussions' );
		$placeholder   = $required ? $label . '*' : $label;
		$field_class   = function_exists( 'astra_attr' )
			? astra_attr( 'comment-form-grid-class' )
			: 'ast-grid-common-col ast-width-lg-33 ast-width-md-4 ast-float';

		return sprintf(
			'<p class="comment-form-ccds-hospital-university %1$s"><label for="ccds_hospital_university" class="screen-reader-text">%2$s</label><input id="ccds_hospital_university" name="ccds_hospital_university" type="text" value="" placeholder="%3$s" size="30" maxlength="255" autocomplete="organization"%4$s /></p>',
			esc_attr( $field_class ),
			esc_html( $label ),
			esc_attr( $placeholder ),
			$required_attr
		);
	}

	/**
	 * Apply required/optional state to a standard text input field.
	 *
	 * @param string $html Field HTML.
	 * @param string $field_id Input ID.
	 * @param bool   $required Whether the field is required.
	 * @return string
	 */
	private function set_text_input_required_state( $html, $field_id, $required ) {
		$html = $this->set_required_attributes( $html, $field_id, $required );
		$html = $this->set_label_required_marker( $html, $field_id, $required );
		$html = $this->set_placeholder_required_marker( $html, $required );

		return $html;
	}

	/**
	 * Apply required/optional state to the comment textarea.
	 *
	 * @param string $html Field HTML.
	 * @param bool   $required Whether the field is required.
	 * @return string
	 */
	private function set_comment_field_required_state( $html, $required ) {
		$html = $this->set_required_attributes( $html, 'comment', $required );
		$html = $this->set_label_required_marker( $html, 'comment', $required );
		$html = $this->set_placeholder_required_marker( $html, $required );

		return $html;
	}

	/**
	 * Apply required/optional state to the cookie consent checkbox.
	 *
	 * @param string $html Field HTML.
	 * @param bool   $required Whether the field is required.
	 * @return string
	 */
	private function set_cookies_required_state( $html, $required ) {
		$html = $this->set_required_attributes( $html, 'wp-comment-cookies-consent', $required );

		if ( $required ) {
			if ( false === strpos( $html, 'ccds-required-marker' ) ) {
				$html = preg_replace(
					'#</label>#i',
					' <span class="required ccds-required-marker">*</span></label>',
					$html,
					1
				);
			}
		} else {
			$html = preg_replace( '#\s*<span class="required ccds-required-marker">\*</span>#i', '', $html );
		}

		return $html;
	}

	/**
	 * Add or remove HTML required attributes for one field.
	 *
	 * @param string $html Field HTML.
	 * @param string $field_id Element ID.
	 * @param bool   $required Whether the field is required.
	 * @return string
	 */
	private function set_required_attributes( $html, $field_id, $required ) {
		return preg_replace_callback(
			'#<(input|textarea)\b(?=[^>]*\bid=(["\'])' . preg_quote( $field_id, '#' ) . '\2)[^>]*>#i',
			function ( $matches ) use ( $required ) {
				$tag = preg_replace( '#\srequired(=(["\']).*?\2|=[^\s>]*)?#i', '', $matches[0] );
				$tag = preg_replace( '#\saria-required=(["\']).*?\1#i', '', $tag );

				if ( $required ) {
					$tag = preg_replace( '#\s*/?>$#', ' required aria-required="true"$0', $tag, 1 );
				}

				return $tag;
			},
			$html
		);
	}

	/**
	 * Add or remove required marker from a field label.
	 *
	 * @param string $html Field HTML.
	 * @param string $field_id Element ID.
	 * @param bool   $required Whether the field is required.
	 * @return string
	 */
	private function set_label_required_marker( $html, $field_id, $required ) {
		return preg_replace_callback(
			'#(<label\b(?=[^>]*\bfor=(["\'])' . preg_quote( $field_id, '#' ) . '\2)[^>]*>)(.*?)(</label>)#is',
			function ( $matches ) use ( $required ) {
				$label_text = preg_replace( '#\s*\*$#', '', $matches[3] );

				if ( $required ) {
					$label_text .= '*';
				}

				return $matches[1] . $label_text . $matches[4];
			},
			$html
		);
	}

	/**
	 * Add or remove a trailing required marker in placeholders.
	 *
	 * @param string $html Field HTML.
	 * @param bool   $required Whether the field is required.
	 * @return string
	 */
	private function set_placeholder_required_marker( $html, $required ) {
		return preg_replace_callback(
			'#placeholder=(["\'])(.*?)\1#i',
			function ( $matches ) use ( $required ) {
				$placeholder = preg_replace( '#\s*\*$#', '', $matches[2] );

				if ( $required ) {
					$placeholder .= '*';
				}

				return 'placeholder=' . $matches[1] . esc_attr( $placeholder ) . $matches[1];
			},
			$html
		);
	}

	/**
	 * Find a friendly insertion point for the institution field.
	 *
	 * @param array<string,string> $fields Fields.
	 * @return string
	 */
	private function get_institution_insert_after_key( $fields ) {
		foreach ( array( 'email', 'author', 'comment' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Insert a field after another field while preserving order.
	 *
	 * @param array<string,string> $fields Existing fields.
	 * @param string               $after_key Key to insert after.
	 * @param string               $new_key New key.
	 * @param string               $new_field New field HTML.
	 * @return array<string,string>
	 */
	private function insert_field_after( $fields, $after_key, $new_key, $new_field ) {
		$result = array();

		if ( '' === $after_key ) {
			return array( $new_key => $new_field ) + $fields;
		}

		foreach ( $fields as $key => $field ) {
			$result[ $key ] = $field;

			if ( $key === $after_key ) {
				$result[ $new_key ] = $new_field;
			}
		}

		if ( ! isset( $result[ $new_key ] ) ) {
			$result[ $new_key ] = $new_field;
		}

		return $result;
	}

	/**
	 * Put author/contact inputs before the message textarea.
	 *
	 * @param array<string,string> $fields Comment fields.
	 * @return array<string,string>
	 */
	private function move_comment_textarea_after_input_fields( $fields ) {
		if ( ! isset( $fields['comment'] ) ) {
			return $fields;
		}

		$comment_field = $fields['comment'];
		unset( $fields['comment'] );

		$ordered_fields = array();

		foreach ( array( 'author', 'email', 'url', 'ccds_hospital_university' ) as $field_key ) {
			if ( isset( $fields[ $field_key ] ) ) {
				$ordered_fields[ $field_key ] = $fields[ $field_key ];
				unset( $fields[ $field_key ] );
			}
		}

		$ordered_fields['comment'] = $comment_field;

		if ( isset( $fields['cookies'] ) ) {
			$ordered_fields['cookies'] = $fields['cookies'];
			unset( $fields['cookies'] );
		}

		return $ordered_fields + $fields;
	}

	/**
	 * Keep Astra's shared comment field wrapper valid when only some standard fields are shown.
	 *
	 * @param array<string,string> $fields Comment fields.
	 * @return array<string,string>
	 */
	private function balance_astra_default_field_wrappers( $fields ) {
		$has_author = isset( $fields['author'] );
		$has_email  = isset( $fields['email'] );
		$has_url    = isset( $fields['url'] );

		if ( $has_author && $has_email && $has_url ) {
			return $fields;
		}

		if ( $has_author ) {
			$fields['author'] = preg_replace(
				'#^\s*<div class="ast-comment-formwrap ast-row">\s*#',
				'',
				$fields['author']
			);
		}

		if ( $has_url ) {
			$fields['url'] = preg_replace(
				'#\s*</div>\s*$#',
				'',
				$fields['url']
			);
		}

		return $fields;
	}

	/**
	 * Get all published standard posts used as news.
	 *
	 * @return WP_Post[]
	 */
	private static function get_published_news_posts() {
		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Get comments and aggregate statistics for one post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	private static function get_comment_statistics_for_post( $post_id ) {
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'comment',
				'status'  => array( 'approve', 'hold', 'spam', 'trash' ),
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);

		$summary = array(
			'total'      => 0,
			'approved'   => 0,
			'spam_trash' => 0,
		);

		$institutions = array();

		foreach ( $comments as $comment ) {
			$summary['total']++;

			if ( '1' === (string) $comment->comment_approved ) {
				$summary['approved']++;
			} elseif ( in_array( (string) $comment->comment_approved, array( 'spam', 'trash' ), true ) ) {
				$summary['spam_trash']++;
			}

			$institution = self::get_comment_institution( $comment->comment_ID );

			if ( '' === $institution ) {
				$institution = __( '(Not provided)', 'chirurgie-comment-discussions' );
			}

			if ( ! isset( $institutions[ $institution ] ) ) {
				$institutions[ $institution ] = 0;
			}

			$institutions[ $institution ]++;
		}

		arsort( $institutions );

		return array(
			'summary'      => $summary,
			'institutions' => $institutions,
			'comments'     => $comments,
		);
	}

	/**
	 * Get a comment's Hospital / University value.
	 *
	 * @param int $comment_id Comment ID.
	 * @return string
	 */
	private static function get_comment_institution( $comment_id ) {
		return trim( (string) get_comment_meta( $comment_id, self::META_INSTITUTION, true ) );
	}

	/**
	 * Remove hidden placeholders from comment content for admin tables and CSV.
	 *
	 * @param WP_Comment $comment Comment object.
	 * @return string
	 */
	private static function get_visible_comment_content( $comment ) {
		if ( self::is_placeholder_comment_content( $comment->comment_content ) ) {
			return '';
		}

		return trim( wp_strip_all_tags( $comment->comment_content ) );
	}

	/**
	 * Check whether content is the internal no-comment-text placeholder.
	 *
	 * @param string $content Comment content.
	 * @return bool
	 */
	private static function is_placeholder_comment_content( $content ) {
		$content = trim( wp_strip_all_tags( (string) $content ) );

		return 0 === strpos( $content, self::PLACEHOLDER_PREFIX );
	}

	/**
	 * Turn raw comment status values into readable labels.
	 *
	 * @param string|int $status Raw status.
	 * @return string
	 */
	private static function get_comment_status_label( $status ) {
		$status = (string) $status;

		if ( '1' === $status ) {
			return __( 'Approved', 'chirurgie-comment-discussions' );
		}

		if ( '0' === $status ) {
			return __( 'Pending', 'chirurgie-comment-discussions' );
		}

		return ucfirst( $status );
	}

	/**
	 * Build a stats detail page URL.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_statistics_page_url( $post_id ) {
		return add_query_arg(
			array(
				'page'    => 'ccds-news-statistics',
				'post_id' => absint( $post_id ),
			),
			admin_url( 'edit-comments.php' )
		);
	}

	/**
	 * Build a nonce-protected CSV URL.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_csv_export_url( $post_id ) {
		$url = add_query_arg(
			array(
				'action'  => 'ccds_export_news_comments_csv',
				'post_id' => absint( $post_id ),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'ccds_export_csv_' . absint( $post_id ) );
	}

	/**
	 * Sanitize requested comment_status.
	 *
	 * @return string
	 */
	private function sanitize_comment_status_from_request() {
		$status = isset( $_POST['comment_status'] ) ? sanitize_key( wp_unslash( $_POST['comment_status'] ) ) : 'closed';

		return 'open' === $status ? 'open' : 'closed';
	}

	/**
	 * Redirect back to settings with an admin notice.
	 *
	 * @param string $notice Notice key.
	 */
	private function redirect_to_settings_page( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ccds-discussion-fields',
					'ccds_notice' => sanitize_key( $notice ),
				),
				admin_url( 'edit-comments.php' )
			)
		);
		exit;
	}
}

register_activation_hook( __FILE__, array( 'CCDS_Plugin', 'activate' ) );
CCDS_Plugin::init();
