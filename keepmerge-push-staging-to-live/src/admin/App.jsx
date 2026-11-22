import {
	createElement,
	Fragment,
	createPortal,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Globe,
	History,
	Inbox,
	LayoutDashboard,
	Lock,
	RadioTower,
	ScrollText,
	Send,
	Settings,
} from 'lucide-react';
import Tabs from './components/Tabs';
import Toast from './components/Toast';
import OverviewScreen from './screens/OverviewScreen';
import SendScreen from './screens/SendScreen';
import ReceiveScreen from './screens/ReceiveScreen';
import PushesScreen from './screens/PushesScreen';
import AuditScreen from './screens/AuditScreen';
import SettingsScreen from './screens/SettingsScreen';
import { errorMessage, getOverview } from './api';

const ID_PREFIX = 'sgsp';

/**
 * The six tabs, in the order of the work: where things stand, sending (staging), receiving
 * (live), the history of pushes, the audit log and settings. Both sites show all six, because
 * one install can send and receive (blueprint §4.1).
 */
const TABS = [
	{
		id: 'overview',
		label: __( 'Overview', 'keepmerge-push-staging-to-live' ),
		icon: LayoutDashboard,
	},
	{ id: 'send', label: __( 'Send', 'keepmerge-push-staging-to-live' ), icon: Send },
	{
		id: 'receive',
		label: __( 'Receive', 'keepmerge-push-staging-to-live' ),
		icon: Inbox,
	},
	{
		id: 'pushes',
		label: __( 'Pushes', 'keepmerge-push-staging-to-live' ),
		icon: History,
	},
	{
		id: 'audit',
		label: __( 'Audit log', 'keepmerge-push-staging-to-live' ),
		icon: ScrollText,
	},
	{
		id: 'settings',
		label: __( 'Settings', 'keepmerge-push-staging-to-live' ),
		icon: Settings,
	},
];

/**
 * The tab named in the URL fragment, so a reload or a shared link opens the same tab.
 *
 * @return {string} Tab ID.
 */
function tabFromHash() {
	const id = window.location.hash.replace( '#', '' );
	return TABS.some( ( tab ) => tab.id === id ) ? id : 'overview';
}

/**
 * Host name of a URL for compact display.
 *
 * @param {string} url URL.
 * @return {string} Host, or the URL when it cannot be parsed.
 */
function hostOf( url ) {
	try {
		return new window.URL( url ).host;
	} catch ( e ) {
		return url;
	}
}

/**
 * Status chips in the page header: this site, its connections, the receive window. Rendered
 * into the header that PHP printed, so the heading itself never depends on JavaScript.
 *
 * @param {Object} props
 * @param {Object} props.overview Overview data or null while loading.
 * @param {string} props.error    Error message, if loading failed.
 */
