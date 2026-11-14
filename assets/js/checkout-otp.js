/**
 * Phone verification on the classic WooCommerce checkout.
 *
 * @package Saizul\BdSms
 */
( function ( $, config ) {
	'use strict';

	if ( ! config ) {
		return;
	}

	var $box = $( '#woo-bd-sms-otp' );
	var verifiedPhone = '';
	var timer = null;

	function selectedMethod() {
		return $( 'input[name="payment_method"]:checked' ).val() || '';
	}

	function phone() {
		return $.trim( $( '#billing_phone' ).val() || '' );
	}

	function status( text, isError ) {
		$box.find( '.woo-bd-sms-otp__status' ).text( text ).toggleClass( 'woocommerce-error', !! isError );
	}

	function toggle() {
		$box = $( '#woo-bd-sms-otp' );
		var needed = config.methods.indexOf( selectedMethod() ) !== -1;
		$box.toggle( needed );
		if ( needed && verifiedPhone && verifiedPhone === phone() ) {
			status( config.i18n.verified, false );
			$box.find( '.woo-bd-sms-otp__send, .woo-bd-sms-otp__entry' ).hide();
		}
	}

	function cooldown( seconds ) {
		var $send = $box.find( '.woo-bd-sms-otp__send' );
		window.clearInterval( timer );
		$send.prop( 'disabled', true );
		timer = window.setInterval( function () {
			seconds -= 1;
			if ( seconds <= 0 ) {
				window.clearInterval( timer );
				$send.prop( 'disabled', false ).text( config.i18n.resend );
				return;
			}
			$send.text( config.i18n.wait.replace( '%d', seconds ) );
		}, 1000 );
	}

	function request( action, data ) {
		return $.post( config.ajaxUrl, $.extend( { action: action, nonce: config.nonce, phone: phone() }, data || {} ) );
	}

	$( document.body ).on( 'click', '.woo-bd-sms-otp__send', function () {
		var $button = $( this );
		$button.prop( 'disabled', true ).text( config.i18n.sending );
		request( 'woo_bd_sms_otp_send' )
			.done( function ( response ) {
				status( response.data.message + ' ' + config.i18n.enterCode, false );
				$box.find( '.woo-bd-sms-otp__entry' ).show();
				$box.find( '#woo_bd_sms_otp_code' ).trigger( 'focus' );
				cooldown( response.data.cooldown || 60 );
			} )
			.fail( function ( xhr ) {
				var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
				status( data.message || config.i18n.error, true );
				if ( data.retry_after ) {
					cooldown( data.retry_after );
				} else {
					$button.prop( 'disabled', false ).text( config.i18n.resend );
				}
			} );
	} );

	$( document.body ).on( 'click', '.woo-bd-sms-otp__verify', function () {
		request( 'woo_bd_sms_otp_verify', { code: $( '#woo_bd_sms_otp_code' ).val() } )
			.done( function ( response ) {
				verifiedPhone = phone();
				status( response.data.message, false );
				$box.find( '.woo-bd-sms-otp__send, .woo-bd-sms-otp__entry' ).hide();
			} )
			.fail( function ( xhr ) {
				var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
				status( data.message || config.i18n.error, true );
			} );
	} );

	$( document.body ).on( 'change', '#billing_phone', function () {
		if ( verifiedPhone && verifiedPhone !== phone() ) {
			verifiedPhone = '';
			status( '', false );
			$box.find( '.woo-bd-sms-otp__send' ).show();
		}
	} );

	$( document.body ).on( 'updated_checkout payment_method_selected', toggle );
	$( document.body ).on( 'change', 'input[name="payment_method"]', toggle );
	$( toggle );
}( jQuery, window.wooBdSmsOtp ) );
