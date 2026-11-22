import {
	createElement,
	useCallback,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	CircleAlert,
	Clock,
	Code,
	Database,
	EyeOff,
	SlidersHorizontal,
	Trash2,
} from 'lucide-react';
import Alert from '../components/Alert';
import Card from '../components/Card';
import ConfirmDialog from '../components/ConfirmDialog';
import LoadError from '../components/LoadError';
import NamesField from '../components/NamesField';
import PanelHead from '../components/PanelHead';
import SaveBar from '../components/SaveBar';
import { SkeletonCard } from '../components/Skeleton';
import ToggleRow from '../components/ToggleRow';
import { errorMessage, getSettings, saveSettings } from '../api';

const GROUP_TITLES = {
	content: __( 'Content', 'keepmerge-push-staging-to-live' ),
	site_editor: __( 'Site editor', 'keepmerge-push-staging-to-live' ),
	menus: __( 'Menus', 'keepmerge-push-staging-to-live' ),
	terms: __( 'Categories, tags and other terms', 'keepmerge-push-staging-to-live' ),
};

const FIELD_LABELS = {
	skip_post_types: __( 'Content types', 'keepmerge-push-staging-to-live' ),
	skip_taxonomies: __( 'Terms', 'keepmerge-push-staging-to-live' ),
	option_groups: __( 'Option groups', 'keepmerge-push-staging-to-live' ),
	custom_options: __( 'Additional options', 'keepmerge-push-staging-to-live' ),
	excluded_meta: __( 'Additional meta keys to leave out', 'keepmerge-push-staging-to-live' ),
	item_batch: __( 'Items per batch', 'keepmerge-push-staging-to-live' ),
	chunk_kb: __( 'Media chunk size', 'keepmerge-push-staging-to-live' ),
	snapshot_days: __( 'Keep rollback snapshots for', 'keepmerge-push-staging-to-live' ),
	audit_days: __( 'Keep the audit log for', 'keepmerge-push-staging-to-live' ),
};

/**
 * A list of fixed names shown as code chips inside a disclosure.
 *
 * @param {Object}   props
 * @param {string}   props.summary Disclosure label.
 * @param {string[]} props.names   Names.
 */
function NameList( { summary, names } ) {
	return (
		<details className="sgsp-disclosure">
			<summary>{ summary }</summary>
			<div className="sgsp-option-list">
				{ names.map( ( name ) => (
					<code className="sgsp-code" key={ name }>
						{ name }
					</code>
				) ) }
			</div>
		</details>
	);
}

/**
 * A number input with its unit, range hint and server errors.
 *
 * @param {Object}   props
 * @param {string}   props.field    Settings key.
 * @param {string}   props.unit     Unit after the input.
 * @param {Object}   props.range    { min, max }.
 * @param {number}   props.value    Value.
 * @param {Function} props.onChange Receives the new value.
 * @param {Array}    props.errors   Server errors.
 */
