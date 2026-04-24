import { useState } from '@wordpress/element';
import {
	Button,
	TextControl,
	Flex,
	FlexItem,
	FlexBlock,
	Notice,
	__experimentalText as Text,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { previewPrice } from '../api';

export default function PreviewSimulator( { settings } ) {
	const [ basePrice, setBasePrice ] = useState( 100 );
	const [ stock, setStock ] = useState( 5 );
	const [ result, setResult ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	const run = async () => {
		setLoading( true );
		setError( null );
		try {
			const res = await previewPrice( basePrice, stock, settings );
			setResult( res );
		} catch ( err ) {
			setError( err.message || __( 'Preview failed.', 'stockadaptix-pricing' ) );
		} finally {
			setLoading( false );
		}
	};

	return (
		<div className="stockadaptix-preview">
			<Flex gap={ 4 } align="flex-end" wrap>
				<FlexBlock>
					<TextControl
						type="number"
						label={ __( 'Base price', 'stockadaptix-pricing' ) }
						value={ basePrice }
						onChange={ ( v ) => setBasePrice( parseFloat( v ) || 0 ) }
						min="0"
						step="0.01"
					/>
				</FlexBlock>
				<FlexBlock>
					<TextControl
						type="number"
						label={ __( 'Stock quantity', 'stockadaptix-pricing' ) }
						value={ stock }
						onChange={ ( v ) => setStock( parseInt( v, 10 ) || 0 ) }
						min="0"
					/>
				</FlexBlock>
				<FlexItem>
					<Button variant="secondary" onClick={ run } isBusy={ loading }>
						{ __( 'Run preview', 'stockadaptix-pricing' ) }
					</Button>
				</FlexItem>
			</Flex>

			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ result && ! error && (
				<div className="stockadaptix-preview__result">
					<Text size="title">
						{ /* translators: %s: formatted price */
							sprintf(
								__( 'Adjusted price: %s', 'stockadaptix-pricing' ),
								result.adjusted_price.toFixed( 2 )
							)
						}
					</Text>
					<Text variant="muted">
						{
							/* translators: 1: signed delta, 2: signed percent */
							sprintf(
								__( 'Δ %1$s (%2$s%%) from base', 'stockadaptix-pricing' ),
								result.delta >= 0 ? '+' + result.delta.toFixed( 2 ) : result.delta.toFixed( 2 ),
								result.delta_percent >= 0
									? '+' + result.delta_percent.toFixed( 1 )
									: result.delta_percent.toFixed( 1 )
							)
						}
					</Text>
				</div>
			) }
		</div>
	);
}
