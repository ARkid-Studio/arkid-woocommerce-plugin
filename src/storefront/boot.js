/**
 * Storefront entry point.
 *
 * Kept separate from index.js so the dialog logic can be imported by tests
 * without the module immediately binding itself to the document.
 */

/**
 * Internal dependencies
 */
import './index.scss';
import { init } from './index';

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}
