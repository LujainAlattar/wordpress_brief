<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Account automatically approved.
 *
 * @param int $user_id
 */
function um_post_registration_approved_hook( $user_id ) {
	um_fetch_user( $user_id );

	UM()->user()->approve();
}
add_action( 'um_post_registration_approved_hook', 'um_post_registration_approved_hook' );

/**
 * Account needs email validation.
 *
 * @param int $user_id
 */
function um_post_registration_checkmail_hook( $user_id ) {
	um_fetch_user( $user_id );

	UM()->user()->email_pending();
}
add_action( 'um_post_registration_checkmail_hook', 'um_post_registration_checkmail_hook' );

/**
 * Account needs admin review.
 *
 * @param int $user_id
 */
function um_post_registration_pending_hook( $user_id ) {
	um_fetch_user( $user_id );

	UM()->user()->pending();
}
add_action( 'um_post_registration_pending_hook', 'um_post_registration_pending_hook' );

/**
 * After insert a new user run at frontend and backend.
 *
 * @param int|WP_Error $user_id
 * @param array        $args
 * @param null|array   $form_data It's null in case when posted from wp-admin > Add user
 */
function um_after_insert_user( $user_id, $args, $form_data = null ) {
	if ( empty( $user_id ) || is_wp_error( $user_id ) ) {
		return;
	}

	// Set usermeta from submission.
	um_fetch_user( $user_id );
	if ( ! empty( $args['submitted'] ) ) {
		// It's only frontend case.
		UM()->user()->set_registration_details( $args['submitted'], $args, $form_data );
	}

	// Set user status.
	$status = um_user( 'status' );
	if ( empty( $status ) ) {
		um_fetch_user( $user_id );
		$status = um_user( 'status' );
	}
	UM()->user()->set_status( $status );

	// Create user uploads directory.
	UM()->uploader()->get_upload_user_base_dir( $user_id, true );

	/**
	 * Fires after insert user to DB and there you can set any extra details.
	 *
	 * Internal Ultimate Member callbacks (Priority -> Callback name -> Excerpt):
	 * 10  - `um_registration_save_files()`            Save registration files.
	 * 100 - `um_registration_set_profile_full_name()` Set user's full name.
	 *
	 * @since 2.0
	 * @hook um_registration_set_extra_data
	 *
	 * @param {int}   $user_id        User ID.
	 * @param {array} $submitted_data $_POST Submission array.
	 * @param {array} $form_data      UM form data. Since 2.6.7
	 *
	 * @example <caption>Make any custom action after insert user to DB.</caption>
	 * function my_registration_set_extra_data( $user_id, $submitted_data, $form_data ) {
	 *     // your code here
	 * }
	 * add_action( 'um_registration_set_extra_data', 'my_registration_set_extra_data', 10, 3 );
	 */
	do_action( 'um_registration_set_extra_data', $user_id, $args, $form_data );
	/**
	 * Fires after complete UM user registration.
	 * Note: Native redirects handlers at 100 priority, you can add some info before redirects.
	 *
	 * Internal Ultimate Member callbacks (Priority -> Callback name -> Excerpt):
	 * 10  - `um_send_registration_notification()` Send notifications.
	 * 100 - `um_check_user_status()`              Redirect after registration based on user status.
	 *
	 * @since 2.0
	 * @hook um_registration_complete
	 *
	 * @param {int}   $user_id        User ID.
	 * @param {array} $submitted_data $_POST Submission array.
	 * @param {array} $form_data      UM form data. Since 2.6.7
	 *
	 * @example <caption>Make any common action after complete UM user registration.</caption>
	 * function my_registration_complete( $user_id, $submitted_data, $form_data ) {
	 *     // your code here
	 * }
	 * add_action( 'um_registration_complete', 'my_registration_complete', 10, 3 );
	 */
	do_action( 'um_registration_complete', $user_id, $args, $form_data );
}
add_action( 'um_user_register', 'um_after_insert_user', 1, 3 );

/**
 * Send notification about registration
 *
 * @param $user_id
 */
