<?php
/**
 * Plugin Name: Insomniac Browser and WooCommerce + Subscriptions Compatibility
 * Plugin URI: http://webatix.com/supporturl
 * Description: Insomniac Browser and WooCommerce + Subscriptions Compatibility
 * Version: 0.0.3
 * Author: Webatix
 * Author URI: http://webatix.com
 * Text Domain: ib-woocommerce
 * Domain Path: /lang/
 * License: GPL2
 */

defined( 'ABSPATH' ) || exit;	// Exit if accessed directly.

class IB_WooCommerce {

	/**
	 * Triggered on Wordpress init.
	 *
	 * @return void
	 */
	public static function init() {

		if ( ! class_exists( 'WC_Subscriptions' ) )
			return;

		// Add scripts.
		add_action( 'wp_enqueue_scripts', array( 'IB_WooCommerce', 'add_plugin_styles_and_scripts' ) );

		// "Manage your license" block on "My Account" page.
		add_action( 'woocommerce_before_my_account', array( 'IB_WooCommerce', 'manage_sublicensees' ), 1 );

		// Add user as his own sub-licensee on subscription order creation.
		add_action( 'woocommerce_subscription_status_active', array( 'IB_WooCommerce', 'add_user_as_his_own_sublicensee' ), 10, 1 );

		// Prevents sending a notification when the user is automatically added as his own sub-licensee.
		add_action( 'pre_add_user_as_his_own_sublicensee', array( 'IB_WooCommerce', 'disable_added_user_as_his_own_sublicensee_notification' ) );
		add_action( 'pre_add_user_as_his_own_sublicensee_extension', array( 'IB_WooCommerce', 'disable_added_user_as_his_own_sublicensee_notification' ) );

		// Remove sublicensees on subscription cancellation.
		add_action( 'woocommerce_subscription_status_cancelled', array( 'IB_WooCommerce', 'remove_sublicensees_on_subscription_cancellation' ), 1, 1 );

		/* Extensions */

		// Save extensions IDs as order_meta.
		add_action( 'woocommerce_checkout_update_order_meta', array( 'IB_WooCommerce', 'save_IB_license_and_extension_ids_as_order_meta' ), 10, 2 );

		// Update cached list of extensions ids on adding or removing a sub-licensee.
		add_action( 'ib_sublicensee_added', array( 'IB_WooCommerce', 'update_cached_user_list_of_extensions' ), 10 );

		add_action( 'ib_sublicensee_removed', array( 'IB_WooCommerce', 'update_cached_user_list_of_extensions' ), 10 );

		// "Move subscriptions from other accounts" block.
		add_action( 'ib_after_myaccount_subscriptions_table', array( 'IB_WooCommerce', 'move_subscriptions_from_other_accounts') );


		/* Other */

		// Displays a popup warning for unassigned licenses on the payment confirmation page.
		add_action( 'wp_footer', array( 'IB_WooCommerce', 'maybe_display_unassigned_licenses_popup' ) );

		// Prevent increasing Edge qty in the cart if user does not have enough annual IB subscriptions.
		add_action( 'woocommerce_after_cart_item_quantity_update', array( 'IB_WooCommerce', 'maybe_prevent_increasing_edge_qty_in_cart' ), 10, 4 );
		add_filter( 'woocommerce_add_cart_item', array( 'IB_WooCommerce', 'prevent_adding_too_many_edge_licenses' ) );

		// Prevent IB qty being > 1.
		add_action( 'woocommerce_after_cart_item_quantity_update', array( 'IB_WooCommerce', 'prevent_IB_qty_bigger_than_one' ), 10, 4 );

		// Make sure there are no extra Edge licenses in the cart after user removed IB annual/bi-annual product.
		add_action( 'woocommerce_cart_item_removed', array( 'IB_WooCommerce', 'check_edge_qty_after_ib_product_removed_from_the_cart' ), 10, 2 );

		//Add AJAX Handlers for license management
		add_action('wp_ajax_ajax_add_sublicensee', array( 'IB_WooCommerce', 'ajax_add_sublicensee' ));

		add_action('wp_ajax_ajax_create_sublicensee', array( 'IB_WooCommerce', 'ajax_create_sublicensee' ));

		add_action('wp_ajax_ajax_deactivate_sublicensee', array( 'IB_WooCommerce', 'ajax_deactivate_sublicensee' ));

	}


	/**
	 * Prevents the Forms / Assigning Licenses / Assignee Notification from being sent when the user is being
	 * automatically added as sublicensee of himself after sign up.
	 *
	 * If the user adds himself as a sublicensee manually, the same notification should be triggered.
	 *
	 * @see IB-117
	 */
	public static function disable_added_user_as_his_own_sublicensee_notification() {

		/**
		 * Notification IDs:
		 *
		 * dev.insomniacbrowser.com: 5aa2bb7c58ff7
		 * www.insomniacbrowser.com: 5aa2bb7c58ff7
		 */
		add_filter( 'ib_send_gravity_forms_notification_5aa2bb7c58ff7', '__return_false' );

	}


	/**
	 * Adds plugin scripts and styles to "My Account" page and unassigned licenses popup scripts and styles everywhere (if needed).
	 *
	 * @return void
	 */
	public static function add_plugin_styles_and_scripts() {

		global $post;

		if ( intval( get_option('woocommerce_myaccount_page_id') ) === $post->ID ) {

			wp_enqueue_style( 'ib-woocommerce-styles', plugins_url( 'css/iba.css', __FILE__ ) );

			wp_register_script( 'ib-woocommerce-script', plugins_url( 'js/iba.js', __FILE__ ) );
			$params = array(
				'ajaxurl' => admin_url('admin-ajax.php'),
				'ajax_nonce' => wp_create_nonce('ib-ajax-safe-manage-sublicensees'),
				'error_cannot_use_email_as_username' => __( 'You cannot use an email address as a username.', 'ib-woocommerce' )
			);
			wp_localize_script( 'ib-woocommerce-script', 'IBA', $params );

			wp_enqueue_script( 'ib-woocommerce-script' );

		}

	}


	/**
	 * Displays the block, responsible for sublicensees management on "My Account" page
	 *
	 * @return void
	 */
	public static function manage_sublicensees() {

		global $current_user;

		if ( empty( $current_user ) ) {

			$current_user = get_currentuserinfo();

		}

		//get user extensions
		$user_extensions = self::get_user_active_extensions( $current_user->ID );

		//license id is extensions id that it's signed with
		$chosen_license_id = false;

		if ( ! empty( $_POST['chosen_license'] ) ) {

			$chosen_license_id = $_POST['chosen_license'];

		}

		// -1 is the license ID for IB
		if ( '-1' === $chosen_license_id ) {

			//get number of licenses for IB subscription
			$total_number_of_licenses = self::get_total_number_of_licenses( $current_user->ID );

		} else if ( ! empty( $chosen_license_id ) ) {

			$total_number_of_licenses = $user_extensions[ $chosen_license_id ]['qty'];

		} else {

			$total_number_of_licenses = 0;

		}

		$sublicensees = self::get_user_sublicensees( $current_user->ID, $chosen_license_id );

		//$licenses_available = $total_number_of_licenses - count( $sublicensees );

		?>

		<?php do_action( 'woocommerce_before_manage_licenses_block' ); ?>

		<h4><?php _e( 'Your Licenses', 'ib-woocommerce' ); ?></h4>

		<figure class="manage-licenses">

			<div id="manage-your-licenses-form-wrapper">

				<div id="ib-products-you-own-wrapper">

					<?php

					$number_of_ib_licenses = self::get_total_number_of_licenses( $current_user->ID );

					$extension_licenses_exist = false;

					foreach ( $user_extensions as $extension ) {

						if ( $extension['qty'] ) {

							$extension_licenses_exist = true;

						}

					}

					if ( $number_of_ib_licenses || $extension_licenses_exist ) {

						$sublicensees = self::get_user_sublicensees( $current_user->ID );

						$licenses_available = $number_of_ib_licenses - count( $sublicensees );

						?>

						<div id="products-list">

							<ul class="overview">

								<li class="product-item white-bg-content -1">

									<h5 class="product-title">
									
										<img class="product-thumbnail" src="<?php echo esc_url( wp_get_attachment_image_url( get_post_thumbnail_id( IB_PRODUCT_ID ), 'thumbnail', true ) ); ?>" width="24" height="24" />
									
										<?php printf( __( 'Insomniac Browser Licenses <div>Licenses in use: <strong><span class="licenses-used--1">%d</span> of %d</strong></div>', 'ib-woocommerce' ), count( $sublicensees ), $number_of_ib_licenses ); ?>
										
									</h5>

									<?php self::sublicensees_list_view( $sublicensees, $licenses_available, '-1', __( 'Insomniac Browser', 'ib-woocommerce' ) ); ?>

								</li>

								<?php

								if ( ! empty( $user_extensions ) ) {

									foreach( $user_extensions as $extension ) {

										$sublicensees = self::get_user_sublicensees( $current_user->ID, $extension['extension_id'] );

										$licenses_available = $extension['qty'] - count( $sublicensees );

										?>
										
										<li class="product-item white-bg-content <?php echo $extension['extension_id']; ?>">

											<h5 class="product-title">
											
												<img class="product-thumbnail" src="<?php echo esc_url( wp_get_attachment_image_url( get_post_thumbnail_id( self::get_product_id_by_extension_id( $extension['extension_id'] ) ), 'thumbnail', true ) ); ?>" width="24" height="24" />
											
												<?php printf( __( '%s <div>Licenses in use: <strong><span class="licenses-used-%s">%d</span> of %d</strong></div>', 'ib-woocommerce' ), $extension['name'], $extension['extension_id'], count( $sublicensees ), $extension['qty'] ); ?>
											
											</h5>

											<?php self::sublicensees_list_view( $sublicensees, $licenses_available, $extension['extension_id'], $extension['name'] ); ?>

										</li>

										<?php

									}

								}

								?>

							</ul>

						</div>

						<?php

					} else {

						?>

						<p><?php printf( __( 'You have no active licenses. To get one, %s.', 'ib-woocommerce' ), '<a href="' . site_url( 'store' ) . '">signup here</a>' ); ?></p>

						<?php

					}

					?>

				</div>

				<div id="ib-licenses-granted-wrapper" class="white-bg-content">

					<h4><?php _e( 'Licenses Assigned to You By Other Users', 'ib-woocommerce' ); ?></h4>

					<?php

					$licenses_granted = self::ib_get_granted_licenses( $current_user->ID );

					if ( ! empty( $licenses_granted ) ) {

						?>

						<ul id="ib-product-licenses-granted" class="overview">
							<?php

							foreach ( $licenses_granted as $product_name => $granted ) {

								?>

								<li>

									<h4><?php echo $product_name; _e( ' Granted By:', 'ib-woocommerce' ); ?></h4>

									<?php

									if ( ! empty( $granted ) ) {

										?>

										<ol>

											<?php

											foreach ( $granted as $license_owner ) {

												?>

												<li><?php echo $license_owner['username'], ' ', $license_owner['email']; ?></li>

												<?php

											}

											?>

										</ol>

										<?php

									}

									?>

								</li>

								<?php

							}

							?>
						</ul>

						<?php

					} else {

						?>

						<p><?php _e( 'This account has no licenses assigned by other people. If you think this is wrong, ask your boss to check their My Account page and assign you one.', 'ib-woocommerce' ); ?></p>

						<?php

					}

					?>

				</div>

				<div class="clear"></div>

			</div>

			<div class="clear"></div>

		</figure>

		<?php

		do_action( 'woocommerce_after_manage_licenses_form' );
	}


