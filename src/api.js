import apiFetch from '@wordpress/api-fetch';

export function fetchSettings() {
	return apiFetch( { path: '/stockadaptix/v1/settings' } );
}

export function saveSettings( settings ) {
	return apiFetch( {
		path: '/stockadaptix/v1/settings',
		method: 'POST',
		data: settings,
	} );
}

export function previewPrice( basePrice, stock, settings ) {
	return apiFetch( {
		path: '/stockadaptix/v1/preview',
		method: 'POST',
		data: { base_price: basePrice, stock, settings },
	} );
}