function StatusChips( { overview, error } ) {
	const boot = window.softglazeSpAdmin || {};

	if ( error ) {
		return (
			<span className="sgsp-chip">
				<span className="sgsp-chip__text">
					{ __( 'Status unavailable', 'keepmerge-push-staging-to-live' ) }
				</span>
			</span>
		);
	}

	if ( ! overview ) {
		return (
			<span className="sgsp-chip">
				<span className="sgsp-chip__text">
					{ __( 'Checking…', 'keepmerge-push-staging-to-live' ) }
				</span>
			</span>
		);
	}

	const sends = overview.pairings.sends_to.length;
	const receives = overview.pairings.receives_from.length;
	const windowOpen = overview.receive.open;

	return (
		<>
			<span className="sgsp-chip" title={ overview.site.home_url }>
				<Globe size={ 14 } aria-hidden="true" />
				<span className="sgsp-chip__text">
					{ sprintf(
						/* translators: %s: this site's host name. */
						__( 'This site: %s', 'keepmerge-push-staging-to-live' ),
						hostOf( overview.site.home_url || boot.homeUrl || '' )
					) }
				</span>
			</span>
			<span className="sgsp-chip">
				<Send size={ 14 } aria-hidden="true" />
				<span className="sgsp-chip__text">
					{ sends
						? sprintf(
								/* translators: %d: number of live sites this site sends to. */
								_n(
									'Sends to %d site',
									'Sends to %d sites',
									sends,
									'keepmerge-push-staging-to-live'
								),
								sends
						  )
						: __( 'Not sending', 'keepmerge-push-staging-to-live' ) }
				</span>
			</span>
			<span className="sgsp-chip">
				<Inbox size={ 14 } aria-hidden="true" />
				<span className="sgsp-chip__text">
					{ receives
						? sprintf(
								/* translators: %d: number of staging sites this site receives from. */
								_n(
									'Receives from %d site',
									'Receives from %d sites',
									receives,
									'keepmerge-push-staging-to-live'
								),
								receives
						  )
						: __( 'Not receiving', 'keepmerge-push-staging-to-live' ) }
				</span>
			</span>
			<span className="sgsp-chip">
				{ windowOpen ? (
					<RadioTower size={ 14 } aria-hidden="true" />
				) : (
					<Lock size={ 14 } aria-hidden="true" />
				) }
				<span className="sgsp-chip__text">
					{ windowOpen
						? __( 'Receive window open', 'keepmerge-push-staging-to-live' )
						: __( 'Receive window closed', 'keepmerge-push-staging-to-live' ) }
				</span>
			</span>
		</>
	);
}

/**
 * Admin shell: header status, tabs and the selected panel.
 */
export default function App() {
	const [ tab, setTab ] = useState( tabFromHash );
	const [ overview, setOverview ] = useState( null );
	const [ overviewError, setOverviewError ] = useState( '' );
	const [ notice, setNotice ] = useState( null );

	const loadOverview = useCallback( () => {
		setOverviewError( '' );
		return getOverview()
			.then( ( data ) => setOverview( data ) )
			.catch( ( error ) => setOverviewError( errorMessage( error ) ) );
	}, [] );

	useEffect( () => {
		loadOverview();
	}, [ loadOverview ] );

	useEffect( () => {
		const onHash = () => setTab( tabFromHash() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	const select = useCallback( ( id ) => {
		setTab( id );
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', `#${ id }` );
		}
	}, [] );

	const notify = useCallback( ( type, message ) => setNotice( { type, message } ), [] );
	const dismiss = useCallback( () => setNotice( null ), [] );

	const statusNode = document.getElementById( 'softglaze-sp-status' );

	const screens = {
		overview: (
			<OverviewScreen
				overview={ overview }
				error={ overviewError }
				onRetry={ loadOverview }
				onNavigate={ select }
			/>
		),
		send: <SendScreen notify={ notify } onChanged={ loadOverview } />,
		receive: <ReceiveScreen notify={ notify } onChanged={ loadOverview } />,
		pushes: (
			<PushesScreen notify={ notify } />
		),
		audit: <AuditScreen notify={ notify } />,
		settings: <SettingsScreen notify={ notify } />,
	};

	return (
		<>
			{ statusNode &&
				createPortal(
					<StatusChips overview={ overview } error={ overviewError } />,
					statusNode
				) }

			<Tabs tabs={ TABS } selected={ tab } onSelect={ select } idPrefix={ ID_PREFIX } />

			{ TABS.map( ( { id } ) => (
				<div
					key={ id }
					role="tabpanel"
					id={ `${ ID_PREFIX }-panel-${ id }` }
					aria-labelledby={ `${ ID_PREFIX }-tab-${ id }` }
					className="sgsp-panel"
					hidden={ id !== tab }
					tabIndex={ 0 }
				>
					{ id === tab && screens[ id ] }
				</div>
			) ) }

			<Toast notice={ notice } onDismiss={ dismiss } />
		</>
	);
}