	/**
	 * Sublicensees list view.
	 *
	 * @param array $sublicensees
	 * @param int $licenses_available
	 * @param string $license_id (optional) "-1" (IB license) by default
	 * @param string $product_name (optional) "Insomniac Browser" by default
	 *
	 * @return void
	 */
	private static function sublicensees_list_view( $sublicensees, $licenses_available, $license_id = -1, $product_name = 'Insomniac Browser' ) {

		?>

		<ol class="product-<?php echo $license_id; ?>">

			<?php

			foreach ( $sublicensees as $sublicensee ) {

				?><li><span><strong><?php

				echo $sublicensee->data->user_login, '</strong> / ', $sublicensee->data->user_email;

				?></span><a href="#" class="deactivate-sublicensee-button" data-license_id="<?php echo $license_id; ?>" data-sub_id="<?php echo $sublicensee->ID; ?>" title="<?php _e( 'Revoke Access', 'ib-woocommerce' ); ?>"></a></li><?php

			}

			?>

			<li class="empty" style="display: none"><a href="#" class="deactivate-sublicensee-button" data-license_id="<?php echo $license_id; ?>" data-sub_id="" title="<?php _e( 'Revoke Access', 'ib-woocommerce' ); ?>"></a></li>

		</ol>

		<div class="sublicensee-forms-wrapper" <?php if ( $licenses_available < 1 ) { ?> style="display: none" <?php } else { ?> style="display: block" <?php } ?> >

			<!-- Search existing user form -->
			<form class="add-sublicensee" method="POST">

				<div class="row">

					<div class="col-md-12">
						<p>
							<label>
								<?php _e( 'Enter the username or email address of the person you want to add or invite', 'ib-woocommerce' ); ?>
							</label>
						</p>

					</div>

					<div class="col-md-8">

						<input class="form-control sublicensee-email-username" name="sublicensee-email-username" type="text" placeholder="Username or email address"/>
						<input type="hidden" name="sublicensee_email"/>
						<input type="hidden" name="sublicensee_username"/>
						<input type="hidden" name="license_id" value="<?php echo $license_id; ?>"/>
						<input type="hidden" name="search_sublicensee_nonce" value="<?php echo wp_create_nonce('search_sublicensee'); ?>"/>

					</div>
					<div class="col-md-4">

						<a href="#" class="button button-secondary medium btn-block add-sublicensee-button" data-license_id="<?php echo $license_id; ?>" data-license_product_name="<?php echo $product_name; ?>">
							<?php _e( 'Assign / Add new user', 'ib-woocommerce' ); ?>
						</a>

					</div>
				</div>
			</form>

			<!-- Add new user form -->
			<figure class="add-sublicensee-user" style="display: none">

				<div class="bg-warning">This user was not found. Please send them an invite here:</div>

				<form>
					<div class="row">
						<div class="col-md-12">
							<div class="form-group">
								<label class="control-label">Username</label>
								<input class="form-control" type="text" name="sublicensee_username" placeholder="Username"/>
							</div>

							<div class="form-group">
								<label class="control-label">Email</label>
								<input type="text" name="sublicensee_email" placeholder="Email address"/>
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group">
								<label>
									<?php _e( 'First Name', 'ib-woocommerce' ); ?>
								</label>
								<input type="text" name="sublicensee_first_name" />
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group">
								<label>
									<?php _e( 'Last Name', 'ib-woocommerce' ); ?>
								</label>
								<input type="text" name="sublicensee_last_name" />
							</div>
						</div>

						<div class="col-md-12">
							<div class="form-group">
								<label>
									<?php _e( 'Password', 'ib-woocommerce' ); ?>
								</label>
								<input type="password" name="sublicensee_pass" />
							</div>

							<div class="form-group">
								<label>
									<?php _e( 'Confirm Password', 'ib-woocommerce' ); ?>
								</label>
								<input type="password" name="sublicensee_confirm_pass" />

								<input type="hidden" name="license_id" value="<?php echo $license_id; ?>"/>
								<input type="hidden" name="create_sublicensee_nonce" value="<?php echo wp_create_nonce('create_sublicensee'); ?>"/>
							</div>

							<div class="form-inline">
								<div class="form-group">
									<a href="#" class="button medium add-user-button" data-license_id="<?php echo $license_id; ?>" data-license_product_name="<?php echo $product_name; ?>"><?php _e( 'License User', 'ib-woocommerce' ); ?></a>
									<a class="button medium add-user-cancel-button"><?php _e( 'Cancel', 'ib-woocommerce' ); ?></a>
								</div>
							</div>

						</div>
					</div>
				</form>
			</figure>
		</div>

		<div class="purchase-more" <?php if ( $licenses_available > 0 ) { ?> style="display: none" <?php } ?>>
			<p><?php _e( 'No more licenses available. You can re-assign a license above or purchase more licenses using the button below.', 'ib-woocommerce' ); ?></p>
			<p><a href="<?php echo site_url(); ?>/pricing" class="button medium"><?php _e( 'Buy More Licenses', 'ib-woocommerce' ); ?></a> </p>
		</div>

		<?php

	}


	/**
	 * Returns chosen license product name.
	 *
	 * @param string $chosen_license_id | default: -1 (Insomniac Browser)
	 *
	 * @return string $license_product_name
	 */
	private static function get_license_product_name( $chosen_license_id = -1 ) {

		$license_product_name = '';

		if ( ( -1 == $chosen_license_id ) || empty( $chosen_license_id ) ) {

			$license_product_name = __( 'Insomniac Browser', 'ib-woocommerce' );

		} else {

			$product_query = new WP_Query( array(
				'meta_key' => 'extension_id',
				'meta_value' => $chosen_license_id,
				'post_type' => 'product',
				'post_status' => array( 'publish', 'draft' )
			) );

			if ( $product_query->have_posts() ) {

				while ( $product_query->have_posts() ) {

					$product_query->the_post();

					$license_product_name = get_the_title();

				}

			}

		}

		return $license_product_name;

	}
	
	
	/**
	 * Returns extension product ID by extension id.
	 * 
	 * @param string $extension_id
	 * 
	 * @return int $product_id ( 0 for non-extension products )
	 */
	public static function get_product_id_by_extension_id( $extension_id ) {
		
		$product_query = new WP_Query( array(
				'meta_key' => 'extension_id',
				'meta_value' => $extension_id,
				'post_type' => 'product',
				'post_status' => array( 'publish', 'draft' )
		) );
		
		if ( $product_query->have_posts() ) {
			
			return get_the_ID();
			
		} else {
			
			return 0;
			
		}
		
	}


	/**
	 * Gets IDs of active user extensions.
	 *
	 * @param string $user_id (optional) | $current_user->ID by default
	 *
	 * @return array $extensions
	 */
	public static function get_user_active_extensions( $user_id = false ) {

		if ( empty( $user_id ) ) {

			if ( ! is_user_logged_in() ) {

				return;

			}

			global $current_user;

			if ( empty( $current_user ) ) {

				$current_user = get_currentuserinfo();

			}

			$user_id = $current_user->ID;

		}

		if ( empty( $user_id ) ) {

			return;

		}

		$extension_ids = array();

		$extensions = array();

		$processed_order_ids = array();

		$user_subscriptions = wcs_get_users_subscriptions( $user_id );

		if ( ! empty( $user_subscriptions ) ) {

			foreach( $user_subscriptions as $subscription ) {

				$paid_and_cancelled = false;

				//backwards compatibility with Woo Subscriptions < 2.0
				if ( 'cancelled' === $subscription->status ) {

					$valid_till = get_post_meta( $subscription->order->id, '_subscription_cancelled_time', true );

					$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $subscription->order->id );

					if ( time() <= $valid_till[ $subscription_key ] ) {

						$paid_and_cancelled = true;

					}

				}

				//only applies to active subscriptions (+ backwards compatibility)
				if ( $paid_and_cancelled || ( 'active' === $subscription->status ) || ( 'pending-cancel' === $subscription->status ) ) {

					// Process each order only once.
					if ( ! in_array( $subscription->order->id, $processed_order_ids ) ) {

						$processed_order_ids[] = $subscription->order->id;

						$order_extensions = get_post_meta( $subscription->order->id, '_extension_ids', true );

						if ( ! empty( $order_extensions ) ) {

							foreach ( $order_extensions as $extension_record ) {

								foreach( $extension_record as $id => $qty ) {

									if ( empty( $extensions[ $id ] ) ) {		// Group extensions by id.

										$product_name = self::get_license_product_name( $id );

										$extensions[ $id ] = array( 'extension_id' => $id, 'qty' => $qty, 'name' => $product_name );

									}	else {

										$extensions[ $id ]['qty'] += $qty;

									}

								}

							}

						}

					}

				}

			}

		}