function um_send_registration_notification( $user_id ) {
	um_fetch_user( $user_id );

	$emails = um_multi_admin_email();
	if ( ! empty( $emails ) ) {
		foreach ( $emails as $email ) {
			if ( 'pending' !== um_user( 'account_status' ) ) {
				UM()->mail()->send( $email, 'notification_new_user', array( 'admin' => true ) );
			} else {
				UM()->mail()->send( $email, 'notification_review', array( 'admin' => true ) );
			}
		}
	}
}
add_action( 'um_registration_complete', 'um_send_registration_notification' );

/**
 * Check user status and redirect it after registration
 *
 * @param int        $user_id
 * @param array      $args
 * @param null|array $form_data
 */
function um_check_user_status( $user_id, $args, $form_data = null ) {
	$status = um_user( 'account_status' );
	/**
	 * UM hook
	 *
	 * @type action
	 * @title um_post_registration_{$status}_hook
	 * @description After complete UM user registration.
	 * @input_vars
	 * [{"var":"$user_id","type":"int","desc":"User ID"},
	 * {"var":"$args","type":"array","desc":"Form data"}]
	 * @change_log
	 * ["Since: 2.0"]
	 * @usage add_action( 'um_post_registration_{$status}_hook', 'function_name', 10, 2 );
	 * @example
	 * <?php
	 * add_action( 'um_post_registration_{$status}_hook', 'my_post_registration', 10, 2 );
	 * function my_post_registration( $user_id, $args ) {
	 *     // your code here
	 * }
	 * ?>
	 */
	do_action( "um_post_registration_{$status}_hook", $user_id, $args );

	if ( is_null( $form_data ) || is_admin() ) {
		return;
	}

	do_action( "track_{$status}_user_registration" );

	if ( 'approved' === $status ) {
		// Check if user is logged in because there can be the customized way when through 'um_registration_for_loggedin_users' hook the registration is enabled for the logged-in users (e.g. Administrator).
		if ( ! is_user_logged_in() ) {
			// Custom way if 'um_registration_for_loggedin_users' hook after custom callbacks returns true. Then don't make auto-login because user is already logged-in.
			UM()->user()->auto_login( $user_id );
		}
		UM()->user()->generate_profile_slug( $user_id );

		/**
		 * UM hook
		 *
		 * @type action
		 * @title um_registration_after_auto_login
		 * @description After complete UM user registration and autologin.
		 * @input_vars
		 * [{"var":"$user_id","type":"int","desc":"User ID"}]
		 * @change_log
		 * ["Since: 2.0"]
		 * @usage add_action( 'um_registration_after_auto_login', 'function_name', 10, 1 );
		 * @example
		 * <?php
		 * add_action( 'um_registration_after_auto_login', 'my_registration_after_auto_login', 10, 1 );
		 * function my_registration_after_auto_login( $user_id ) {
		 *     // your code here
		 * }
		 * ?>
		 */
		do_action( 'um_registration_after_auto_login', $user_id );

		// Priority redirect
		if ( isset( $args['redirect_to'] ) ) {
			wp_safe_redirect( urldecode( $args['redirect_to'] ) );
			exit;
		}

		um_fetch_user( $user_id );

		if ( 'redirect_url' === um_user( 'auto_approve_act' ) && '' !== um_user( 'auto_approve_url' ) ) {
			wp_safe_redirect( um_user( 'auto_approve_url' ) );
			exit;
		}

		if ( 'redirect_profile' === um_user( 'auto_approve_act' ) ) {
			wp_safe_redirect( um_user_profile_url() );
			exit;
		}
	} else {
		if ( 'redirect_url' === um_user( $status . '_action' ) && '' !== um_user( $status . '_url' ) ) {
			/**
			 * UM hook
			 *
			 * @type filter
			 * @title um_registration_pending_user_redirect
			 * @description Change redirect URL for pending user after registration
			 * @input_vars
			 * [{"var":"$url","type":"string","desc":"Redirect URL"},
			 * {"var":"$status","type":"string","desc":"User status"},
			 * {"var":"$user_id","type":"int","desc":"User ID"}]
			 * @change_log
			 * ["Since: 2.0"]
			 * @usage
			 * <?php add_filter( 'um_registration_pending_user_redirect', 'function_name', 10, 3 ); ?>
			 * @example
			 * <?php
			 * add_filter( 'um_registration_pending_user_redirect', 'my_registration_pending_user_redirect', 10, 3 );
			 * function my_registration_pending_user_redirect( $url, $status, $user_id ) {
			 *     // your code here
			 *     return $url;
			 * }
			 * ?>
			 */
			$redirect_url = apply_filters( 'um_registration_pending_user_redirect', um_user( $status . '_url' ), $status, um_user( 'ID' ) );

			wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( 'show_message' === um_user( $status . '_action' ) && '' !== um_user( $status . '_message' ) ) {
			$url = UM()->permalinks()->get_current_url();
			$url = add_query_arg( 'message', esc_attr( $status ), $url );
			// Add only priority role to URL.
			$url = add_query_arg( 'um_role', esc_attr( um_user( 'role' ) ), $url );
			$url = add_query_arg( 'um_form_id', esc_attr( $form_data['form_id'] ), $url );

			wp_safe_redirect( $url );
			exit;
		}
	}
}
add_action( 'um_registration_complete', 'um_check_user_status', 100, 3 );

/**
 * Validate user password field on registration.
 *
 * @param array $submitted_data
 */
function um_submit_form_errors_hook__registration( $submitted_data ) {
	// Check for "\" in password.
	if ( array_key_exists( 'user_password', $submitted_data ) && false !== strpos( wp_unslash( trim( $submitted_data['user_password'] ) ), '\\' ) ) {
		UM()->form()->add_error( 'user_password', __( 'Passwords may not contain the character "\\".', 'ultimate-member' ) );
	}
}
add_action( 'um_submit_form_errors_hook__registration', 'um_submit_form_errors_hook__registration' );

/**
 * Registration form submit handler.
 *
 * @param array $args
 * @param array $form_data
 */
function um_submit_form_register( $args, $form_data ) {
	if ( isset( UM()->form()->errors ) ) {
		return;
	}

	/**
	 * Filters user data submitted by a registration form.
	 *
	 * Note: Data is already sanitized here.
	 *
	 * @since 1.3.x
	 * @hook  um_add_user_frontend_submitted
	 *
	 * @param {array} $submitted Submitted registration data.
	 * @param {array} $form_data UM form data. Since 2.6.7
	 *
	 * @return {array} Extended registration data.
	 *
	 * @example <caption>Extends registration data.</caption>
	 * function my_add_user_frontend_submitted( $submitted, $form_data ) {
	 *     // your code here
	 *     return $submitted;
	 * }
	 * add_filter( 'um_add_user_frontend_submitted', 'my_add_user_frontend_submitted', 10, 2 );
	 */
	$args = apply_filters( 'um_add_user_frontend_submitted', $args, $form_data );

	if ( ! empty( $args['user_login'] ) ) {
		$user_login = $args['user_login'];
	}
	if ( ! empty( $args['username'] ) && empty( $args['user_login'] ) ) {
		$user_login = $args['username'];
	}

	if ( ! empty( $args['first_name'] ) && ! empty( $args['last_name'] ) && empty( $user_login ) ) {

		switch ( UM()->options()->get( 'permalink_base' ) ) {
			case 'name':
				$user_login = str_replace( ' ', '.', $args['first_name'] . ' ' . $args['last_name'] );
				break;

			case 'name_dash':
				$user_login = str_replace( ' ', '-', $args['first_name'] . ' ' . $args['last_name'] );
				break;

			case 'name_plus':
				$user_login = str_replace( ' ', '+', $args['first_name'] . ' ' . $args['last_name'] );
				break;

			default:
				$user_login = str_replace( ' ', '', $args['first_name'] . ' ' . $args['last_name'] );
				break;
		}
		$user_login = sanitize_user( strtolower( remove_accents( $user_login ) ), true );

		if ( ! empty( $user_login ) ) {
			$count           = 1;
			$temp_user_login = $user_login;
			while ( username_exists( $temp_user_login ) ) {
				$temp_user_login = $user_login . $count;
				$count++;
			}
			$user_login = $temp_user_login;
		}
	}

	if ( empty( $user_login ) && ! empty( $args['user_email'] ) ) {
		$user_login = $args['user_email'];
	}

	$unique_user_id = uniqid();

	// see dbDelta and WP native DB structure user_login varchar(60)
	if ( empty( $user_login ) || ( mb_strlen( $user_login ) > 60 && ! is_email( $user_login ) ) ) {
		$user_login = 'user' . $unique_user_id;
		while ( username_exists( $user_login ) ) {
			$unique_user_id = uniqid();
			$user_login     = 'user' . $unique_user_id;
		}
	}

	if ( isset( $args['username'] ) && is_email( $args['username'] ) ) {
		$user_email = $args['username'];
	} elseif ( ! empty( $args['user_email'] ) ) {
		$user_email = $args['user_email'];
	}

	if ( ! isset( $args['user_password'] ) ) {
		$user_password = UM()->validation()->generate( 8 );
	} else {
		$user_password = $args['user_password'];
	}

	if ( empty( $user_email ) ) {
		$site_url   = wp_parse_url( get_site_url(), PHP_URL_HOST );
		$user_email = 'nobody' . $unique_user_id . '@' . $site_url;
		while ( email_exists( $user_email ) ) {
			$unique_user_id = uniqid();
			$user_email     = 'nobody' . $unique_user_id . '@' . $site_url;
		}

		/**
		 * Filters change user default email if it's empty on registration.
		 *
		 * @since 1.3.x
		 * @hook  um_user_register_submitted__email
		 *
		 * @param {string} $user_email Default email.
		 *
		 * @return {string} Default customized email.
		 *
		 * @example <caption>Change user default email if it's empty on registration.</caption>
		 * function my_user_register_submitted__email( $user_email ) {
		 *     // your code here
		 *     return $user_email;
		 * }
		 * add_filter( 'um_user_register_submitted__email', 'my_user_register_submitted__email' );
		 */
		$user_email = apply_filters( 'um_user_register_submitted__email', $user_email );
	}

	$credentials = array(
		'user_login'    => $user_login,
		'user_password' => $user_password,
		'user_email'    => trim( $user_email ),
	);

	// @todo test when ready maybe remove
	if ( ! empty( $args['submitted'] ) ) {
		$args['submitted'] = UM()->form()->clean_submitted_data( $args['submitted'] );
	}

	$args['submitted'] = array_merge( $args['submitted'], $credentials );

	// Set registration timestamp.
	$timestamp                      = current_time( 'timestamp' ); // @todo Working on timestamps.
	$args['submitted']['timestamp'] = $timestamp;
	$args['timestamp']              = $timestamp;

	$args = array_merge( $args, $credentials );

	//get user role from global or form's settings
	$user_role = UM()->form()->assigned_role( UM()->form()->form_id );

	//get user role from field Role dropdown or radio
	if ( isset( $args['role'] ) ) {
		global $wp_roles;
		$exclude_roles = array_diff( array_keys( $wp_roles->roles ), UM()->roles()->get_editable_user_roles() );

		//if role is properly set it
		if ( ! in_array( $args['role'], $exclude_roles, true ) ) {
			$user_role = $args['role'];
		}
	}

	/**
	 * Filters change user role on registration process
	 *
	 * @since 2.0
	 * @hook  um_registration_user_role
	 *
	 * @param {string} $user_role User role.
	 * @param {array}  $args      Registration data.
	 * @param {array}  $form_data UM form data. Since 2.6.7
	 *
	 * @return {string} User role.
	 *
	 * @example <caption>Change user role on registration process.</caption>
	 * function my_registration_user_role( $user_role, $args, $form_data ) {
	 *     // your code here
	 *     return $user_role;
	 * }
	 * add_filter( 'um_registration_user_role', 'my_registration_user_role', 10, 3 );
	 */
	$user_role = apply_filters( 'um_registration_user_role', $user_role, $args, $form_data );

	$userdata = array(
		'user_login' => $user_login,
		'user_pass'  => $user_password,
		'user_email' => $user_email,
		'role'       => $user_role,
	);

	$user_id = wp_insert_user( $userdata );
	if ( is_wp_error( $user_id ) ) {
		return;
	}

	/**
	 * Fires after complete UM user registration.
	 *
	 * Internal Ultimate Member callbacks (Priority -> Callback name -> Excerpt):
	 * 1 - `um_after_insert_user()` Make all Ultimate Member data set and actions after user registration|added via wp-admin.
	 *
	 * @since 2.0
	 * @hook  um_user_register
	 *
	 * @param {int}   $user_id   User ID.
	 * @param {array} $args      Form data.
	 * @param {array} $form_data UM form data. Since 2.6.7
	 *
	 * @example <caption>Make any custom action after complete UM user registration.</caption>
	 * function my_um_user_register( $user_id, $args, $form_data ) {
	 *     // your code here
	 * }
	 * add_action( 'um_user_register', 'my_um_user_register', 10, 3 );
	 */
	do_action( 'um_user_register', $user_id, $args, $form_data );
}
add_action( 'um_submit_form_register', 'um_submit_form_register', 10, 2 );

/**
 * Show the submit button
 *
 * @param $args
 */
function um_add_submit_button_to_register( $args ) {
	$primary_btn_word = $args['primary_btn_word'];
	/**
	 * UM hook
	 *
	 * @type filter
	 * @title um_register_form_button_one
	 * @description Change Register Form Primary button
	 * @input_vars
	 * [{"var":"$primary_btn_word","type":"string","desc":"Button text"},
	 * {"var":"$args","type":"array","desc":"Registration Form arguments"}]
	 * @change_log
	 * ["Since: 2.0"]
	 * @usage
	 * <?php add_filter( 'um_register_form_button_one', 'function_name', 10, 2 ); ?>
	 * @example
	 * <?php
	 * add_filter( 'um_register_form_button_one', 'my_register_form_button_one', 10, 2 );
	 * function my_register_form_button_one( $primary_btn_word, $args ) {
	 *     // your code here
	 *     return $primary_btn_word;
	 * }
	 * ?>
	 */
	$primary_btn_word = apply_filters('um_register_form_button_one', $primary_btn_word, $args );

	if ( ! isset( $primary_btn_word ) || $primary_btn_word == '' ){
		$primary_btn_word = UM()->options()->get( 'register_primary_btn_word' );
	}

	$secondary_btn_word = $args['secondary_btn_word'];
	/**
	 * UM hook
	 *
	 * @type filter
	 * @title um_register_form_button_two
	 * @description Change Registration Form Secondary button
	 * @input_vars
	 * [{"var":"$secondary_btn_word","type":"string","desc":"Button text"},
	 * {"var":"$args","type":"array","desc":"Registration Form arguments"}]
	 * @change_log
	 * ["Since: 2.0"]
	 * @usage
	 * <?php add_filter( 'um_register_form_button_two', 'function_name', 10, 2 ); ?>
	 * @example
	 * <?php
	 * add_filter( 'um_register_form_button_two', 'my_register_form_button_two', 10, 2 );
	 * function my_register_form_button_two( $secondary_btn_word, $args ) {
	 *     // your code here
	 *     return $secondary_btn_word;
	 * }
	 * ?>
	 */
	$secondary_btn_word = apply_filters( 'um_register_form_button_two', $secondary_btn_word, $args );

	if ( ! isset( $secondary_btn_word ) || $secondary_btn_word == '' ){
		$secondary_btn_word = UM()->options()->get( 'register_secondary_btn_word' );
	}

	$secondary_btn_url = ( isset( $args['secondary_btn_url'] ) && $args['secondary_btn_url'] ) ? $args['secondary_btn_url'] : um_get_core_page('login');
	/**
	 * UM hook
	 *
	 * @type filter
	 * @title um_register_form_button_two_url
	 * @description Change Registration Form Secondary button URL
	 * @input_vars
	 * [{"var":"$secondary_btn_url","type":"string","desc":"Button URL"},
	 * {"var":"$args","type":"array","desc":"Registration Form arguments"}]
	 * @change_log
	 * ["Since: 2.0"]
	 * @usage
	 * <?php add_filter( 'um_register_form_button_two_url', 'function_name', 10, 2 ); ?>
	 * @example
	 * <?php
	 * add_filter( 'um_register_form_button_two_url', 'my_register_form_button_two_url', 10, 2 );
	 * function my_register_form_button_two_url( $secondary_btn_url, $args ) {
	 *     // your code here
	 *     return $secondary_btn_url;
	 * }
	 * ?>
	 */
	$secondary_btn_url = apply_filters('um_register_form_button_two_url', $secondary_btn_url, $args ); ?>

	<div class="um-col-alt">

		<?php if ( ! empty( $args['secondary_btn'] ) ) { ?>

			<div class="um-left um-half">
				<input type="submit" value="<?php esc_attr_e( wp_unslash( $primary_btn_word ), 'ultimate-member' ) ?>" class="um-button" id="um-submit-btn" />
			</div>
			<div class="um-right um-half">
				<a href="<?php echo esc_url( $secondary_btn_url ); ?>" class="um-button um-alt">
					<?php _e( wp_unslash( $secondary_btn_word ),'ultimate-member' ); ?>
				</a>
			</div>

		<?php } else { ?>

			<div class="um-center">
				<input type="submit" value="<?php esc_attr_e( wp_unslash( $primary_btn_word ), 'ultimate-member' ) ?>" class="um-button" id="um-submit-btn" />
			</div>

		<?php } ?>

		<div class="um-clear"></div>

	</div>

	<?php
}
add_action( 'um_after_register_fields', 'um_add_submit_button_to_register', 1000 );


/**
 * Show Fields
 *
 * @param $args
 */
function um_add_register_fields( $args ){
	echo UM()->fields()->display( 'register', $args );
}
add_action( 'um_main_register_fields', 'um_add_register_fields', 100 );

/**
 * Saving files to register a new user, if there are fields with files.
 *
 * @param $user_id
 * @param $args
 * @param $form_data
 */
function um_registration_save_files( $user_id, $args, $form_data ) {
	if ( empty( $args['submitted'] ) ) {
		// It's only frontend case.
		return;
	}

	$files = array();

	$fields = maybe_unserialize( $form_data['custom_fields'] );
	if ( ! empty( $fields ) && is_array( $fields ) ) {
		foreach ( $fields as $key => $array ) {
			if ( isset( $args['submitted'][ $key ] ) ) {
				if ( isset( $array['type'] ) && in_array( $array['type'], array( 'image', 'file' ), true ) &&
					( um_is_temp_file( $args['submitted'][ $key ] ) || 'empty_file' === $args['submitted'][ $key ] )
				) {
					$files[ $key ] = $args['submitted'][ $key ];
				}
			}
		}
	}

	/**
	 * Filters files submitted by the UM registration or profile form.
	 *
	 * @param {array} $files   Submitted files.
	 * @param {int}   $user_id User ID.
	 *
	 * @return {array} Submitted files.
	 *
	 * @since 1.3.x
	 * @hook um_user_pre_updating_files_array
	 *
	 * @example <caption>Extends submitted files.</caption>
	 * function my_user_pre_updating_files( $files, $user_id ) {
	 *     $files[] = 'some file';
	 *     return $files;
	 * }
	 * add_filter( 'um_user_pre_updating_files_array', 'my_user_pre_updating_files', 10, 2 );
	 */
	$files = apply_filters( 'um_user_pre_updating_files_array', $files, $user_id );
	if ( ! empty( $files ) && is_array( $files ) ) {
		UM()->uploader()->replace_upload_dir = true;
		UM()->uploader()->move_temporary_files( $user_id, $files );
		UM()->uploader()->replace_upload_dir = false;
	}
}
add_action( 'um_registration_set_extra_data', 'um_registration_save_files', 10, 3 );


/**
 * Update user Full Name
 *
 * @profile name update
 *
 * @param $user_id
 * @param $args
 */
function um_registration_set_profile_full_name( $user_id, $args ) {
	/**
	 * Fires for updating user profile full name.
	 *
	 * @since 1.3.x
	 * @hook um_registration_set_extra_data
	 *
	 * @param {int}   $user_id        User ID.
	 * @param {array} $submitted_data $_POST Submission array.
	 *
	 * @example <caption>Make any custom action when updating user profile full name.</caption>
	 * function my_registration_set_extra_data( $user_id, $submitted_data ) {
	 *     // your code here
	 * }
	 * add_action( 'um_update_profile_full_name', 'my_update_profile_full_name', 10, 2 );
	 */
	do_action( 'um_update_profile_full_name', $user_id, $args );
}
add_action( 'um_registration_set_extra_data', 'um_registration_set_profile_full_name', 10, 2 );

/**
 *  Redirect from default registration to UM registration page
 */
function um_form_register_redirect() {
	$page_id       = UM()->options()->get( UM()->options()->get_core_page_id( 'register' ) );
	$register_post = get_post( $page_id );
	if ( ! empty( $register_post ) ) {
		wp_safe_redirect( get_permalink( $page_id ) );
		exit();
	}
}
add_action( 'login_form_register', 'um_form_register_redirect', 10 );
