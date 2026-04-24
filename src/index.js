import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'stockadaptix-root' );
	if ( ! root ) {
		return;
	}
	createRoot( root ).render( <App /> );
} );
