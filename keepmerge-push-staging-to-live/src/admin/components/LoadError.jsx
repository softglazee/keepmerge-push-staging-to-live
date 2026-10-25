import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { RefreshCw } from 'lucide-react';
import Alert from './Alert';

/**
 * Shown when a tab's data could not be loaded, with a way to try again.
 *
 * @param {Object}   props
 * @param {string}   props.message  What went wrong.
 * @param {Function} props.onRetry  Loads again.
 */
export default function LoadError( { message, onRetry } ) {
	return (
		<Alert
			tone="danger"
			title={ __( 'This information could not be loaded.', 'keepmerge-push-staging-to-live' ) }
		>
			<p className="sgsp-m-0">{ message }</p>
			<button
				type="button"
				className="sgsp-button sgsp-button--secondary sgsp-mt-3"
				onClick={ onRetry }
			>
				<RefreshCw size={ 15 } aria-hidden="true" />
				{ __( 'Try again', 'keepmerge-push-staging-to-live' ) }
			</button>
		</Alert>
	);
}
