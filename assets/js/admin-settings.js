( function ( $ ) {
	'use strict';

	function randomHex( byteLength ) {
		var bytes = new Uint8Array( byteLength );
		window.crypto.getRandomValues( bytes );
		return Array.prototype.map.call( bytes, function ( value ) {
			return value.toString( 16 ).padStart( 2, '0' );
		} ).join( '' );
	}

	$( function () {
		function runTest( options ) {
			var $spinner = $( '#monapay-test-spinner' );
			var $result = $( '#monapay-test-result' );
			var sandboxWasDisabled = $( '#monapay-test-sandbox' ).prop( 'disabled' );

			if ( ! window.confirm( options.confirm ) ) {
				return;
			}

			$( '#monapay-test-webhook, #monapay-test-sandbox' ).prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$result.text( options.testing );

			$.post( monaPayAdmin.ajaxUrl, {
				action: options.action,
				nonce: monaPayAdmin.nonce
			} ).done( function ( response ) {
				var message = response && response.data && response.data.message ? response.data.message : monaPayAdmin.genericError;
				$result.text( message );
			} ).fail( function ( xhr ) {
				var response = xhr.responseJSON;
				var message = response && response.data && response.data.message ? response.data.message : monaPayAdmin.genericError;
				$result.text( message );
			} ).always( function () {
				$( '#monapay-test-webhook' ).prop( 'disabled', false );
				$( '#monapay-test-sandbox' ).prop( 'disabled', sandboxWasDisabled );
				$spinner.removeClass( 'is-active' );
			} );
		}

		$( '#monapay-generate-secret' ).on( 'click', function () {
			var $field = $( '#woocommerce_monapay_vietqr_webhook_secret' );
			if ( ! window.crypto || ! window.crypto.getRandomValues ) {
				window.alert( monaPayAdmin.genericError );
				return;
			}
			$field.attr( 'type', 'text' ).val( randomHex( 32 ) ).trigger( 'change' ).trigger( 'focus' );
			$( '#monapay-test-result' ).text( monaPayAdmin.generated );
		} );

		$( '#monapay-test-webhook' ).on( 'click', function () {
			runTest( {
				action: 'monapay_test_webhook',
				confirm: monaPayAdmin.confirmWebhook,
				testing: monaPayAdmin.testingWebhook
			} );
		} );

		$( '#monapay-test-sandbox' ).on( 'click', function () {
			runTest( {
				action: 'monapay_test_sandbox',
				confirm: monaPayAdmin.confirmSandbox,
				testing: monaPayAdmin.testingSandbox
			} );
		} );
	} );
}( jQuery ) );
