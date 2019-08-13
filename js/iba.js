jQuery( function( $ ) {
	
	function isEmail( email ) {
		
    	var regexpEmail = /^([\w-]+(?:\.[\w-]+)*)@((?:[\w-]+\.)*\w[\w-]{0,66})\.([a-z]{2,6}(?:\.[a-z]{2})?)$/i;
    	
    	return regexpEmail.test( email );
    	
	}
	
	
	function show_fill_in_all_the_fields_error( error_fields ) {
		
		// Highlight the field that caused the error.
		$( error_fields.join( ', ' ) ).addClass( 'error-field' );
		
		// Only show the error if it's not shown already.
		if ( $( '#ib-move-account-error' ).length === 0 ) {
			
			$( '#ib-move-subscriptions-from-another-account-wrapper p.submit input' ).after( '<span id="ib-move-account-error">Please, fill in all the fields.</span>' );
			
		} else {
			
			$( '#ib-move-account-error' ).text( 'Please, fill in all the fields.' );
			
		}
		
	}


	// Add Sublicensee.
	$( 'a.add-sublicensee-button' ).click( function( event ) {

		event.preventDefault();

		$( this ).closest( 'form' ).find( '.form-group' ).removeClass( 'has-error' );

		$( context ).parents( '.product-item' ).find( '.message-box' ).detach();

		var username = $( this ).closest( 'form' ).find( 'input[name="sublicensee_username"]' ).val();
		var email = $( this ).closest( 'form' ).find( 'input[name="sublicensee_email"]' ).val();
		var product_id = $( this ).closest( 'form' ).find( 'input[name="license_id"]' ).val();
		var wpnonce = $( this ).closest( 'form' ).find( 'input[name="search_sublicensee_nonce"]' ).val();

		// If all data is set.
		if ( ( ( username !== '' ) || ( email !== '' ) ) && ( product_id !== '' ) ) {

			var add_sublicensee_data = {
				action: 'ajax_add_sublicensee',
				security: IBA.ajax_nonce,
				sublicensee_username: username,
				sublicensee_email: email,
				search_sublicensee_nonce: wpnonce,
				chosen_license: product_id
			};

			var context = this,
				button_text = $( this ).html();

			$( context ).html( '<i class="fa fa-circle-o-notch fa-spin fa-fw"></i>' );

			$.ajax( {
				type: 'post',
				url: IBA.ajaxurl,
				data: add_sublicensee_data,
				success: function ( response ) {

					response = JSON.parse( response );

					$( context ).html( button_text );
					
					if ( response.status === 'fail' ) {

						$( context ).parents( '.product-item' ).append( '<div class="message-box error-bg error-red">' + response.message + '</div>' );

					} else if ( response.status === 'sublicensee_added' ) {

						if ( response.licenses_available < 1 ) {
							
							$( '.product-item.' + response.product_id ).find( '.purchase-more' ).show();
							
							$( '.product-item.' + response.product_id ).find( '.sublicensee-forms-wrapper' ).hide();
							
						} else {
							
							$( '.product-item.' + response.product_id ).find( '.purchase-more' ).hide();
							
							$( '.product-item.' + response.product_id ).find( '.sublicensee-forms-wrapper' ).show();
							
						}

						$( '.licenses-used-'  +response.product_id ).text( response.licenses_used );

						var el = $( 'ol.product-' + response.product_id + ' > li.empty' );

						$( el )
							.clone()
							.prepend( '<span><strong>' + response.sublicensee_name + '</strong> / ' + response.sublicensee_email + '</span>' )
							.insertBefore( el )
							.removeClass( 'empty' )
							.show();

						$( el )
							.find( 'a' )
							.attr( 'data-sub_id', response.sublicensee_id );

						// Clear username form field.
						$( '.add-sublicensee .sublicensee-email-username' ).val( '' );

					} else if ( response.status === 'user_not_found' ) {

						$( context ).closest( '.sublicensee-forms-wrapper' ).find( '.add-sublicensee-user input[name="sublicensee_username"]' ).val( add_sublicensee_data.sublicensee_username );

						$( context ).closest( '.sublicensee-forms-wrapper' ).find( '.add-sublicensee-user input[name="sublicensee_email"]' ).val( add_sublicensee_data.sublicensee_email );

						$( context ).closest( '.sublicensee-forms-wrapper' ).find( '.add-sublicensee-user' ).slideDown( 'fast' );

					} else {

						console.log( response );

					}

				},
				error: function ( response, textStatus, errorThrown ) {

					$( context ).html( button_text );
					
					console.log( response );

					console.log( textStatus );

					$( context ).parents( '.product-item' ).append( '<div class="message-box error-bg error-red">AJAX error! Please, refresh the page and try again or contact our support team.</div>' );

				}
			} );
			
		}

	} );
	
	
	// Make it submit the form via AJAX on "enter" key press.
	$( '.add-sublicensee .sublicensee-email-username' ).keypress( function( event ) {
		
		if ( event.which === 13 ) {
			
			event.preventDefault();
			
			$( this ).parents( '.add-sublicensee' ).find( '.add-sublicensee-button' ).click();
			
			return false;
			
		}
		
	} );


	// Cancel inviting new user.
	$( '.add-user-cancel-button' ).click( function() {

		$( this ).closest( '.add-sublicensee-user' ).slideUp( 'fast' );

	} );


	// Create sublicensee.
	$( '.add-user-button' ).click( function( event ) {

		event.preventDefault();

		var sublicensee_username        = $( this ).closest( 'form' ).find( 'input[name="sublicensee_username"]' ).val();
		var sublicensee_email           = $( this ).closest( 'form' ).find( 'input[name="sublicensee_email"]' ).val();
		var sublicensee_first_name      = $( this ).closest( 'form' ).find( 'input[name="sublicensee_first_name"]' ).val();
		var sublicensee_last_name       = $( this ).closest( 'form' ).find( 'input[name="sublicensee_last_name"]' ).val();
		var sublicensee_pass            = $( this ).closest( 'form' ).find( 'input[name="sublicensee_pass"]' ).val();
		var sublicensee_confirm_pass    = $( this ).closest( 'form' ).find( 'input[name="sublicensee_confirm_pass"]' ).val();
		var license_id                  = $( this ).closest( 'form' ).find( 'input[name="license_id"]' ).val();
		var create_sublicensee_nonce    = $( this ).closest( 'form' ).find( 'input[name="create_sublicensee_nonce"]' ).val();

		var create_sublicensee_data = {
			action: 'ajax_create_sublicensee',
			security: IBA.ajax_nonce,
			sublicensee_username: sublicensee_username,
			sublicensee_email: sublicensee_email,
			sublicensee_pass: sublicensee_pass,
			sublicensee_confirm_pass: sublicensee_confirm_pass,
			sublicensee_first_name: sublicensee_first_name,
			sublicensee_last_name: sublicensee_last_name,
			chosen_license: license_id,
			create_sublicensee_nonce: create_sublicensee_nonce
		};

		// Clear previous messages.
		$( '.add-sublicensee-user .bg-warning' ).remove();

		var context = this,
			button_text = $( this ).html();

		$( context ).html( '<i class="fa fa-circle-o-notch fa-spin fa-fw"></i>' );

		$( context ).parents( '.product-item' ).find( '.message-box' ).hide();

		$.ajax( {
			type: 'post',
			url: IBA.ajaxurl,
			data: create_sublicensee_data,
			success: function( response ) {

				$( context ).html( button_text );

				response = JSON.parse( response );

				if ( response.status === 'fail' ) {

					$( context ).parents( '.product-item' ).append( '<div class="message-box error-bg error-red">' + response.message + '</div>' );

				} else if ( response.status === 'sublicensee_user_added' ) {

					$( '.licenses-used-' + response.product_id ).text( response.licenses_used );

					if ( response.licenses_available < 1 ) {
						
						$( '.product-item.' + response.product_id ).find( '.purchase-more' ).show();
						
						$( '.product-item.' + response.product_id ).find( '.sublicensee-forms-wrapper' ).hide();
					
					} else {
						
						$( '.product-item.' + response.product_id ).find( '.purchase-more' ).hide();
						
						$( '.product-item.' + response.product_id ).find( '.sublicensee-forms-wrapper' ).show();
					
					}

					var el = $( 'ol.product-' + response.product_id + ' > li.empty' );

					$( el )
						.clone()
						.prepend( '<span><strong>' + response.sublicensee_name + '</strong> / ' + response.sublicensee_email + '</span>' )
						.insertBefore( el )
						.removeClass( 'empty' )
						.show();

					$( el )
						.find( 'a' )
						.attr( 'data-sub_id', response.sublicensee_id );

					$( context ).parents( '.product-item' ).append( '<div class="message-box success-bg success-green">User has been created and added as a licensee!</div>' );

					// Clear forms.
					$( '.add-sublicensee .sublicensee-email-username' ).val( '' );
					
					$( '.add-sublicensee-user input' ).val( '' );
					
					$( '.add-sublicensee-user' ).hide( 'slow' );
					
					// Hide "success" message after a while.
					$( '.success-bg' ).fadeOut( 8000, function() {
						
						$( this ).remove();
						
					} );
					
				} else {

					console.log( response );

				}

			},
			error: function( response, textStatus, errorThrown ) {

				$( context ).html( button_text );
				
				console.log( response );

				console.log( textStatus );

				$( context ).parents( '.product-item' ).append( '<div class="message-box error-bg error-red">AJAX error! Please, refresh the page and try again or contact our support team.</div>' );

			}

		} );
		
	} );


	// Deactivate sub-licensee.
	$( '#products-list' ).on( 'click', '.deactivate-sublicensee-button', function( event ) {
		
		event.preventDefault();

		var sublicensee_id = $( this ).data( 'sub_id' );

		var product_id = $( this ).data( 'license_id' );	// Select correct "Chosen License ID".

		var deactivate_sublicensee_data = {
			action: 'ajax_deactivate_sublicensee',
			security: IBA.ajax_nonce,
			deactivate_sublicensee: sublicensee_id,
			chosen_license: product_id
		};

		var context = this,
			button_text = $( this ).html();

		$( context ).addClass( 'spinner' );
		
		// Clear old messages.
		$( this ).siblings( '.error-bg' ).remove();

		$.ajax( {
			type: 'post',
			url: IBA.ajaxurl,
			data: deactivate_sublicensee_data,
			success: function( response ) {

				response = JSON.parse( response );

				if ( response.status === 'fail' ) {

					$( context ).after( '<div class="message-box error-bg error-red">' + response.message + '</div>' );

					$( context ).removeClass( 'spinner' );
					
					$( context ).html( button_text );
					
				} else if ( response.status === 'deactivated' ) {

					$( '.licenses-used-' + response.product_id ).text( response.licenses_used );

					if ( response.licenses_available < 1 ) {
						
						$( '.product-item.' + response.product_id ).find( '.purchase-more' ).show();
						
						$( '.product-item.' + response.product_id ).find( '.sublicensee-forms-wrapper' ).hide();
						
					} else {
						
						$( '.product-item.' + response.product_id ).find( '.purchase-more' ).hide();
						
						$( '.product-item.' + response.product_id ).find( '.sublicensee-forms-wrapper' ).show();
						
					}
					
					// Hide and then remove sub-licensee row.
					$( context ).parent().hide( 'slow', function() {
						
						$( context ).parent().remove();
						
					} );

				} else {

					$( context ).removeClass( 'spinner' );
					
					$( context ).html( button_text );
					
					console.log( response );

				}

			},
			error: function( response, textStatus, errorThrown ) {

				$( context ).removeClass( 'spinner' );
				
				$( context ).html( button_text );
				
				console.log( response );

				console.log( textStatus );

				$( context ).after( '<span class="message-box error-bg error-red">AJAX error! Please, refresh the page and try again or contact our support team.</span>' );

			}

		} );

	} );


	// Detect if there is username or email entered.
	$( '.sublicensee-email-username' ).keyup( function() {

		if ( isEmail( $( this ).val() ) ) {
			
			$( this ).closest( 'form' ).find( 'input[name="sublicensee_username"]' ).val( '' );
			
			$( this ).closest( 'form' ).find( 'input[name="sublicensee_email"]' ).val( $( this ).val() );
			
		} else {
			
			$( this ).closest( 'form' ).find( 'input[name="sublicensee_username"]' ).val( $( this ).val() );
			
			$( this ).closest( 'form' ).find( 'input[name="sublicensee_email"]' ).val( '' );
			
		}

	} );


	// "Move Subscriptions From Another Account" block.
	if ( window.location.hash === '#ib-move-subscriptions-from-another-account-wrapper' ) {
		
		$( 'html, body' ).animate( {	// Scroll down to the form.
			
	        scrollTop: $( '#ib-move-subscriptions-from-another-account-wrapper' ).offset().top - 150
	        
	    }, 'slow' );
		
		history.replaceState( {}, document.title, "." );
		
	}


	// Check the fields completion before submitting the form.
	$( '#ib-move-subscriptions-from-another-account-wrapper input[type="submit"]' ).click( function( event ) {
		
		event.preventDefault();
		
		error_fields = [];
		
		if ( $( '#ib-move-account-subscriptions-username' ).val().length === 0 ) {
			
			error_fields.push( '#ib-move-account-subscriptions-username' );
			
		}
		
		if ( $( '#ib-move-account-subscriptions-pass' ).val().length === 0 ) {
			
			error_fields.push( '#ib-move-account-subscriptions-pass' );
			
		}
		
		if ( error_fields.length !== 0 ) {
			
			show_fill_in_all_the_fields_error( error_fields );
			
			return false;
			
		} else {
			
			$( '#ib-move-subscriptions-from-another-account' ).submit();
			
		}
		
	} );
	
	$( '#ib-move-subscriptions-from-another-account-wrapper' ).on( 'click', '.error-field', function() {
		
		$( '#ib-move-account-subscriptions-username, #ib-move-account-subscriptions-pass' ).removeClass( 'error-field' );
		
		$( '#ib-move-account-error' ).fadeOut( 'slow', function() {
			
			$( '#ib-move-account-error' ).remove();
		
		} );
		
	} );
	
	if ( $( '#ib-move-account-success' ).length > 0 ) {
		
		$( '#ib-move-account-subscriptions-username, #ib-move-account-subscriptions-pass' ).click( function() {
			
			$( '#ib-move-account-success' ).fadeOut( 'slow', function() {
				
				$( '#ib-move-account-success' ).remove();
			
			} );
			
		} );
		
	}
	
} );

