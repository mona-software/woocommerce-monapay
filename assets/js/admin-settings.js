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
			var $button = $( this );
			var $spinner = $( '#monapay-test-spinner' );
			var $result = $( '#monapay-test-result' );

			if ( ! window.confirm( monaPayAdmin.confirmTest ) ) {
				return;
			}

			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$result.text( monaPayAdmin.testing );

			$.post( monaPayAdmin.ajaxUrl, {
				action: 'monapay_test_webhook',
				nonce: monaPayAdmin.nonce
			} ).done( function ( response ) {
				var message = response && response.data && response.data.message ? response.data.message : monaPayAdmin.genericError;
				$result.text( message );
			} ).fail( function ( xhr ) {
				var response = xhr.responseJSON;
				var message = response && response.data && response.data.message ? response.data.message : monaPayAdmin.genericError;
				$result.text( message );
			} ).always( function () {
				$button.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
		} );
	} );
}( jQuery ) );

