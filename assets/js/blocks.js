( function( wp ) {
	if ( ! wp || ! wp.blocks ) {
		return;
	}

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var RangeControl = wp.components.RangeControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;
	var data = window.LeagueFlowBlocksData || {};

	function withBlankOption( items, label ) {
		var options = [ { label: label, value: '' } ];
		return options.concat( items || [] );
	}

	function filterBySport( items, sport ) {
		if ( ! sport ) {
			return items || [];
		}

		return ( items || [] ).filter( function( item ) {
			return ! item.sport || item.sport === sport;
		} );
	}

	function renderContextControls( props, settings ) {
		var sport = props.attributes.sport || '';
		var controls = [
			el( SelectControl, {
				label: __( 'Sport', 'leagueflow' ),
				value: sport,
				options: withBlankOption( data.sports, __( 'Select a sport', 'leagueflow' ) ),
				onChange: function( value ) {
					props.setAttributes( {
						sport: value,
						competition: '',
						season: ''
					} );
				}
			} ),
			el( SelectControl, {
				label: __( 'Competition', 'leagueflow' ),
				value: props.attributes.competition || '',
				options: withBlankOption( filterBySport( data.competitions, sport ), __( 'All competitions', 'leagueflow' ) ),
				onChange: function( value ) {
					props.setAttributes( { competition: value } );
				}
			} ),
			el( SelectControl, {
				label: __( 'Season', 'leagueflow' ),
				value: props.attributes.season || '',
				options: withBlankOption( filterBySport( data.seasons, sport ), __( 'All seasons', 'leagueflow' ) ),
				onChange: function( value ) {
					props.setAttributes( { season: value } );
				}
			} ),
			el( SelectControl, {
				label: __( 'League level', 'leagueflow' ),
				value: props.attributes.league_level || '',
				options: withBlankOption( data.leagueLevels, __( 'All levels', 'leagueflow' ) ),
				onChange: function( value ) {
					props.setAttributes( { league_level: value } );
				}
			} )
		];

		if ( settings && settings.showLogos ) {
			controls.push(
				el( ToggleControl, {
					label: __( 'Show logos', 'leagueflow' ),
					checked: !! props.attributes.show_logos,
					onChange: function( value ) {
						props.setAttributes( { show_logos: value } );
					}
				} )
			);
		}

		return controls;
	}

	function renderPanel( title, children ) {
		return el(
			InspectorControls,
			{},
			el(
				PanelBody,
				{
					title: title,
					initialOpen: true
				},
				children
			)
		);
	}

	function renderPreview( blockName, attributes ) {
		return el(
			Fragment,
			{},
			el( ServerSideRender, { block: blockName, attributes: attributes } ),
			el( 'p', { className: 'leagueflow-editor-note' }, __( 'Rendered server-side to match the frontend output.', 'leagueflow' ) )
		);
	}

	registerBlockType( 'leagueflow/league-table', {
		edit: function( props ) {
			return el(
				Fragment,
				{},
				renderPanel( __( 'League Table', 'leagueflow' ), renderContextControls( props, { showLogos: true } ) ),
				renderPreview( 'leagueflow/league-table', props.attributes )
			);
		},
		save: function() {
			return null;
		}
	} );

	registerBlockType( 'leagueflow/sport-standings', {
		edit: function( props ) {
			return el(
				Fragment,
				{},
				renderPanel(
					__( 'Sport Standings', 'leagueflow' ),
					[
						el( SelectControl, {
							label: __( 'Competition', 'leagueflow' ),
							value: props.attributes.competition || '',
							options: withBlankOption( data.competitions, __( 'All competitions', 'leagueflow' ) ),
							onChange: function( value ) {
								props.setAttributes( { competition: value } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Season', 'leagueflow' ),
							value: props.attributes.season || '',
							options: withBlankOption( data.seasons, __( 'All seasons', 'leagueflow' ) ),
							onChange: function( value ) {
								props.setAttributes( { season: value } );
							}
						} ),
						el( SelectControl, {
							label: __( 'League level', 'leagueflow' ),
							value: props.attributes.league_level || '',
							options: withBlankOption( data.leagueLevels, __( 'All levels', 'leagueflow' ) ),
							onChange: function( value ) {
								props.setAttributes( { league_level: value } );
							}
						} ),
						el( TextControl, {
							label: __( 'Sports to include', 'leagueflow' ),
							help: __( 'Optional comma-separated sport slugs, for example: soccer,volleyball.', 'leagueflow' ),
							value: props.attributes.sports || '',
							onChange: function( value ) {
								props.setAttributes( { sports: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show logos', 'leagueflow' ),
							checked: !! props.attributes.show_logos,
							onChange: function( value ) {
								props.setAttributes( { show_logos: value } );
							}
						} )
					]
				),
				renderPreview( 'leagueflow/sport-standings', props.attributes )
			);
		},
		save: function() {
			return null;
		}
	} );

	registerBlockType( 'leagueflow/team-list', {
		edit: function( props ) {
			return el(
				Fragment,
				{},
				renderPanel( __( 'Team List', 'leagueflow' ), renderContextControls( props, { showLogos: true } ) ),
				renderPreview( 'leagueflow/team-list', props.attributes )
			);
		},
		save: function() {
			return null;
		}
	} );

	registerBlockType( 'leagueflow/team-roster', {
		edit: function( props ) {
			return el(
				Fragment,
				{},
				renderPanel(
					__( 'Team Roster', 'leagueflow' ),
					[
						el( SelectControl, {
							label: __( 'Team', 'leagueflow' ),
							value: props.attributes.team || '',
							options: withBlankOption( data.teams, __( 'Select a team', 'leagueflow' ) ),
							onChange: function( value ) {
								props.setAttributes( { team: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show player photos', 'leagueflow' ),
							checked: !! props.attributes.show_photos,
							onChange: function( value ) {
								props.setAttributes( { show_photos: value } );
							}
						} )
					]
				),
				renderPreview( 'leagueflow/team-roster', props.attributes )
			);
		},
		save: function() {
			return null;
		}
	} );

	registerBlockType( 'leagueflow/match-list', {
		edit: function( props ) {
			return el(
				Fragment,
				{},
				renderPanel(
					__( 'Match List', 'leagueflow' ),
					renderContextControls( props ).concat( [
						el( SelectControl, {
							label: __( 'Status', 'leagueflow' ),
							value: props.attributes.status || '',
							options: [
								{ label: __( 'All statuses', 'leagueflow' ), value: '' },
								{ label: __( 'Scheduled', 'leagueflow' ), value: 'scheduled' },
								{ label: __( 'Live', 'leagueflow' ), value: 'live' },
								{ label: __( 'Finished', 'leagueflow' ), value: 'finished' },
								{ label: __( 'Postponed', 'leagueflow' ), value: 'postponed' },
								{ label: __( 'Cancelled', 'leagueflow' ), value: 'cancelled' }
							],
							onChange: function( value ) {
								props.setAttributes( { status: value } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Limit', 'leagueflow' ),
							value: props.attributes.limit || 10,
							min: 1,
							max: 50,
							onChange: function( value ) {
								props.setAttributes( { limit: value } );
							}
						} )
					] )
				),
				renderPreview( 'leagueflow/match-list', props.attributes )
			);
		},
		save: function() {
			return null;
		}
	} );

	registerBlockType( 'leagueflow/match-calendar', {
		edit: function( props ) {
			return el(
				Fragment,
				{},
				renderPanel(
					__( 'Sports Calendar', 'leagueflow' ),
					renderContextControls( props ).concat( [
						el( SelectControl, {
							label: __( 'Default view', 'leagueflow' ),
							value: props.attributes.view || 'month',
							options: [
								{ label: __( 'Month', 'leagueflow' ), value: 'month' },
								{ label: __( 'List', 'leagueflow' ), value: 'list' },
								{ label: __( 'Week', 'leagueflow' ), value: 'week' },
								{ label: __( 'Day', 'leagueflow' ), value: 'day' }
							],
							onChange: function( value ) {
								props.setAttributes( { view: value } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Team schedule', 'leagueflow' ),
							value: props.attributes.team || '',
							options: withBlankOption( filterBySport( data.teams, props.attributes.sport || '' ), __( 'All teams', 'leagueflow' ) ),
							onChange: function( value ) {
								props.setAttributes( { team: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Include standalone events', 'leagueflow' ),
							help: __( 'Show drop-ins and other calendar events alongside matches.', 'leagueflow' ),
							checked: false !== props.attributes.include_events,
							onChange: function( value ) {
								props.setAttributes( { include_events: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show week view', 'leagueflow' ),
							checked: false !== props.attributes.show_week,
							onChange: function( value ) {
								props.setAttributes( { show_week: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show day view', 'leagueflow' ),
							checked: false !== props.attributes.show_day,
							onChange: function( value ) {
								props.setAttributes( { show_day: value } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Initial list items', 'leagueflow' ),
							value: props.attributes.list_initial || 30,
							min: 5,
							max: 100,
							step: 5,
							onChange: function( value ) {
								props.setAttributes( { list_initial: value } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Load more count', 'leagueflow' ),
							value: props.attributes.list_more || 15,
							min: 5,
							max: 50,
							step: 5,
							onChange: function( value ) {
								props.setAttributes( { list_more: value } );
							}
						} )
					] )
				),
				renderPreview( 'leagueflow/match-calendar', props.attributes )
			);
		},
		save: function() {
			return null;
		}
	} );

	registerBlockType( 'leagueflow/match-card', {
		edit: function( props ) {
			return el(
				Fragment,
				{},
				renderPanel(
					__( 'Match Card', 'leagueflow' ),
					[
						el( SelectControl, {
							label: __( 'Match', 'leagueflow' ),
							value: props.attributes.match || '',
							options: withBlankOption( data.matches, __( 'Select a match', 'leagueflow' ) ),
							onChange: function( value ) {
								props.setAttributes( { match: value } );
							}
						} )
					]
				),
				renderPreview( 'leagueflow/match-card', props.attributes )
			);
		},
		save: function() {
			return null;
		}
	} );

	registerBlockType( 'leagueflow/knockout-bracket', {
		edit: function( props ) {
			return el(
				Fragment,
				{},
				renderPanel( __( 'Knockout Bracket', 'leagueflow' ), renderContextControls( props ) ),
				renderPreview( 'leagueflow/knockout-bracket', props.attributes )
			);
		},
		save: function() {
			return null;
		}
	} );

	registerBlockType( 'leagueflow/portal', {
		edit: function( props ) {
			return el(
				Fragment,
				{},
				renderPreview( 'leagueflow/portal', props.attributes )
			);
		},
		save: function() {
			return null;
		}
	} );
} )( window.wp );
