( function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settings = window.wc && window.wc.wcSettings;
	if ( ! registry || ! settings || ! window.wp || ! window.wp.element || ! window.wp.htmlEntities ) {
		return;
	}

	var data = settings.getSetting( 'monapay_vietqr_data', {} );
	var createElement = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var title = decode( data.title || 'MONA Pay' );

	function Content() {
		return createElement( 'div', null, decode( data.description || '' ) );
	}

	registry.registerPaymentMethod( {
		name: 'monapay_vietqr',
		label: createElement( 'span', null, title ),
		ariaLabel: title,
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: data.supports || [ 'products' ]
		}
	} );
}() );
