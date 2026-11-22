// createElement is the classic JSX pragma (babel.config.js); ESLint cannot see that use.
// eslint-disable-next-line no-unused-vars
import { createElement, createRoot } from '@wordpress/element';
import App from './App';
import './admin.css';

/**
 * Mounts the admin app into the shell printed by Admin\Menu::render().
 */
function mount() {
	const container = document.getElementById( 'softglaze-sp-root' );

	if ( ! container ) {
		return;
	}

	createRoot( container ).render( <App /> );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
