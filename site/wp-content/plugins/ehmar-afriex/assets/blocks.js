( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'ehmar_afriex_data', {} );
	const title = decodeEntities( settings.title || 'Pay with Afriex' );
	const Content = () => createElement( 'p', null, decodeEntities( settings.description || '' ) );

	registerPaymentMethod( {
		name: 'ehmar_afriex',
		label: createElement( 'span', null, title ),
		ariaLabel: title,
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
