/**
 * Editor script for the "Announcer for SportsPress" block (spa/announcement).
 *
 * No build step: written against the global wp.* UMD packages with
 * wp.element.createElement (no JSX), matching this plugin's plain-JS convention.
 * Expects a localized `SPA_BLOCK` global: { leagues: [ { value, label } ] }.
 *
 * @package SportsPress_Announcer
 */
( function ( blocks, blockEditor, components, element, i18n, serverSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;
	var cfg = window.SPA_BLOCK || { leagues: [] };
	var ServerSideRender = serverSideRender; // wp.serverSideRender is the default export.

	function leagueOptions() {
		var opts = [ { value: 0, label: __( 'Select a league…', 'announcer-for-sportspress' ) } ];
		( cfg.leagues || [] ).forEach( function ( league ) {
			opts.push( { value: league.value, label: league.label } );
		} );
		return opts;
	}

	function inspector( attributes, setAttributes ) {
		return el(
			blockEditor.InspectorControls,
			null,
			el(
				components.PanelBody,
				{ title: __( 'Recap settings', 'announcer-for-sportspress' ), initialOpen: true },
				el( components.SelectControl, {
					label: __( 'League', 'announcer-for-sportspress' ),
					value: attributes.leagueId,
					options: leagueOptions(),
					onChange: function ( value ) {
						setAttributes( { leagueId: parseInt( value, 10 ) || 0 } );
					},
				} ),
				el( components.RangeControl, {
					label: __( 'Days of history', 'announcer-for-sportspress' ),
					value: attributes.days,
					min: 1,
					max: 90,
					onChange: function ( value ) {
						setAttributes( { days: value || 7 } );
					},
				} )
			)
		);
	}

	function preview( attributes ) {
		if ( ! attributes.leagueId ) {
			return el(
				components.Placeholder,
				{
					icon: 'megaphone',
					label: __( 'Announcer for SportsPress', 'announcer-for-sportspress' ),
					instructions: __( 'Choose a league in the block settings to show its recap.', 'announcer-for-sportspress' ),
				}
			);
		}
		return el( ServerSideRender, {
			block: 'spa/announcement',
			attributes: attributes,
		} );
	}

	blocks.registerBlockType( 'spa/announcement', {
		apiVersion: 2,
		title: __( 'Announcer for SportsPress', 'announcer-for-sportspress' ),
		description: __( 'Embed a league recap — results, standings, leaders and upcoming games.', 'announcer-for-sportspress' ),
		icon: 'megaphone',
		category: 'widgets',
		attributes: {
			leagueId: { type: 'integer', default: 0 },
			days: { type: 'integer', default: 7 },
		},
		edit: function ( props ) {
			return el(
				'div',
				blockEditor.useBlockProps ? blockEditor.useBlockProps() : {},
				inspector( props.attributes, props.setAttributes ),
				preview( props.attributes )
			);
		},
		// Dynamic block: rendered server-side via render_callback.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n,
	window.wp.serverSideRender
);