		return $extensions;

	}


	/**
	 * Retruns licenses that were granted to user by someone else
	 *
	 * @param int $user_id
	 *
	 * @return array $granted_licenses;
	 */
	public static function ib_get_granted_licenses( $user_id ) {

		global $wpdb;

		$cuser =wp_get_current_user();

		$granted_licenses = array();

		$user_id = intval( $user_id );

		//first we get IB grants
		$parent_user_ids = $wpdb->get_results( $wpdb->prepare( 'SELECT parent_member_id FROM ' . $wpdb->prefix . 'wp_emember_members_custom
			WHERE member_id = %d AND parent_member_id != %d', array( $user_id, $cuser->ID ) ) );

		if ( ! empty( $parent_user_ids ) ) {

			$granted_licenses['Insomniac Browser'] = array();

			foreach ( $parent_user_ids as $uid ) {

				$guser = get_user_by( 'id', $uid->parent_member_id );

				if ( ! empty( $guser ) ) {

					$granted_licenses['Insomniac Browser'][] = array( 'username' => $guser->user_login, 'email' => $guser->user_email );

				}

			}

		}

		if ( empty( $granted_licenses['Insomniac Browser'] ) ) {

			unset( $granted_licenses['Insomniac Browser'] );

		}

		//then we get extensions grants
		$extensions_granted = $wpdb->get_results( $wpdb->prepare( 'SELECT parent_user_id, extension_id FROM ' . $wpdb->prefix . 'ib_woocommerce_extensions_sublicensees
			WHERE sublicensee_id = %d AND parent_user_id != %d', array( $user_id, $cuser->ID ) ) );

		if ( ! empty( $extensions_granted ) ) {

			foreach ( $extensions_granted as $ext ) {

				$guser = get_user_by( 'id', $ext->parent_user_id );

				if ( ! empty( $guser ) ) {

					$extension_product_title = $wpdb->get_var( $wpdb->prepare( 'SELECT posts.post_title FROM ' . $wpdb->prefix . 'posts AS posts
							INNER JOIN ' . $wpdb->prefix . 'postmeta AS meta ON posts.ID = meta.post_id WHERE meta.meta_key = "extension_id" AND meta.meta_value = "%s"', array( $ext->extension_id ) ) );

					if ( ! isset( $granted_licenses[ $extension_product_title ] ) ) {

						$granted_licenses[ $extension_product_title ] = array();

					}

					$granted_licenses[ $extension_product_title ][] = array( 'username' =>$guser->user_login, 'email' => $guser->user_email );

				}

			}

		}

		return $granted_licenses;

	}


	/**
	 * Displays success message on "Sublicensee" addition
	 *
	 * @param obj $user_details
	 *
	 * @return void
	 */
	private static function display_success_message( $user_details ) {

		?>

		<p id="sublicensee-successfully-added"><?php printf( __( 'We\'ve added %s as a licensee on your account. Be sure only one person is trying to use this account at a time.', 'ib-woocommerce' ), $user_details->data->user_login ); ?></p>

		<?php

	}

	/**
	 * Returns total number of user licenses (for IB).
	 *
	 * @param int $user_id (optional)
	 *
	 * @return int $number_of_licenses;
	 */
	public static function get_total_number_of_licenses( $user_id = 0 ) {

		$number_of_licenses = 0;

		if ( empty( $user_id ) ) {

			$user_id = get_current_user_id();

		}

		$user_subscriptions = wcs_get_users_subscriptions( $user_id );

		if ( ! empty( $user_subscriptions ) ) {

			foreach ( $user_subscriptions as $subscription ) {

				$subscription_products = $subscription->get_items();

				if ( ! empty( $subscription_products ) ) {

					foreach ( $subscription_products as $sp ) {

						$post_id = ( empty( $sp['variation_id'] ) ) ? $sp['product_id'] : $sp['variation_id'];

						$product = wc_get_product( $post_id );

						preg_match( '/^IB-.*-([\d]+)$/i', $product->sku, $licenses_count );

						if ( ! empty( $licenses_count[1] ) && ( ( 'active' === $subscription->status ) || ( 'pending-cancel' === $subscription->status ) ) ) {

							$number_of_licenses += intval( $licenses_count[1] );

						} else if ( ! empty( $licenses_count[1] ) && ( 'cancelled' === $subscription->status ) ) {		//backwards compatibility

							$valid_till = get_post_meta( $subscription->order->id, '_subscription_cancelled_time', true );

							$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $subscription->order->id );

							if ( time() <= $valid_till[ $subscription_key ] ) {

								$number_of_licenses += intval( $licenses_count[1] );

							}

						}

					}

				}

			}

		}

		return $number_of_licenses;

	}


	/**
	 * Returns user sublicensees
	 *
	 * @param int $user_id (optional)
	 * @param string $extension_id (optional)
	 *
	 * @return array $sublicensees
	 */
	public static function get_user_sublicensees( $user_id = 0, $extension_id = false ) {

		global $wpdb;

		$sublicensees = array();

		if ( empty( $user_id ) ) {

			$user_id = get_current_user_id();

		}

		if ( '-1' === $extension_id ) {		// -1 is the code for browser licenses

			$extension_id = false;

		}

		if ( empty( $extension_id ) ) {

			$sublicensees_ids = $wpdb->get_results( $wpdb->prepare( 'SELECT member_id FROM ' . $wpdb->prefix . 'wp_emember_members_custom WHERE parent_member_id = %d ORDER BY Id', $user_id ) );

		} else {

			$sublicensees_ids = $wpdb->get_results( $wpdb->prepare( 'SELECT sublicensee_id as member_id FROM ' . $wpdb->prefix . 'ib_woocommerce_extensions_sublicensees WHERE parent_user_id = %d AND extension_id = %s ORDER BY ID', array( $user_id, $extension_id ) ) );

		}

		if ( ! empty( $sublicensees_ids ) ) {

			foreach ( $sublicensees_ids as $sublicensee ) {

				$userdata = get_userdata( $sublicensee->member_id );

				if ( ! empty( $userdata ) ) {

					$sublicensees[] = $userdata;

				} else {

					self::deactivate_sublicensee( $user_id, $sublicensee->member_id, $extension_id );

				}

			}

		}

		return $sublicensees;

	}


	/**
	 * Adds sub-licensee to a user license
	 *
	 * @param int $license_holder_id
	 * @param int $sublicensee_id
	 * @param string $extension_id optional
	 *
	 * @return mixed bool true | string $error_message
	 */
	private static function add_sublicensee( $license_holder_id, $sublicensee_id, $extension_id = false ) {

		global $wpdb;

		if ( '-1' == $extension_id ) {		// -1 means browser licenses

			$extension_id = false;

		}

		$success = false;

		$exists = self::is_sublicensee( $sublicensee_id, $license_holder_id, $extension_id );

		if ( ! empty( $exists ) ) {

			return __( 'User is your Licensee already!', 'ib-woocommerce' );

		} else {

			if ( empty( $extension_id ) ) {

				$success = $wpdb->insert( $wpdb->prefix . 'wp_emember_members_custom',
					array(
						'member_id' => $sublicensee_id,
						'parent_member_id' => $license_holder_id
					),
					array(
						'%d',
						'%d'
					)
				);

			} else {

				$success = $wpdb->insert( $wpdb->prefix . 'ib_woocommerce_extensions_sublicensees',
					array(
						'parent_user_id' => $license_holder_id,
						'sublicensee_id' => $sublicensee_id,
						'extension_id' => $extension_id
					),
					array(
						'%d',
						'%d',
						'%s'
					)
				);

			}

		}

		if ( is_int( $success ) ) {

			$success = true;

		}

		if ( true === $success ) {

			do_action( 'ib_sublicensee_added', $sublicensee_id, $license_holder_id, $extension_id );

			// LifterLMS integration.
			if ( class_exists( 'LLMS_Student' ) ) {

				$student = new LLMS_Student( $sublicensee_id );

				$student->enroll( 113197, 'sublicensee_added' );

			}

			// Submit sub-licensee details to Gravity Forms.
			if ( class_exists( 'GFAPI' ) ) {

				if ( empty( $extension_id ) ) {

					$extension_id = -1;

				}

				$sublicensee_added = get_userdata( $sublicensee_id );

				$license_holder = get_userdata( $license_holder_id );

				$license_product_name = self::get_license_product_name( $extension_id );

				$entry = array(
					'form_id' => 109,
					'1' => $sublicensee_added->user_login,
					'2' => $sublicensee_added->user_email,
					'3' => $sublicensee_added->first_name,
					'4' => $sublicensee_added->last_name,
					'5' => $license_product_name,
					'6' => $license_holder->user_email,
					'7' => $license_holder->user_login,
				);

				$entry_id = GFAPI::add_entry( $entry );

				self::send_gravity_forms_notifications( 109, $entry_id );

			}

		}

		return $success;

	}


	/**
	 * Deactivates sub-licensee of a user license
	 *
	 * @param int $license_holder_id
	 * @param int $sublicensee_id
	 * @param string $extension_id optional
	 *
	 * @return bool true on successful deletion | bool false if sublicensee does not exist
	 */
	public static function deactivate_sublicensee( $license_holder_id, $sublicensee_id, $extension_id = false ) {

		if ( '-1' == $extension_id ) {		// -1 means browser licenses

			$extension_id = false;

		}

		if ( self::is_sublicensee( $sublicensee_id, $license_holder_id, $extension_id ) ) {

			global $wpdb;

			if ( empty( $extension_id ) ) {

				$deleted = $wpdb->delete( $wpdb->prefix . 'wp_emember_members_custom',
					array(
						'member_id' => $sublicensee_id,
						'parent_member_id' => $license_holder_id
					),
					array(
						'%d',
						'%d'
					)
				);

			} else {

				$deleted = $wpdb->delete( $wpdb->prefix . 'ib_woocommerce_extensions_sublicensees',
					array(
						'sublicensee_id' => $sublicensee_id,
						'parent_user_id' => $license_holder_id,
						'extension_id' => $extension_id
					),
					array(
						'%d',
						'%d',
						'%s'
					)
				);

			}

			if ( false === $deleted ) {

				return false;

			} else {

				do_action( 'ib_sublicensee_removed', $sublicensee_id, $license_holder_id, $extension_id );

				// LifterLMS integration.
				if ( class_exists( 'LLMS_Student' ) && ( false === $extension_id ) ) {

					$student = new LLMS_Student( $sublicensee_id );

					$student->unenroll( 113197, 'any' );

					// TODO: make it not remove user from Premium membership if he has other license owners that granted him a license.

				}

				// Submit sub-licensee details to Gravity Forms.
				if ( class_exists( 'GFAPI' ) ) {

					if ( empty( $extension_id ) ) {

						$extension_id = -1;

					}

					$deactivated_user = get_userdata( $sublicensee_id );

					$license_holder = get_userdata( $license_holder_id );

					$license_product_name = self::get_license_product_name( $extension_id );

					$entry = array(
						'form_id' => 111,
						'1' => $deactivated_user->user_login,
						'2' => $deactivated_user->user_email,
						'3' => $license_holder->user_login . ' (' . $license_holder->user_email . ')',
						'4' => $license_product_name,
					);

					$entry_id = GFAPI::add_entry( $entry );

					self::send_gravity_forms_notifications( 111, $entry_id );

				}

				return true;

			}

		} else {

			return false;

		}

	}


	/**
	 * Checks if user is a sublicensee ( of particular license holder if second argument is provided ). If so, returns parent_member_id
	 *
	 * @param int $sublicensee_id
	 * @param int $license_holder_id optional
	 * @param string $extension_id optional
	 *
	 * mixed bool false | int $license_holder_id
	 */
	public static function is_sublicensee( $sublicensee_id, $license_holder_id = false, $extension_id = false ) {

		global $wpdb;

		if ( '-1' == $extension_id ) {		// -1 means browser licenses

			$extension_id = false;

		}

		if ( ! empty( $extension_id ) ) {

			$query = 'SELECT parent_user_id FROM ' . $wpdb->prefix . 'ib_woocommerce_extensions_sublicensees WHERE sublicensee_id = %d AND extension_id = %s';

			$args = array( $sublicensee_id, $extension_id );

			//if we check whether particular user is a sublicensee of another user, we add additional arguments and modify the query
			if ( ! empty( $license_holder_id ) ) {

				$query .= ' AND parent_user_id = %d';

				$args[] = $license_holder_id;

			}

		} else {

			$query = 'SELECT parent_member_id FROM ' . $wpdb->prefix . 'wp_emember_members_custom WHERE member_id = %d';

			$args = array( $sublicensee_id );

			//if we check whether particular user is a sublicensee of another user, we add additional arguments and modify the query
			if ( ! empty( $license_holder_id ) ) {

				$query .= ' AND parent_member_id = %d';

				$args[] = $license_holder_id;

			}

		}

		$exists = $wpdb->get_var( $wpdb->prepare( $query, $args ) );

		if ( empty( $exists ) ) {

			return false;

		} else {

			return $exists;

		}

	}


	/**
	 * Sends out Gravity Forms notifications.
	 *
	 * @param int $form_id
	 * @param int $entry_id
	 *
	 * @return void
	 */
	public static function send_gravity_forms_notifications( $form_id, $entry_id ) {

		if ( ! class_exists( 'RGFormsModel' ) || ! class_exists( 'GFCommon' ) ) {

			return;

		}

		$form = RGFormsModel::get_form_meta( $form_id );

		$entry = RGFormsModel::get_lead( $entry_id );

		// Loop through all the notifications for the form so we know which ones to send.
		$notification_ids = array();

		foreach( $form['notifications'] as $id => $info ) {

			if (

				apply_filters( 'ib_send_gravity_forms_notifications', true, $id, $form, $entry ) &&
				apply_filters( 'ib_send_gravity_forms_notification_' . $id, true, $form, $entry )

			) {

				array_push( $notification_ids, $id );

			}

		}

		// Send the notifications.
		GFCommon::send_notifications( $notification_ids, $form, $entry );

	}


	/**
	 * Adds user as his own sublicensee on subscription order creation.
	 *
	 * @param object $subscription
	 *
	 * @return void
	 */
	public static function add_user_as_his_own_sublicensee( $subscription ) {

		$user_id = $subscription->get_user_id();

		$subscription_products = $subscription->get_items();

		if ( ! empty( $subscription_products ) ) {

			foreach ( $subscription_products as $sp ) {

				$product_id = ( empty( $sp['variation_id'] ) ) ? $sp['product_id'] : $sp['variation_id'];

				// For IB products.
				$product = wc_get_product( $product_id );

				preg_match( '/^IB-.*-([\d]+)$/i', $product->sku, $licenses_count );

				if ( ! empty( $licenses_count[1] ) ) {

					$already_added = self::is_sublicensee( $user_id, $user_id );

					if ( ! $already_added ) {

						do_action( 'pre_add_user_as_his_own_sublicensee', $user_id );
						self::add_sublicensee( $user_id, $user_id );

					}

				}

				// For extension products.
				$extension_id = get_post_meta( $product_id, 'extension_id', true );

				if ( ! empty( $extension_id ) ) {

					do_action( 'pre_add_user_as_his_own_sublicensee_extension', $user_id, $extension_id );
					self::add_sublicensee( $user_id, $user_id, $extension_id );

				}

			}

			// LifterLMS integration.
			if ( class_exists( 'LLMS_Student' ) ) {

				$student = new LLMS_Student( $sublicensee_id );

				$student->enroll( 115225, 'product_purchase' );

			}

		}

	}


	/**
	 * Removes sublicensees on subscription cancellation.
	 *
	 * @param object $subscription
	 *
	 * @return void
	 */
	public static function remove_sublicensees_on_subscription_cancellation( $subscription ) {

		$number_of_licenses = 0;

		$user_id = $subscription->get_user_id();

		$total_number_of_licensees = self::get_total_number_of_licenses( $user_id );

		$user_extensions = self::get_user_active_extensions( $user_id );

		$subscription_products = $subscription->get_items();

		if ( ! empty( $subscription_products ) ) {

			foreach ( $subscription_products as $sp ) {

				$post_id = ( empty( $sp['variation_id'] ) ) ? $sp['product_id'] : $sp['variation_id'];

				$sublicensees = self::get_user_sublicensees( $user_id );

				$product = wc_get_product( $post_id );

				preg_match( '/^IB-.*-([\d]+)$/i', $product->sku, $licenses_count );

				if ( ! empty( $licenses_count[1] ) ) {

					$number_of_licenses = intval( $licenses_count[1] );

				}

				if ( ! empty( $number_of_licenses ) ) {	// Browser subscriptions.

					$to_remove = count( $sublicensees ) - $total_number_of_licensees;

					//remove missing number of sublicensees from the end of the list
					$i = count( $sublicensees ) - 1;

					while ( $to_remove > 0 ) {

						self::deactivate_sublicensee( $user_id, $sublicensees[ $i ]->ID );

						--$i;

						--$to_remove;

					}

				} else {	// Extension subscriptions.

					$extension_id = get_post_meta( $post_id, 'extension_id', true );

					$sublicensees = self::get_user_sublicensees( $user_id, $extension_id );

					$to_remove = count( $sublicensees ) - $user_extensions[ $extension_id ]['qty'];

					$i = count( $sublicensees ) - 1;

					while ( $to_remove > 0 ) {

						self::deactivate_sublicensee( $user_id, $sublicensees[ $i ]->ID, $extension_id );

						--$i;

						--$to_remove;

					}

				}

			}

		}

	}


	/**
	 * Saves IB number of licenses and extensions IDs (if any) as order meta
	 *
	 * @param int $order_id
	 * @param array $posted
	 *
	 * @return void
	 */
	public static function save_IB_license_and_extension_ids_as_order_meta( $order_id, $posted ) {

		if ( ! empty( $order_id ) ) {

			$order = new WC_Order( $order_id );

			if ( ! empty( $order->id ) ) {

				$order_items = $order->get_items();

				if ( ! empty( $order_items ) ) {

					$ib_licenses = 0;

					$extension_ids = array();

					foreach ( $order_items as $item ) {

						$prod = $item->get_product();

						preg_match( '/^IB-.*-([\d]+)$/', $prod->sku, $licenses_count );

						if ( ! empty( $licenses_count[1] ) )
							$ib_licenses += intval( $licenses_count[1] ) * intval( $item['qty'] );

						$extension_id = get_post_meta( $prod->id, 'extension_id', true );

						if ( ! empty( $extension_id ) ) {

							$extension_ids[] = array( $extension_id => $item['qty'] );

						}

					}

					if ( 0 !== $ib_licenses )
						update_post_meta( $order_id, '_ib_licenses', $ib_licenses );

					if ( ! empty( $extension_ids ) )
						update_post_meta( $order_id, '_extension_ids', $extension_ids );

				}

			}

		}

	}


	/**
	 * Updates cached list of user extensions ids.
	 *
	 * @param int $sublicensee_id
	 *
	 * @return void
	 */
	public static function update_cached_user_list_of_extensions( $sublicensee_id ) {

		global $wpdb;

		$extension_ids = array();

		// Get relations between current user and his parent users (for each of the extensions).
		$parent_users = $wpdb->get_results( 'SELECT parent_user_id, extension_id
			FROM ' . $wpdb->prefix . 'ib_woocommerce_extensions_sublicensees
			WHERE sublicensee_id = ' . intval( $sublicensee_id ) );

		if ( ! empty( $parent_users ) ) {

			$parent_users_ids = array();

			foreach ( $parent_users as $parent_user ) {

				$parent_users_ids[ $parent_user->extension_id ] = $parent_user->parent_user_id;

			}

			$all_parent_users_subscriptions = array();

			foreach ( $parent_users_ids as $extension_id => $user_id ) {

				// Grab all the parent users subscriptions and group them by parent user_id.
				$all_parent_users_subscriptions[ $user_id ] = wcs_get_users_subscriptions( $user_id );

			}

			if ( ! empty( $all_parent_users_subscriptions ) ) {

				foreach ( $all_parent_users_subscriptions as $user_id => $user_subscriptions ) {

					// Loop through every parent user's subscriptions.
					foreach ( $user_subscriptions as $subscription ) {

						$paid_and_cancelled = false;

						// Backwards compatibility for Woo Subscriptions < 2.0 users who cancelled and should have access until the end of paid period.
						if ( 'cancelled' === $subscription->status ) {

							$valid_till = get_post_meta( $subscription->order->id, '_subscription_cancelled_time', true );

							$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $subscription->order->id );

							if ( time() <= $valid_till[ $subscription_key ] ) {

								$paid_and_cancelled = true;

							}

						}

						if ( $paid_and_cancelled || ( 'active' === $subscription->status ) || ( 'pending-cancel' === $subscription->status ) ) {

							$order_extensions = get_post_meta( $subscription->order->id, '_extension_ids', true );

							if ( ! empty( $order_extensions ) ) {

								foreach ( $order_extensions as $extension_record ) {

									foreach ( $extension_record as $extension_id => $qty ) {

										// Make sure that parent user's extension ($extension_id) is actually tied to the user being checked (taxonomies are in $parent_users_ids array).
										if ( ( $parent_users_ids[ $extension_id ] == $user_id ) && ( $qty > 0 ) && ! empty( $extension_id ) && ! in_array( $extension_id, $extension_ids ) ) {

											$extension_ids[] = (string) $extension_id;

										}

									}

								}

							}

						}

					}

				}

			}

		}

		update_user_meta( $sublicensee_id, '_ib_user_extensions', $extension_ids );

	}


	/**
	 * "Move subscriptions from other accounts" block (My Account's "View/Edit Subscriptions" tab).
	 *
	 * @param string $action_url
	 *
	 * @retrn void
	 */
	public function move_subscriptions_from_other_accounts( $action_url ) {

		if ( ! is_user_logged_in() ) {

			return;

		}

		$current_user_id = get_current_user_id();

		$error_messages = array();

		if ( isset( $_POST['move_subs_from_another_account'] ) ) {

			$username = sanitize_user( $_POST['ib_move_account_subscriptions_username'] );

			$pass = sanitize_text_field( $_POST['ib_move_account_subscriptions_pass'] );

			if ( empty( $username ) ) {

				$error_messages['ib_move_account_subscriptions_username'] = true;

			}

			if ( empty( $pass ) ) {

				$error_messages['ib_move_account_subscriptions_pass'] = true;

			}

			if ( empty( $error_messages ) ) {

				// First we're trying to find the user.
				$another_user_account = get_user_by( 'login', $username );

				if ( false === $another_user_account ) {

					$error_messages['user_not_found'] = true;

				} else {

					// Then we're checking if it's not the current user.
					if ( $another_user_account->ID === $current_user_id ) {

						$error_messages['the_same_user'] = true;

					} else {

						// Then we're checking his password.
						if ( false === wp_check_password( $pass, $another_user_account->data->user_pass, $another_user_account->ID ) ) {

							$error_messages['wrong_password'] = true;

						} else {

							// Now when it's confirmed that current user owns $user_from account, let's move his Stripe subscriptions.
							$another_user_subscriptions = wcs_get_users_subscriptions( $another_user_account->ID );

							$moved_subscriptions = array();

							$actions_performed = array();

							if ( ! empty( $another_user_subscriptions ) ) {

								foreach ( $another_user_subscriptions as $subscription ) {

									if ( ( 'active' === $subscription->get_status() ) && ( 'stripe' === $subscription->get_payment_method() ) ) {

										$moved_subscriptions[] = $subscription->get_id();

										// And then we move sub-licensees for these subscription.
										$subscription_products = $subscription->get_items();

										if ( ! empty( $subscription_products ) ) {

											foreach ( $subscription_products as $sp ) {

												$post_id = ( empty( $sp['variation_id'] ) ) ? $sp['product_id'] : $sp['variation_id'];

												$sublicensees = self::get_user_sublicensees( $another_user_account->ID );

												$product = wc_get_product( $post_id );

												preg_match( '/^IB-.*-([\d]+)$/i', $product->sku, $licenses_count );

												if ( ! empty( $licenses_count[1] ) ) {

													$to_remove = intval( $licenses_count[1] );

												}

												if ( ! empty( $to_remove ) ) {	// Browser subscriptions.

													$i = count( $sublicensees ) - 1;

													while ( ( $to_remove > 0 ) && ! empty( $sublicensees[ $i ]->ID ) ) {

														// Remove sub-licensee from the old parent user account.
														self::deactivate_sublicensee( $another_user_account->ID, $sublicensees[ $i ]->ID );

														// And add it to new parent user account.
														self::add_sublicensee( $current_user_id, $sublicensees[ $i ]->ID );

														--$i;

														--$to_remove;

													}

												} else {	// Extension subscriptions.

													$extension_id = get_post_meta( $post_id, 'extension_id', true );

													$sublicensees = self::get_user_sublicensees( $another_user_account->ID, $extension_id );

													$to_remove = $sp->get_quantity();

													$i = count( $sublicensees ) - 1;

													while ( ( $to_remove > 0 ) && ! empty( $sublicensees[ $i ]->ID ) ) {

														self::deactivate_sublicensee( $another_user_account->ID, $sublicensees[ $i ]->ID, $extension_id );

														self::add_sublicensee( $current_user_id, $sublicensees[ $i ]->ID, $extension_id );

														--$i;

														--$to_remove;

													}

												}

												// Now we move the subscription itself.
												update_post_meta( $subscription->get_id(), '_customer_user', $current_user_id );

											}

										}

									}

								}

							}

							if ( empty( $moved_subscriptions ) ) {

								$error_messages['no_subscriptions_to_be_moved'] = true;

							}

						}

					}

				}

			}

			// We never show entered password for security reasons.
			unset( $pass );

		}

		?>

		<div id="ib-move-subscriptions-from-another-account-wrapper">

			<h3><?php _e( 'Move Subscriptions From Another Account To Your Account', 'ib-woocommerce' ); ?></h3>

			<div class="white-bg-content">

				<p class="description">

					<?php _e( 'To move subscriptions from another account to this one, please, enter your other account\'s username and password. Please, note that only active Stripe subscriptions will be moved.', 'ib-woocommerce'); ?>

				</p>

				<form id="ib-move-subscriptions-from-another-account" method="post" action="<?php echo sanitize_url( $action_url ); ?>#ib-move-subscriptions-from-another-account-wrapper">

					<?php wp_nonce_field( 'ib_move_account_subs', 'move_subs_from_another_account' ); ?>

					<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">

						<label for="ib-move-account-subscriptions-username"><?php _e( 'Username', 'ib-woocommerce' ); ?></label>

						<input id="ib-move-account-subscriptions-username" name="ib_move_account_subscriptions_username" type="text"<?php

						if ( ! empty( $error_messages['ib_move_account_subscriptions_username'] ) || ! empty( $error_messages['user_not_found'] ) ) {

							echo ' class="error-field"';

						}

						if ( ! empty( $username ) && empty( $moved_subscriptions ) ) {

							echo ' value="', $username, '"';

						}

						?> />

					</p>

					<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">

						<label for="ib-move-account-subscriptions-pass"><?php _e( 'Password', 'ib-woocommerce' ); ?></label>

						<input id="ib-move-account-subscriptions-pass" name="ib_move_account_subscriptions_pass" type="password"<?php if ( ! empty( $error_messages['ib_move_account_subscriptions_pass'] ) ) echo ' class="error-field"'; ?> />

					</p>

					<table id="ib-move-account-subscriptions-submit-and-error-messages">

						<tr>

							<td>

								<input type="submit" class="button alt" value="<?php _e( 'Move Subscriptions', 'ib-woocommerce' ); ?>" />

							</td>

							<td>

								<?php

								if ( empty( $moved_subscriptions ) ) {

									// Show error messages (if any).
									$error_message = false;

									if ( ! empty( $error_messages['ib_move_account_subscriptions_username'] ) || ! empty( $error_messages['ib_move_account_subscriptions_pass'] )) {

										$error_message = __( 'Please, fill in all the fields.', 'ib-woocommerce' );

									} elseif ( ! empty( $error_messages['user_not_found'] ) ) {

										$error_message = __( 'User was not found. Please, enter correct user login.', 'ib-woocommerce' );

									} elseif ( ! empty( $error_messages['the_same_user'] ) ) {

										$error_message = __( 'You entered your own user login. To move another user\'s subscriptions, please, enter another user login.', 'ib-woocommerce' );

									} elseif ( ! empty( $error_messages['wrong_password'] ) ) {

										$error_message = sprintf( __( 'The password you entered for user %s is incorrect.', 'ib-woocommerce' ), $username );

									} elseif ( ! empty( $error_messages['no_subscriptions_to_be_moved'] ) ) {

										$error_message = sprintf( __( 'User %s has no active Stripe subscriptions, so there\'s nothing to move.', 'ib-woocommerce' ), $username );

									}

									if ( ! empty( $error_message ) ) {

										echo '<span id="ib-move-account-error">', $error_message, '</span>';

									}

								} else {

									// Show success message with details.
									echo '<span id="ib-move-account-success">', sprintf( __( '%d out of %d %s\'s subscriptions were successfully moved to your account.', 'ib-woocommerce' ), count( $moved_subscriptions ), count( $another_user_subscriptions ), $username ), '</span>';

									if ( class_exists( 'GFAPI' ) ) {

										$current_user_data = get_userdata( $current_user_id );

										$entry = array(
											'form_id' => 113,
											'1' => $current_user_data->user_login,
											'2' => $current_user_data->user_email,
											'3' => $another_user_account->user_login,
											'4' => $another_user_account->user_email,
											'5' => count( $moved_subscriptions ),
										);

										$entry_id = GFAPI::add_entry( $entry );

										self::send_gravity_forms_notifications( 113, $entry_id );

									}

								}

								?>

							</td>

						</tr>

					</table>

				</form>

			</div>


		</div>

		<?php

	}


	/**
	 * Displays the unassigned licenses (if any) popup on the "payment confirmation" page.
	 *
	 * @return void
	 */
	public static function maybe_display_unassigned_licenses_popup() {

		if ( ! is_user_logged_in() || ! is_order_received_page() ) {

			return;

		}

		if ( self::get_total_number_of_licenses() > count( self::get_user_sublicensees() ) ) {

			$my_account_url = trailingslashit( site_url( 'my-account' ) );

			?>

			<div id="ib-popup-overlay"></div>

			<div id="ib-popup">

				<a class="ib-popup-close ib-close"></a>

				<h5><?php _e( 'Unassigned Licenses' ); ?></h5>

				<p><?php

					printf( __( 'You have unassigned licenses in your account. Please %1$sclick here%2$s to assign them to someone on your team.', 'ib-woocommerce' ),
						'<a href="' . esc_attr( $my_account_url ) . '">',
						'</a>'
					);

					?></p>

				<div id="ib-unassigned-licenses-popup-actions" class="clearfix">

					<a href="#" class="button ib-close medium light"><?php _e( 'Later', 'ib-woocommerce' ); ?></a>

					<a href="<?php echo esc_url( $my_account_url ); ?>" class="button medium"><?php _e( 'Assign', 'ib-woocommerce' ); ?></a>


				</div>

			</div>

			<script type="text/javascript">

				jQuery( function ( $ ) {

					$( '#ib-popup .ib-close' ).on( 'click', function ( event ) {

						event.preventDefault();

						$( '#ib-popup, #ib-popup-overlay' ).remove();

					} );

				} );

			</script>

			<?php

		}

	}


	/**
	 * Prevents buying more Edge licenses than user is eligible to (max Edges === number of IB licenses user has purchased himself).
	 *
	 * @param string $cart_item_key
	 * @param int $new_quantity
	 * @param int $old_quantity
	 * @param object $wc_cart_object
	 *
	 * @return void
	 */
	public static function maybe_prevent_increasing_edge_qty_in_cart( $cart_item_key, $new_quantity, $old_quantity, $wc_cart_object ) {

		// We're only worried about increasing of the qty of Edge products.
		if ( ( EDGE_PRODUCT_ID !== $wc_cart_object->cart_contents[ $cart_item_key ]['product_id'] ) || ( $old_quantity > $new_quantity ) ) {

			return;

		}

		$number_of_edge_slots_available = self::get_number_of_edge_slots_available( $wc_cart_object->cart_contents[ $cart_item_key ]['variation']['term'] );

		// We should only let user buy as many Edge licenses as many IB annual licenses he has purchased.
		if ( $number_of_edge_slots_available < 0 ) {

			$new_quantity += $number_of_edge_slots_available;	// Do not allow to increase Edge qty too much.

			self::show_max_edge_qty_limit_error_message( $wc_cart_object->cart_contents[ $cart_item_key ]['variation']['term'] );

			$wc_cart_object->set_quantity( $cart_item_key, $new_quantity, true );

		}

	}


	/**
	 * Triggers "limit max Edge qty" check when adding Edge to the cart.
	 *
	 * @param array $cart_item_data
	 *
	 * @return array $cart_item_data
	 */
	public static function prevent_adding_too_many_edge_licenses( $cart_item_data ) {

		if ( EDGE_PRODUCT_ID === $cart_item_data['product_id'] ) {

			$number_of_edge_slots_available = self::get_number_of_edge_slots_available( $cart_item_data['variation']['term'] );

			if ( $cart_item_data['quantity'] > $number_of_edge_slots_available ) {

				self::show_max_edge_qty_limit_error_message( $cart_item_data['variation']['term'] );

				$cart_item_data['quantity'] = $number_of_edge_slots_available;

			}

		}

		return $cart_item_data;

	}


	/**
	 * Shows "Max Edge licenses limit" error message.
	 *
	 * @param string $term Annual or Bi-Annual
	 *
	 * @return void
	 */
	private static function show_max_edge_qty_limit_error_message( $term ) {

		$term_article= 'a';

		if ( 'Annual' === $term ) {

			$term_article = 'an';

		}

		wc_add_notice( sprintf(
			__( 'Only users with bi-annual or annual licenses qualify for Edge.
						To get Edge, you can upgrade a monthly subscription (if you have any) to %2$s %1$s subscription %3$shere%4$s
						or you can purchase a new %1$s license %5$shere%6$s. Each %1$s IB license gives you the ability 
						to buy one %1$s Edge license.', 'ib-woocommerce' ),
			strtolower( $term ),
			$term_article,
			'<a href="' . site_url( 'my-account/subscriptions/' ) . '">',
			'</a>',
			'<a href="' . site_url( 'pricing' ) . '">',
			'</a>' ),
			'error' );

	}


	/**
	 * Prevents IB qty being bigger than 1.
	 *
	 * @param string $cart_item_key
	 * @param int $new_quantity
	 * @param int $old_quantity
	 * @param WC_Cart object $wc_cart_object
	 *
	 * @return void
	 */
	public static function prevent_IB_qty_bigger_than_one( $cart_item_key, $new_quantity, $old_quantity, $wc_cart_object ) {

		if ( ( IB_PRODUCT_ID === $wc_cart_object->cart_contents[ $cart_item_key ]['product_id'] ) && ( $new_quantity > 1 ) ) {

			$wc_cart_object->set_quantity( $cart_item_key, 1, true );

		}

	}


	/**
	 * Makes sure that Edge qty (if there's Edge product in the cart) is not bigger than it should be.
	 *
	 * @param string $cart_item_key
	 * @param WC_Cart object $wc_cart_object
	 *
	 * @return void
	 */
	public static function check_edge_qty_after_ib_product_removed_from_the_cart( $cart_item_key, $wc_cart_object ) {

		if ( ( IB_PRODUCT_ID === $wc_cart_object->removed_cart_contents[ $cart_item_key ]['product_id'] )
		     && ( ( 'Annual' === $wc_cart_object->removed_cart_contents[ $cart_item_key ]['variation']['term'] ) || ( 'Bi-Annual' === $wc_cart_object->removed_cart_contents[ $cart_item_key ]['variation']['term'] ) ) ) {

			foreach ( $wc_cart_object->get_cart_contents() as $secondary_cart_item_key => $secondary_cart_item ) {

				// If we found Edge product in the cart with the same term as removed IB product's term, let's recalculate max qty for it.
				if ( ( EDGE_PRODUCT_ID === $secondary_cart_item['product_id'] ) && ( $secondary_cart_item['variation']['term'] === $wc_cart_object->removed_cart_contents[ $cart_item_key ]['variation']['term'] ) ) {

					// Max Edge qty is equal to what's in the cart already + number of available edge slots.
					$max_edge_qty = $secondary_cart_item['quantity'] + self::get_number_of_edge_slots_available( $secondary_cart_item['variation']['term'] );

					// If the qty is bigger than max allowed, it should be lowered down to the biggest number possible.
					if ( $secondary_cart_item['quantity'] > $max_edge_qty ) {

						self::show_max_edge_qty_limit_error_message( $secondary_cart_item['variation']['term'] );

						// Set Edge's qty to be maximum allowed, based on cart contents and previous purchases.
						$wc_cart_object->set_quantity( $secondary_cart_item_key, $max_edge_qty, true );

					}

				}

			}

		}

	}


	/**
	 * Returns the number of Edge licenses user is still eligible to purchase (on the top of what he has purchased already).
	 *
	 * @param int $user_id (optional)
	 *
	 * @return int
	 */
	public static function get_number_of_edge_slots_available( $term, $user_id = 0 ) {

		return self::get_number_of_edge_slots_available_in_the_cart( $term ) + self::get_number_of_edge_slots_available_in_user_account( $term, $user_id );

	}


	/**
	 * Returns the number of Edge licenses user can buy based on cart contents
	 * (number of Annual/Bi-Annual IB licenses that are in the cart minus number of Edge products in the cart).
	 *
	 * @param string $term Annual or Bi-Annual
	 *
	 * @return int $number_of_edge_slots_available
	 */
	private static function get_number_of_edge_slots_available_in_the_cart( $term ) {

		$number_of_edge_slots_available = 0;

		if ( count( WC()->cart->get_cart_contents() ) > 0 ) {

			foreach ( WC()->cart->get_cart_contents() as $cart_item ) {

				if ( isset( $cart_item['variation']['term'] ) && ( $term === $cart_item['variation']['term'] ) ) {

					if ( IB_PRODUCT_ID === $cart_item['product_id'] ) {

						$number_of_edge_slots_available += intval( $cart_item['variation']['number_of_licenses'] );

					} elseif ( EDGE_PRODUCT_ID === $cart_item['product_id'] ) {

						$number_of_edge_slots_available -= intval( $cart_item['quantity'] );

					}

				}

			}

		}

		return $number_of_edge_slots_available;

	}


	/**
	 * Returns the number of Edge licenses user can buy based on his previous purchases (number of Annual/Bi-Annual IB licenses minus number of Edge purchased)
	 *
	 * @param string $term Annual or Bi-Annual
	 * @param int $user_id (optional) current user id is used by default
	 *
	 * @return int $number_of_edge_slots_available
	 */
	private static function get_number_of_edge_slots_available_in_user_account( $term, $user_id = 0 ) {

		$number_of_edge_slots_available = 0;

		if ( empty( $user_id ) ) {

			$user_id = get_current_user_id();

		}

		if ( ! empty( $user_id ) ) {	// Return 0 if user is not logged in and $user_id was not specified.

			$user_subscriptions = wcs_get_users_subscriptions( $user_id );

			if ( ! empty( $user_subscriptions ) ) {

				foreach ( $user_subscriptions as $subscription ) {

					// We only calculate "active" and "pending-cancel" subscriptions.
					if ( ( ( 'active' !== $subscription->status ) && ( 'pending-cancel' !== $subscription->status ) ) ) {

						continue;

					}

					$subscription_products = $subscription->get_items();

					if ( ! empty( $subscription_products ) ) {

						foreach ( $subscription_products as $sp ) {

							if ( $sp->get_variation_id() ) {

								$product = wc_get_product( $sp->get_variation_id() );

							} else {

								$product = wc_get_product( $sp->get_product_id() );

							}

							if ( empty( $product ) ) {

								continue;

							}

							if ( IB_PRODUCT_ID === $sp->get_product_id() ) {

								if ( $term === $product->get_attribute( 'term' ) ) {

									$number_of_edge_slots_available += intval( $product->get_attribute( 'number_of_licenses' ) );

								}

							} elseif ( 'IB-annual-' === substr( $product->get_sku(), 0, 10 ) ) {		// Grandfather products compatibility. Just checking for annual as there was no bi-annual term these days.

								preg_match( '/^IB-.*-([\d]+)$/i', $product->get_sku(), $licenses_count );

								if ( ! empty( $licenses_count[1] ) ) {

									$number_of_edge_slots_available += intval( $licenses_count[1] );

								}

							} elseif ( ( EDGE_PRODUCT_ID === $sp->get_product_id() ) && ( $term === $product->get_attribute( 'term' ) ) ) {

								$number_of_edge_slots_available -= $sp->get_quantity();

							}

						}

					}

				}

			}

		}

		return $number_of_edge_slots_available;

	}


	/**
	 * Triggered on plugin activation, creates a DB table for storing extensions sublicensees
	 *
	 * @return void
	 */
	public static function activate() {

		global $wpdb;

		//$wpdb->query( 'DROP TABLE '. $wpdb->prefix . 'ib_woocommerce_extensions_sublicensees' );

		$wpdb->query( '
					CREATE TABLE IF NOT EXISTS '. $wpdb->prefix . 'ib_woocommerce_extensions_sublicensees (
						ID BIGINT(20) NOT NULL AUTO_INCREMENT,
						parent_user_id BIGINT(20),
						sublicensee_id BIGINT(20),
						extension_id VARCHAR(255),
						PRIMARY KEY (ID)
					)
		' );

	}

	/**
	 * AJAX Handler for adding sublicensee user.
	 *
	 * @return object $response
	 */
	public function ajax_add_sublicensee() {

		check_ajax_referer( 'ib-ajax-safe-manage-sublicensees', 'security' );

		$response = new stdClass();

		global $current_user;

		if ( empty( $current_user ) ) {

			$current_user = get_current_user();

		}

		// Get user extensions
		$user_extensions = self::get_user_active_extensions( $current_user->ID );

		// License id is extensions id that it's signed with.
		$chosen_license_id = false;

		if ( ! empty( $_POST[ 'chosen_license' ] ) ) {

			$chosen_license_id = $_POST['chosen_license'];

		}

		// -1 is the license ID for IB.
		if ( '-1' === $chosen_license_id ) {

			// Get number of licenses for IB subscription.
			$total_number_of_licenses = self::get_total_number_of_licenses( $current_user->ID );

		} else if ( ! empty( $chosen_license_id ) ) {

			$total_number_of_licenses = $user_extensions[ $chosen_license_id ][ 'qty' ];

		} else {

			$total_number_of_licenses = 0;

		}

		$sublicensees = self::get_user_sublicensees( $current_user->ID, $chosen_license_id );

		$licenses_available = $total_number_of_licenses - count( $sublicensees );

		// Actions with licenses.
		// User has to have a possibility to add more sublicensees.
		if ( $licenses_available > 0 ) {

				// First we search for existing user.
				if ( isset( $_POST[ 'search_sublicensee_nonce' ] ) && wp_verify_nonce( $_POST[ 'search_sublicensee_nonce' ], 'search_sublicensee' ) ) {

					// Security checks.
					if ( empty( $_POST[ 'sublicensee_username' ] ) && empty( $_POST[ 'sublicensee_email' ] ) ) {

						$response->status = 'fail';

						$response->message = __( 'Please, enter username or email!', 'ib-woocommerce' );

						wp_send_json( json_encode( $response ) );

					} else {

						// Make sure user entered.
						if ( ! empty( $_POST[ 'sublicensee_email' ] ) && ( $_POST[ 'sublicensee_email' ] !== sanitize_email( $_POST[ 'sublicensee_email' ] ) ) ) {

							$response->status = 'fail';

							$response->message = __( 'Email you entered is incorrect!', 'ib-woocommerce' );

							wp_send_json( json_encode( $response ) );

						} else {

							$user_exists = false;

							// Sanitize data.
							$sublicensee_username = ( empty( $_POST[ 'sublicensee_username' ] ) ) ? '' : sanitize_user( $_POST[ 'sublicensee_username' ] );

							$sublicensee_email = ( empty( $_POST[ 'sublicensee_email' ] ) ) ? '' : sanitize_email( $_POST[ 'sublicensee_email' ] );

							if ( ! empty( $sublicensee_username ) ) {

								$user_id = username_exists( $sublicensee_username );

							} else {

								$user_id = email_exists( $sublicensee_email );

							}

							// Add sub-licensee.
							if ( ! empty( $user_id ) ) {

								$user_exists = true;

								$adding_allowed = true;

								// If it's the Edge Membership license we wanna share, it can only be added to those who are user's browser sub-licensees.
								if ( EDGE_PRODUCT_ID === intval( $chosen_license_id ) ) {

									$IB_sublicensees = self::get_user_sublicensees( $current_user->ID );

									$adding_allowed = false;

									if ( ! empty( $IB_sublicensees ) ) {

										foreach ( $IB_sublicensees as $IB_sublicensee ) {

											if ( $user_id === $IB_sublicensee->ID ) {

												$adding_allowed = true;

												break;

											}

										}

									}

								}

								if ( true === $adding_allowed ) {

									$new_sublicensee = self::add_sublicensee( $current_user->ID, $user_id, $chosen_license_id );

									if ( true === $new_sublicensee ) {

										$sublicensee_added = get_userdata( $user_id );

										$sublicensees[] = $sublicensee_added;

										$licenses_available--;

										$response->status = 'sublicensee_added';

										$response->product_id = $chosen_license_id;

										$response->sublicensee_name = $sublicensee_added->user_login;

										$response->sublicensee_email = $sublicensee_added->user_email;

										$response->sublicensee_id = $sublicensee_added->ID;

										$response->licenses_used = count( $sublicensees );

										$response->licenses_available = $licenses_available;

										wp_send_json( json_encode( $response ) );

									} else if ( false !== $new_sublicensee ) {

										$response->status = 'fail';

										$response->message = __( 'Sublicensee already added.' );

										wp_send_json( json_encode( $response ) );


									} else {

										$response->message = __( 'Something went wrong during user creation! Please, contact site administrator.', 'ib-woocommerce' );

										$response->status = 'fail';

										wp_send_json( json_encode( $response ) );

									}

								} else {

									$response->message = __( 'You can only license a user that you\'ve previously licensed for Insomniac Browser usage.', 'ib-woocommerce' );

									$response->status = 'fail';

									wp_send_json( json_encode( $response ) );

								}

							} else {

								$response->status = 'user_not_found';

								wp_send_json( json_encode( $response ) );

							}

						}

					}
				}

		} else {

			$response->status = 'fail';

			$response->message = __( 'No more licenses available.', 'ib-woocommerce' );

			wp_send_json( json_encode( $response ) );

		}

		$response->status = 'fail';

		$response->message = __( 'Something went wrong during sub-licensee creation! Please, contact site administrator.', 'ib-woocommerce' );

		wp_send_json( json_encode( $response ) );

	}


	/**
	 * AJAX Handler for creating sublicensee user.
	 *
	 * @return object $response
	 */
	public function ajax_create_sublicensee() {

		check_ajax_referer( 'ib-ajax-safe-manage-sublicensees', 'security' );

		ob_start();
		
		$response = new stdClass();

		global $current_user;

		if ( empty( $current_user ) ) {

			$current_user = get_current_user();

		}

		// Get user extensions.
		$user_extensions = self::get_user_active_extensions( $current_user->ID );

		// License id is extensions id that it's signed with.
		$chosen_license_id = false;

		if ( ! empty( $_POST[ 'chosen_license' ] ) ) {

			$chosen_license_id = $_POST[ 'chosen_license' ];

		}

		// -1 is the license ID for IB.
		if ( '-1' === $chosen_license_id ) {

			// Get number of licenses for IB subscription.
			$total_number_of_licenses = self::get_total_number_of_licenses( $current_user->ID );

		} else if ( ! empty( $chosen_license_id ) ) {

			$total_number_of_licenses = $user_extensions[ $chosen_license_id ][ 'qty' ];

		} else {

			$total_number_of_licenses = 0;

		}

		$sublicensees = self::get_user_sublicensees( $current_user->ID, $chosen_license_id );

		$licenses_available = $total_number_of_licenses - count( $sublicensees );

		// Actions with licenses.
		// User has to have a possibility to add more sublicensees.
		if ( $licenses_available > 0 ) {

			// Create Sublicensee User.
			if ( isset( $_POST[ 'create_sublicensee_nonce' ] ) && wp_verify_nonce( $_POST[ 'create_sublicensee_nonce' ], 'create_sublicensee' ) ) {

				$add_sublicensee = true;

				// Security checks.
				if ( empty( $_POST[ 'sublicensee_username' ] ) || empty( $_POST[ 'sublicensee_email' ] ) || empty( $_POST[ 'sublicensee_pass' ] ) || empty( $_POST[ 'sublicensee_confirm_pass' ] ) || empty( $_POST[ 'sublicensee_first_name' ] ) || empty( $_POST[ 'sublicensee_last_name' ] ) ) {

					$add_sublicensee = false;

					$response->message = __( 'All the fields are required!', 'ib-woocommerce' );

					$response->status = 'fail';

					wp_send_json( json_encode( $response ) );

				} else if ( $_POST[ 'sublicensee_email' ] !== sanitize_email( $_POST[ 'sublicensee_email' ] ) ) {

					$add_sublicensee = false;

					$response->message = __( 'Email you entered is incorrect!', 'ib-woocommerce' );

					$response->status = 'fail';

					wp_send_json( json_encode( $response ) );

				} else if ( $_POST[ 'sublicensee_pass' ] !== $_POST[ 'sublicensee_confirm_pass' ] ) {

					$add_sublicensee = false;

					$response->message = __( 'Password and it\'s confirmation don\'t match!', 'ib-woocommerce' );

					$response->status = 'fail';

					wp_send_json( json_encode( $response ) );

				} else {

					$sublicensee_username = sanitize_user( $_POST[ 'sublicensee_username' ] );

					$sublicensee_email = sanitize_email( $_POST[ 'sublicensee_email' ] );

					$sublicensee_pass = sanitize_text_field( $_POST[ 'sublicensee_pass' ] );

					$sublicensee_first_name = sanitize_text_field( $_POST[ 'sublicensee_first_name' ] );

					$sublicensee_last_name = sanitize_text_field( $_POST[ 'sublicensee_last_name' ] );

					if ( is_email( $sublicensee_username ) ) {

						$add_sublicensee = false;

						$response->message = __( 'You cannot use an email address as a username.', 'ib-woocommerce' );

						$response->status = 'fail';

						wp_send_json( json_encode( $response ) );

					}

				}



				if ( isset( $sublicensee_username ) && isset( $sublicensee_pass ) && isset( $sublicensee_email ) && true === $add_sublicensee ) {

					$user_id = wp_create_user( $sublicensee_username, $sublicensee_pass, $sublicensee_email );

					// Add sub-licensee.
					if ( ! is_wp_error( $user_id ) ) {

						// Set user first and last name.
						wp_update_user( array( 'ID' => $user_id, 'first_name' => $sublicensee_first_name, 'last_name' => $sublicensee_last_name ) );

						$new_sublicensee = self::add_sublicensee( $current_user->ID, $user_id, $chosen_license_id );

						if ( true === $new_sublicensee ) {

							$sublicensee_added = get_userdata( $user_id );

							$sublicensees[] = $sublicensee_added;

							$licenses_available--;

							$successfully_added = true;

							$response->product_id = $chosen_license_id;

							$response->sublicensee_name = $sublicensee_added->user_login;

							$response->sublicensee_email = $sublicensee_added->user_email;

							$response->sublicensee_id = $sublicensee_added->ID;

							$response->licenses_used = count( $sublicensees );

							$response->licenses_available = $licenses_available;

							$response->status = 'sublicensee_user_added';

							wp_send_json( json_encode( $response ) );

						} else if ( false !== $new_sublicensee ) {

							$add_sublicensee_error = $new_sublicensee;

							$response->status = 'fail';

							$response->message = $add_sublicensee_error;

							ob_get_clean();
							
							wp_send_json( json_encode( $response ) );

						} else {

							$successfully_added = false;

							$response->status = 'fail';

							$response->message = __( 'Something went wrong during sub-licensee creation! Please, contact site administrator.', 'ib-woocommerce' );

							ob_get_clean();
							
							wp_send_json( json_encode( $response ) );

						}

					} else {

						$add_sublicensee_error = $user_id->get_error_message();

						$response->status = 'fail';

						$response->message = $add_sublicensee_error;

						ob_get_clean();
						
						wp_send_json( json_encode( $response ) );

					}

				}

			}

		} else {

			$response->status = 'fail';

			$response->message = __( 'No more licenses available.', 'ib-woocommerce' );

			ob_get_clean();
			
			wp_send_json( json_encode( $response ) );

		}

		$response->status = 'fail';

		$response->message = __( 'Something went wrong during sub-licensee creation! Please, contact site administrator.', 'ib-woocommerce' );

		ob_get_clean();
		
		wp_send_json( json_encode( $response ) );

	}

	
	/**
	 * AJAX Handler for deactivating sublicensees
	 *
	 * @return object $response
	 */
	public function ajax_deactivate_sublicensee() {

		check_ajax_referer( 'ib-ajax-safe-manage-sublicensees', 'security' );

		ob_start();
		
		$response = new stdClass();

		global $current_user;

		if ( empty( $current_user ) ) {

			$current_user = get_current_user();

		}

		//get user extensions
		$user_extensions = self::get_user_active_extensions( $current_user->ID );

		//license id is extensions id that it's signed with
		$chosen_license_id = false;

		if ( ! empty( $_POST[ 'chosen_license' ] ) ) {

			$chosen_license_id = $_POST[ 'chosen_license' ];

		}

		// -1 is the license ID for IB
		if ( '-1' === $chosen_license_id ) {

			//get number of licenses for IB subscription
			$total_number_of_licenses = self::get_total_number_of_licenses( $current_user->ID );

		} else if ( ! empty( $chosen_license_id ) ) {

			$total_number_of_licenses = $user_extensions[ $chosen_license_id ][ 'qty' ];

		} else {

			$total_number_of_licenses = 0;

		}

		$sublicensees = self::get_user_sublicensees( $current_user->ID, $chosen_license_id );

		$licenses_available = $total_number_of_licenses - count( $sublicensees );

		//Actions with licenses

		//Deactivate sublicensee if requested
		if ( ! empty( $_POST[ 'deactivate_sublicensee' ] ) ) {

			$deactivate_id = intval( $_POST[ 'deactivate_sublicensee' ] );

			//check if requested user is really current user's sublicensee (it's in array already, so we don't have to query the DB again)
			if ( ! empty( $deactivate_id ) && ! empty( $sublicensees ) ) {

				$deactivate = false;

				foreach ( $sublicensees as $key => $sublicensee ) {

					if ( $sublicensee->ID == $deactivate_id ) {

						$deactivate = true;

						break;

					}

				}

				if ( true === $deactivate ) {

					$deactivated = self::deactivate_sublicensee( $current_user->ID, $deactivate_id, $chosen_license_id );

					if ( true === $deactivated ) {

						unset( $sublicensees[ $key ] );

						$licenses_available++;

						$response->status = 'deactivated';

						$response->product_id = $chosen_license_id;

						$response->sublicensee_id = $deactivate_id;

						$response->licenses_used = count( $sublicensees );

						ob_get_clean();
						
						wp_send_json( json_encode( $response ) );

					} else {

						$response->status = 'fail';

						$response->message = __( 'Something went wrong during deactivation! Please, contact site admin.', 'ib-woocommerce' );

						ob_get_clean();
						
						wp_send_json( json_encode( $response ) );
					}

				} else {

					$response->status = 'fail';

					$response->message = __( 'User you tried to deactivate is not your sub-licensee!', 'ib-woocommerce' );

				}

			}

		}

		$response->status = 'fail';

		$response->message = __( 'Something went wrong during deactivation! Please, contact site admin.', 'ib-woocommerce' );

		ob_get_clean();
		
		wp_send_json( json_encode( $response ) );

	}

}

if ( ! defined( 'IB_PRODUCT_ID' ) ) {

	define( 'IB_PRODUCT_ID', 3020 );

}

if ( ! defined( 'EDGE_PRODUCT_ID' ) ) {

	define( 'EDGE_PRODUCT_ID', 48113 );

}

add_action( 'init', array( 'IB_WooCommerce', 'init' ) );

//create a DB table if it does not exist
register_activation_hook( __FILE__, array( 'IB_WooCommerce', 'activate' ) );