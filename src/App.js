import { useEffect, useState, useCallback } from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardBody,
	CardFooter,
	ToggleControl,
	TextControl,
	SelectControl,
	Button,
	Notice,
	Spinner,
	Flex,
	FlexItem,
	__experimentalHeading as Heading,
	__experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchSettings, saveSettings } from './api';
import RulesEditor from './components/RulesEditor';
import PreviewSimulator from './components/PreviewSimulator';

export default function App() {
	const [ settings, setSettings ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ dirty, setDirty ] = useState( false );

	useEffect( () => {
		fetchSettings()
			.then( ( data ) => {
				setSettings( data );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setNotice( {
					status: 'error',
					message: err.message || __( 'Failed to load settings.', 'stockadaptix-pricing' ),
				} );
				setLoading( false );
			} );
	}, [] );

	const update = useCallback( ( patch ) => {
		setSettings( ( current ) => ( { ...current, ...patch } ) );
		setDirty( true );
	}, [] );

	const handleSave = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const fresh = await saveSettings( settings );
			setSettings( fresh );
			setDirty( false );
			setNotice( { status: 'success', message: __( 'Settings saved.', 'stockadaptix-pricing' ) } );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message || __( 'Failed to save settings.', 'stockadaptix-pricing' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	if ( loading ) {
		return (
			<div className="stockadaptix-loading">
				<Spinner />
				<span>{ __( 'Loading settings…', 'stockadaptix-pricing' ) }</span>
			</div>
		);
	}

	if ( ! settings ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ notice ? notice.message : __( 'Unable to load settings.', 'stockadaptix-pricing' ) }
			</Notice>
		);
	}

	return (
		<div className="stockadaptix-app">
			<header className="stockadaptix-app__header">
				<Heading level={ 1 }>{ __( 'StockAdaptix Pricing', 'stockadaptix-pricing' ) }</Heading>
				<Text variant="muted">
					{ __(
						'Dynamically adjust WooCommerce product prices based on real-time stock levels.',
						'stockadaptix-pricing'
					) }
				</Text>
			</header>

			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<Card className="stockadaptix-card">
				<CardHeader>
					<Heading level={ 3 }>{ __( 'General', 'stockadaptix-pricing' ) }</Heading>
				</CardHeader>
				<CardBody>
					<ToggleControl
						label={ __( 'Enable dynamic pricing', 'stockadaptix-pricing' ) }
						help={ __(
							'Master switch. When off, prices are never adjusted.',
							'stockadaptix-pricing'
						) }
						checked={ !! settings.enable_plugin }
						onChange={ ( v ) => update( { enable_plugin: v ? 1 : 0 } ) }
					/>
					<ToggleControl
						label={ __( 'Apply to variable product variations', 'stockadaptix-pricing' ) }
						help={ __(
							'Adjust prices on individual variations of variable products too. Otherwise, only simple products are affected.',
							'stockadaptix-pricing'
						) }
						checked={ !! settings.include_variations }
						onChange={ ( v ) => update( { include_variations: v ? 1 : 0 } ) }
					/>
				</CardBody>
			</Card>

			<Card className="stockadaptix-card">
				<CardHeader>
					<Heading level={ 3 }>{ __( 'Pricing rules', 'stockadaptix-pricing' ) }</Heading>
					<Text variant="muted">
						{ __(
							'Rules are evaluated top-to-bottom. The first matching rule wins.',
							'stockadaptix-pricing'
						) }
					</Text>
				</CardHeader>
				<CardBody>
					<RulesEditor
						rules={ settings.rules }
						onChange={ ( rules ) => update( { rules } ) }
					/>
				</CardBody>
			</Card>

			<Card className="stockadaptix-card">
				<CardHeader>
					<Heading level={ 3 }>{ __( 'Price caps & rounding', 'stockadaptix-pricing' ) }</Heading>
				</CardHeader>
				<CardBody>
					<Flex gap={ 4 } align="flex-start" wrap>
						<FlexItem>
							<TextControl
								type="number"
								label={ __( 'Price floor', 'stockadaptix-pricing' ) }
								help={ __(
									'Adjusted prices will never go below this. Use 0 to disable.',
									'stockadaptix-pricing'
								) }
								value={ settings.price_floor }
								onChange={ ( v ) => update( { price_floor: parseFloat( v ) || 0 } ) }
								min="0"
								step="0.01"
							/>
						</FlexItem>
						<FlexItem>
							<TextControl
								type="number"
								label={ __( 'Price ceiling', 'stockadaptix-pricing' ) }
								help={ __(
									'Adjusted prices will never go above this. Use 0 to disable.',
									'stockadaptix-pricing'
								) }
								value={ settings.price_ceiling }
								onChange={ ( v ) => update( { price_ceiling: parseFloat( v ) || 0 } ) }
								min="0"
								step="0.01"
							/>
						</FlexItem>
						<FlexItem>
							<SelectControl
								label={ __( 'Rounding', 'stockadaptix-pricing' ) }
								help={ __(
									'Applied after caps. Charm pricing rounds to .99 endings.',
									'stockadaptix-pricing'
								) }
								value={ settings.rounding_mode }
								onChange={ ( v ) => update( { rounding_mode: v } ) }
								options={ [
									{ value: 'none', label: __( 'None', 'stockadaptix-pricing' ) },
									{ value: 'charm_99', label: __( 'Charm pricing (.99)', 'stockadaptix-pricing' ) },
									{ value: 'nearest', label: __( 'Nearest integer', 'stockadaptix-pricing' ) },
								] }
							/>
						</FlexItem>
					</Flex>
				</CardBody>
			</Card>

			<Card className="stockadaptix-card">
				<CardHeader>
					<Heading level={ 3 }>{ __( 'Customer messaging', 'stockadaptix-pricing' ) }</Heading>
				</CardHeader>
				<CardBody>
					<ToggleControl
						label={ __( 'Show notice when a price is adjusted', 'stockadaptix-pricing' ) }
						checked={ !! settings.customer_message_enabled }
						onChange={ ( v ) => update( { customer_message_enabled: v ? 1 : 0 } ) }
					/>
					<TextControl
						label={ __( 'Message text', 'stockadaptix-pricing' ) }
						value={ settings.customer_message }
						onChange={ ( v ) => update( { customer_message: v } ) }
					/>
				</CardBody>
			</Card>

			<Card className="stockadaptix-card">
				<CardHeader>
					<Heading level={ 3 }>{ __( 'Preview', 'stockadaptix-pricing' ) }</Heading>
					<Text variant="muted">
						{ __(
							'Try out the pricing engine against a hypothetical base price and stock level. Uses your current unsaved settings.',
							'stockadaptix-pricing'
						) }
					</Text>
				</CardHeader>
				<CardBody>
					<PreviewSimulator settings={ settings } />
				</CardBody>
			</Card>

			<div className="stockadaptix-app__footer">
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ saving }
					disabled={ saving || ! dirty }
				>
					{ saving
						? __( 'Saving…', 'stockadaptix-pricing' )
						: __( 'Save changes', 'stockadaptix-pricing' ) }
				</Button>
				{ dirty && ! saving && (
					<Text variant="muted" className="stockadaptix-app__dirty">
						{ __( 'You have unsaved changes.', 'stockadaptix-pricing' ) }
					</Text>
				) }
			</div>
		</div>
	);
}
