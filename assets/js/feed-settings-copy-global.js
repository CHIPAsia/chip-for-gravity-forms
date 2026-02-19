/**
 * Feed settings: "Copy from global configuration" button.
 * Fetches global Brand ID and Secret Key via AJAX and fills the form inputs.
 */
( function( $ ) {
	$( function() {
		$( document ).on( 'click', '.gf-chip-copy-global-config', function() {
			var $btn = $( this );
			var strings = window.gf_chip_feed_settings_copy_global_strings || {};
			var nonce = strings.nonce || '';
			var action = strings.action || 'gf_chip_get_global_credentials';

			if ( ! nonce ) {
				return;
			}

			$btn.prop( 'disabled', true ).addClass( 'disabled' );

			$.post( typeof ajaxurl !== 'undefined' ? ajaxurl : window.ajaxurl, {
				action: action,
				nonce: nonce
			} ).done( function( response ) {
				if ( response && response.success && response.data ) {
					$( 'input[name*="brand_id"]' ).val( response.data.brand_id || '' );
					$( 'input[name*="secret_key"]' ).val( response.data.secret_key || '' );
				} else {
					alert( ( response && response.data && response.data.message ) ? response.data.message : ( strings.error || 'Request failed.' ) );
				}
			} ).fail( function() {
				alert( strings.error || 'Request failed.' );
			} ).always( function() {
				$btn.prop( 'disabled', false ).removeClass( 'disabled' );
			} );
		} );
	} );
})( jQuery );