function NumberField( { field, unit, range, value, onChange, errors = [] } ) {
	const id = `sgsp-setting-${ field }`;
	const hintId = `${ id }-hint`;

	return (
		<div className="sgsp-field">
			<label className="sgsp-field__label" htmlFor={ id }>
				{ FIELD_LABELS[ field ] }
			</label>
			<div className="sgsp-number">
				<input
					id={ id }
					type="number"
					inputMode="numeric"
					className="sgsp-input sgsp-input--number"
					min={ range.min }
					max={ range.max }
					step={ 1 }
					value={ value }
					aria-describedby={ hintId }
					aria-invalid={ errors.length > 0 }
					onChange={ ( event ) => {
						const raw = event.target.value;
						onChange( '' === raw ? '' : Number( raw ) );
					} }
				/>
				<span className="sgsp-number__unit">{ unit }</span>
			</div>
			<p className="sgsp-field__hint" id={ hintId }>
				{ sprintf(
					/* translators: 1: lowest value, 2: highest value. */
					__( 'From %1$d to %2$d.', 'keepmerge-push-staging-to-live' ),
					range.min,
					range.max
				) }
			</p>
			{ errors.length > 0 && (
				<ul className="sgsp-field__errors">
					{ errors.map( ( error, index ) => (
						<li key={ index }>
							<CircleAlert size={ 13 } aria-hidden="true" />
							<span>{ error.reason }</span>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * Settings tab ("Keepmerge Settings").
 *
 * The form keeps a draft; nothing is saved until "Save settings". The server checks every
 * field again and refuses the whole save with reasons if any entry is not allowed.
 *
 * @param {Object}   props
 * @param {Function} props.notify Shows a toast.
 */
export default function SettingsScreen( { notify } ) {
	const [ payload, setPayload ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );
	const [ confirmRemove, setConfirmRemove ] = useState( false );

	const load = useCallback( () => {
		setLoadError( '' );
		return getSettings()
			.then( ( data ) => {
				setPayload( data );
				setDraft( data.settings );
			} )
			.catch( ( err ) => setLoadError( errorMessage( err ) ) );
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const isDirty = useMemo(
		() => !! payload && !! draft && JSON.stringify( draft ) !== JSON.stringify( payload.settings ),
		[ payload, draft ]
	);

	const fieldErrors = ( saveError && saveError.errors ) || {};

	function set( key, value ) {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
	}

	function toggleSkip( key, name, included ) {
		setDraft( ( current ) => {
			const list = current[ key ].filter( ( item ) => item !== name );
			return { ...current, [ key ]: included ? list : list.concat( name ) };
		} );
	}

	function save( event ) {
		if ( event ) {
			event.preventDefault();
		}
		setIsSaving( true );
		setSaveError( null );

		saveSettings( draft )
			.then( ( data ) => {
				setPayload( data );
				setDraft( data.settings );
				notify(
					'success',
					data.changed && data.changed.length
						? __( 'Settings saved.', 'keepmerge-push-staging-to-live' )
						: __( 'Nothing changed.', 'keepmerge-push-staging-to-live' )
				);
			} )
			.catch( ( err ) => {
				setSaveError( {
					message: errorMessage( err ),
					errors: ( err && err.data && err.data.errors ) || {},
				} );
				notify( 'error', __( 'Settings were not saved.', 'keepmerge-push-staging-to-live' ) );
			} )
			.finally( () => setIsSaving( false ) );
	}

	const head = (
		<PanelHead
			title={ __( 'Keepmerge Settings', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'What this site sends, and what it accepts when it receives. Every field takes names, numbers or on/off choices; no field accepts code or markup.',
				'keepmerge-push-staging-to-live'
			) }
		/>
	);

	if ( loadError ) {
		return (
			<div>
				{ head }
				<LoadError message={ loadError } onRetry={ load } />
			</div>
		);
	}

	if ( ! payload || ! draft ) {
		return (
			<div>
				{ head }
				<div className="sgsp-stack">
					<SkeletonCard lines={ 5 } />
					<SkeletonCard lines={ 4 } />
					<SkeletonCard lines={ 3 } />
				</div>
			</div>
		);
	}

	const { choices, policy, ranges } = payload;
	const groups = [ 'content', 'site_editor', 'menus', 'terms' ].filter( ( group ) =>
		choices.content_types.some( ( choice ) => choice.group === group )
	);

	return (
		<form onSubmit={ save } noValidate>
			{ head }

			{ saveError && (
				<Alert tone="danger" title={ saveError.message }>
					{ Object.keys( fieldErrors ).length > 0 && (
						<ul>
							{ Object.keys( fieldErrors ).map( ( field ) =>
								fieldErrors[ field ].map( ( error, index ) => (
									<li key={ `${ field }-${ index }` }>
										{ FIELD_LABELS[ field ] || field }
										{ error.entry ? ` (${ error.entry })` : '' }: { error.reason }
									</li>
								) )
							) }
						</ul>
					) }
				</Alert>
			) }

			<div className="sgsp-stack">
				<Card
					icon={ Database }
					title={ __( 'Content types', 'keepmerge-push-staging-to-live' ) }
					description={ __(
						'Ticked types are compared and can be pushed. Orders, refunds, coupons, customers, users and comments are never included, whatever is ticked here.',
						'keepmerge-push-staging-to-live'
					) }
				>
					{ groups.map( ( group ) => {
						const key = 'terms' === group ? 'skip_taxonomies' : 'skip_post_types';
						return (
							<fieldset className="sgsp-m-0 sgsp-p-0 sgsp-border-0 sgsp-mb-4" key={ group }>
								<legend className="sgsp-choices-title">{ GROUP_TITLES[ group ] }</legend>
								<div className="sgsp-choices">
									{ choices.content_types
										.filter( ( choice ) => choice.group === group )
										.map( ( choice ) => {
											const included = ! draft[ key ].includes( choice.name );
											const inputId = `sgsp-type-${ choice.kind }-${ choice.name }`;
											return (
												<label className="sgsp-checkbox" key={ `${ choice.kind }-${ choice.name }` } htmlFor={ inputId }>
													<input
														id={ inputId }
														type="checkbox"
														checked={ included }
														onChange={ ( event ) =>
															toggleSkip( key, choice.name, event.target.checked )
														}
													/>
													<span>
														{ choice.label }
														<span className="sgsp-checkbox__meta">{ choice.name }</span>
													</span>
												</label>
											);
										} ) }
								</div>
							</fieldset>
						);
					} ) }
				</Card>

				<Card
					icon={ SlidersHorizontal }
					title={ __( 'Options', 'keepmerge-push-staging-to-live' ) }
					description={ __(
						'Site settings pushed along with the content. When this site receives, its own list here decides what it accepts, whatever the sending site offers.',
						'keepmerge-push-staging-to-live'
					) }
				>
					{ choices.option_groups.map( ( group ) => (
						<ToggleRow
							key={ group.id }
							id={ `sgsp-group-${ group.id }` }
							label={ group.label }
							checked={ !! draft.option_groups[ group.id ] }
							onChange={ ( value ) =>
								set( 'option_groups', { ...draft.option_groups, [ group.id ]: value } )
							}
						>
							<div className="sgsp-option-list">
								{ group.options.map( ( name ) => (
									<code className="sgsp-code" key={ name }>
										{ name }
									</code>
								) ) }
							</div>
						</ToggleRow>
					) ) }

					<div className="sgsp-mt-6">
						<NamesField
							id="sgsp-custom-options"
							label={ FIELD_LABELS.custom_options }
							hint={ __(
								'Exact option names, or a prefix ending in * such as elementor_*. Protected settings are refused with the reason.',
								'keepmerge-push-staging-to-live'
							) }
							placeholder="elementor_*"
							value={ draft.custom_options }
							onChange={ ( value ) => set( 'custom_options', value ) }
							errors={ fieldErrors.custom_options }
						/>
						<NameList
							summary={ sprintf(
								/* translators: %d: number of protected names and patterns. */
								__( 'Never pushed: %d protected names and patterns', 'keepmerge-push-staging-to-live' ),
								policy.option_denylist.length
							) }
							names={ policy.option_denylist }
						/>
					</div>
				</Card>

				<Card
					icon={ EyeOff }
					title={ __( 'Excluded meta keys', 'keepmerge-push-staging-to-live' ) }
					description={ __(
						'Post and term meta that is never compared or pushed. Add keys that hold data specific to one site.',
						'keepmerge-push-staging-to-live'
					) }
				>
					<NamesField
						id="sgsp-excluded-meta"
						label={ FIELD_LABELS.excluded_meta }
						hint={ __(
							'Exact meta keys, or a prefix ending in * such as _my_cache_*.',
							'keepmerge-push-staging-to-live'
						) }
						placeholder="_my_cache_*"
						value={ draft.excluded_meta }
						onChange={ ( value ) => set( 'excluded_meta', value ) }
						errors={ fieldErrors.excluded_meta }
					/>
					<NameList
						summary={ sprintf(
							/* translators: %d: number of always-excluded keys and patterns. */
							__( 'Always excluded: %d keys and patterns', 'keepmerge-push-staging-to-live' ),
							policy.always_excluded_meta.length
						) }
						names={ policy.always_excluded_meta }
					/>
				</Card>

				<Card
					icon={ Clock }
					title={ __( 'Limits and retention', 'keepmerge-push-staging-to-live' ) }
					description={ __(
						'Smaller batches suit slower hosts. Keepmerge works in short steps instead of raising PHP limits.',
						'keepmerge-push-staging-to-live'
					) }
				>
					<div className="sgsp-fieldgrid">
						<NumberField
							field="item_batch"
							unit={ __( 'items', 'keepmerge-push-staging-to-live' ) }
							range={ ranges.item_batch }
							value={ draft.item_batch }
							onChange={ ( value ) => set( 'item_batch', value ) }
							errors={ fieldErrors.item_batch }
						/>
						<NumberField
							field="chunk_kb"
							unit={ __( 'KB', 'keepmerge-push-staging-to-live' ) }
							range={ ranges.chunk_kb }
							value={ draft.chunk_kb }
							onChange={ ( value ) => set( 'chunk_kb', value ) }
							errors={ fieldErrors.chunk_kb }
						/>
						<NumberField
							field="snapshot_days"
							unit={ __( 'days', 'keepmerge-push-staging-to-live' ) }
							range={ ranges.snapshot_days }
							value={ draft.snapshot_days }
							onChange={ ( value ) => set( 'snapshot_days', value ) }
							errors={ fieldErrors.snapshot_days }
						/>
						<NumberField
							field="audit_days"
							unit={ __( 'days', 'keepmerge-push-staging-to-live' ) }
							range={ ranges.audit_days }
							value={ draft.audit_days }
							onChange={ ( value ) => set( 'audit_days', value ) }
							errors={ fieldErrors.audit_days }
						/>
					</div>
				</Card>

				<Card
					icon={ Code }
					title={ __( 'Code report', 'keepmerge-push-staging-to-live' ) }
					description={ __(
						'Keepmerge reports code differences. It does not copy, install, update or activate plugins or themes.',
						'keepmerge-push-staging-to-live'
					) }
				>
					<ToggleRow
						id="sgsp-allow-code-report"
						label={ __( 'Allow code report requests', 'keepmerge-push-staging-to-live' ) }
						hint={ __(
							'Lets a paired staging site ask this site for its plugin and theme names, versions and file checksums, to show what differs. File contents are never sent. Turning this on or off is recorded in the audit log.',
							'keepmerge-push-staging-to-live'
						) }
						checked={ !! draft.allow_code_report }
						onChange={ ( value ) => set( 'allow_code_report', value ) }
					/>
				</Card>

				<Card
					icon={ Trash2 }
					title={ __( 'Uninstall', 'keepmerge-push-staging-to-live' ) }
					description={ __(
						'Uninstalling always removes the receiver users and every credential.',
						'keepmerge-push-staging-to-live'
					) }
				>
					<ToggleRow
						id="sgsp-remove-data"
						label={ __( 'Remove all Keepmerge data on uninstall', 'keepmerge-push-staging-to-live' ) }
						hint={ __(
							'Off: history, snapshots, identities and settings are kept, so a reinstall continues where you left off. On: uninstalling also deletes Keepmerge\'s tables, snapshots and settings.',
							'keepmerge-push-staging-to-live'
						) }
						checked={ !! draft.remove_data_on_uninstall }
						onChange={ ( value ) => {
							if ( value ) {
								setConfirmRemove( true );
							} else {
								set( 'remove_data_on_uninstall', false );
							}
						} }
					/>
				</Card>
			</div>

			<SaveBar
				isDirty={ isDirty }
				isSaving={ isSaving }
				onSave={ save }
				onReset={ () => {
					setDraft( payload.settings );
					setSaveError( null );
				} }
			/>

			{ confirmRemove && (
				<ConfirmDialog
					title={ __( 'Remove all data on uninstall?', 'keepmerge-push-staging-to-live' ) }
					confirmLabel={ __( 'Turn on', 'keepmerge-push-staging-to-live' ) }
					destructive
					onCancel={ () => setConfirmRemove( false ) }
					onConfirm={ () => {
						set( 'remove_data_on_uninstall', true );
						setConfirmRemove( false );
					} }
				>
					<p>
						{ __(
							'When Keepmerge is uninstalled, its push history, rollback snapshots, item identities, audit log and settings will be deleted from this site. Pushes made before cannot be rolled back afterwards.',
							'keepmerge-push-staging-to-live'
						) }
					</p>
					<p>
						{ __(
							'Nothing is deleted now. The change takes effect when you save settings.',
							'keepmerge-push-staging-to-live'
						) }
					</p>
				</ConfirmDialog>
			) }
		</form>
	);
}
