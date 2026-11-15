( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var SelectControl = components.SelectControl;
	var MediaUpload = blockEditor.MediaUpload;
	var MediaUploadCheck = blockEditor.MediaUploadCheck;
	var useBlockProps = blockEditor.useBlockProps;

	blocks.registerBlockType( 'metamanager/person', {
		title: __( 'Person Card', 'metamanager' ),
		description: __( 'Staff member card with schema markup.', 'metamanager' ),
		icon: 'id',
		category: 'widgets',
		attributes: {
			name:         { type: 'string', default: '' },
			jobTitle:     { type: 'string', default: '' },
			description:  { type: 'string', default: '' },
			imageId:      { type: 'number', default: 0 },
			imageUrl:     { type: 'string', default: '' },
			imagePosition:{ type: 'string', default: 'top' },
			schemaType:   { type: 'string', default: 'Person' },
			sameAs:       { type: 'string', default: '' },
			phone:        { type: 'string', default: '' },
			email:        { type: 'string', default: '' }
		},

		edit: function ( props ) {
			var attrs = props.attributes;
			var setProps = props.setAttributes;
			var blockProps = useBlockProps();

			return el( 'div', blockProps,
				el( 'h3', {}, __( 'Person Card', 'metamanager' ) ),
				el( TextControl, {
					label: __( 'Name', 'metamanager' ),
					value: attrs.name,
					onChange: function ( v ) { setProps( { name: v } ); }
				} ),
				el( TextControl, {
					label: __( 'Job Title', 'metamanager' ),
					value: attrs.jobTitle,
					onChange: function ( v ) { setProps( { jobTitle: v } ); }
				} ),
				el( TextareaControl, {
					label: __( 'Bio / Description', 'metamanager' ),
					value: attrs.description,
					onChange: function ( v ) { setProps( { description: v } ); }
				} ),
				el( MediaUploadCheck, {},
					el( MediaUpload, {
						onSelect: function ( media ) {
							setProps( { imageId: media.id, imageUrl: media.url } );
						},
						allowedTypes: [ 'image' ],
						value: attrs.imageId,
						render: function ( obj ) {
							return el( components.Button, {
								onClick: obj.open,
								variant: 'secondary'
							}, attrs.imageUrl ? __( 'Change Image', 'metamanager' ) : __( 'Select Image', 'metamanager' ) );
						}
					} )
				),
				attrs.imageUrl ? el( 'img', { src: attrs.imageUrl, style: { maxWidth: '150px', display: 'block', marginTop: '8px' } } ) : null,
				el( SelectControl, {
					label: __( 'Image Position', 'metamanager' ),
					value: attrs.imagePosition,
					options: [
						{ value: 'top', label: __( 'Top (stacked)', 'metamanager' ) },
						{ value: 'left', label: __( 'Left (image + text)', 'metamanager' ) },
						{ value: 'right', label: __( 'Right (text + image)', 'metamanager' ) }
					],
					onChange: function ( v ) { setProps( { imagePosition: v } ); }
				} ),
				el( SelectControl, {
					label: __( 'Schema Type', 'metamanager' ),
					value: attrs.schemaType,
					options: [
						{ value: 'Person', label: 'Person' },
						{ value: 'Physician', label: 'Physician' },
						{ value: 'Dentist', label: 'Dentist' },
						{ value: 'Attorney', label: 'Attorney' },
						{ value: 'Accountant', label: 'Accountant' }
					],
					onChange: function ( v ) { setProps( { schemaType: v } ); }
				} ),
				el( TextControl, {
					label: __( 'Phone', 'metamanager' ),
					value: attrs.phone,
					onChange: function ( v ) { setProps( { phone: v } ); }
				} ),
				el( TextControl, {
					label: __( 'Email', 'metamanager' ),
					value: attrs.email,
					onChange: function ( v ) { setProps( { email: v } ); }
				} ),
				el( TextareaControl, {
					label: __( 'Social URLs (one per line)', 'metamanager' ),
					value: attrs.sameAs,
					onChange: function ( v ) { setProps( { sameAs: v } ); }
				} )
			);
		},

		save: function () {
			return null; // Server-side rendering.
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
