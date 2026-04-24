import {
	Button,
	TextControl,
	SelectControl,
	Flex,
	FlexItem,
	FlexBlock,
	Icon,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const NEW_RULE = {
	comparator: 'lte',
	threshold: 10,
	direction: 'increase',
	percent: 10,
};

export default function RulesEditor( { rules, onChange } ) {
	const updateRule = ( idx, patch ) => {
		const next = rules.map( ( r, i ) => ( i === idx ? { ...r, ...patch } : r ) );
		onChange( next );
	};

	const removeRule = ( idx ) => onChange( rules.filter( ( _, i ) => i !== idx ) );

	const moveRule = ( idx, dir ) => {
		const target = idx + dir;
		if ( target < 0 || target >= rules.length ) {
			return;
		}
		const next = [ ...rules ];
		[ next[ idx ], next[ target ] ] = [ next[ target ], next[ idx ] ];
		onChange( next );
	};

	const addRule = () => onChange( [ ...rules, { ...NEW_RULE } ] );

	if ( rules.length === 0 ) {
		return (
			<div className="stockadaptix-rules-empty">
				<p>
					{ __(
						'No pricing rules configured. Prices will not be adjusted until you add at least one rule.',
						'stockadaptix-pricing'
					) }
				</p>
				<Button variant="primary" onClick={ addRule }>
					{ __( 'Add your first rule', 'stockadaptix-pricing' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="stockadaptix-rules">
			<table className="stockadaptix-rules__table">
				<thead>
					<tr>
						<th />
						<th>{ __( 'When stock is', 'stockadaptix-pricing' ) }</th>
						<th>{ __( 'Threshold', 'stockadaptix-pricing' ) }</th>
						<th>{ __( 'Action', 'stockadaptix-pricing' ) }</th>
						<th>{ __( 'Percent', 'stockadaptix-pricing' ) }</th>
						<th />
					</tr>
				</thead>
				<tbody>
					{ rules.map( ( rule, idx ) => (
						<tr key={ idx }>
							<td className="stockadaptix-rules__order">
								<Button
									size="small"
									icon={ <Icon icon="arrow-up-alt2" /> }
									label={ __( 'Move up', 'stockadaptix-pricing' ) }
									disabled={ idx === 0 }
									onClick={ () => moveRule( idx, -1 ) }
								/>
								<Button
									size="small"
									icon={ <Icon icon="arrow-down-alt2" /> }
									label={ __( 'Move down', 'stockadaptix-pricing' ) }
									disabled={ idx === rules.length - 1 }
									onClick={ () => moveRule( idx, 1 ) }
								/>
							</td>
							<td>
								<SelectControl
									hideLabelFromVision
									label={ __( 'Comparator', 'stockadaptix-pricing' ) }
									value={ rule.comparator }
									onChange={ ( v ) => updateRule( idx, { comparator: v } ) }
									options={ [
										{ value: 'lte', label: __( '≤ at most', 'stockadaptix-pricing' ) },
										{ value: 'gte', label: __( '≥ at least', 'stockadaptix-pricing' ) },
									] }
								/>
							</td>
							<td>
								<TextControl
									hideLabelFromVision
									label={ __( 'Threshold', 'stockadaptix-pricing' ) }
									type="number"
									min="0"
									value={ rule.threshold }
									onChange={ ( v ) =>
										updateRule( idx, { threshold: parseInt( v, 10 ) || 0 } )
									}
								/>
							</td>
							<td>
								<SelectControl
									hideLabelFromVision
									label={ __( 'Direction', 'stockadaptix-pricing' ) }
									value={ rule.direction }
									onChange={ ( v ) => updateRule( idx, { direction: v } ) }
									options={ [
										{ value: 'increase', label: __( 'Increase price', 'stockadaptix-pricing' ) },
										{ value: 'decrease', label: __( 'Decrease price', 'stockadaptix-pricing' ) },
									] }
								/>
							</td>
							<td>
								<Flex align="center" gap={ 1 }>
									<FlexBlock>
										<TextControl
											hideLabelFromVision
											label={ __( 'Percent', 'stockadaptix-pricing' ) }
											type="number"
											min="0"
											step="0.1"
											value={ rule.percent }
											onChange={ ( v ) =>
												updateRule( idx, { percent: parseFloat( v ) || 0 } )
											}
										/>
									</FlexBlock>
									<FlexItem>%</FlexItem>
								</Flex>
							</td>
							<td>
								<Button
									isDestructive
									size="small"
									onClick={ () => removeRule( idx ) }
								>
									{ __( 'Remove', 'stockadaptix-pricing' ) }
								</Button>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<div className="stockadaptix-rules__actions">
				<Button variant="secondary" onClick={ addRule }>
					{ __( '+ Add rule', 'stockadaptix-pricing' ) }
				</Button>
			</div>
		</div>
	);
}
