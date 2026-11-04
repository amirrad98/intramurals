/**
 * LeagueFlow admin enhancements. No dependencies; every control works without it.
 *
 * Long user/team selects get a filter box: typing hides options that do not
 * match (selected options always stay visible) and announces how many match.
 * "Select all listed" boxes tick every record checkbox in their form.
 */
( function () {
	'use strict';

	function enhance( select ) {
		if ( select.getAttribute( 'data-leagueflow-search-ready' ) ) {
			return;
		}

		select.setAttribute( 'data-leagueflow-search-ready', '1' );

		var wrap = document.createElement( 'span' );
		var input = document.createElement( 'input' );
		var status = document.createElement( 'span' );
		var countText = select.getAttribute( 'data-leagueflow-search-count' ) || '%d matching options';

		wrap.className = 'leagueflow-select-search';
		input.type = 'search';
		input.className = 'leagueflow-select-search__input';
		input.setAttribute( 'aria-label', select.getAttribute( 'data-leagueflow-search-label' ) || 'Filter options' );
		input.setAttribute( 'placeholder', select.getAttribute( 'data-leagueflow-search-placeholder' ) || '' );
		input.setAttribute( 'autocomplete', 'off' );

		if ( select.id ) {
			input.id = select.id + '-search';
			input.setAttribute( 'aria-controls', select.id );
		}

		status.className = 'screen-reader-text';
		status.setAttribute( 'aria-live', 'polite' );

		wrap.appendChild( input );
		wrap.appendChild( status );
		select.parentNode.insertBefore( wrap, select );

		function filter() {
			var query = input.value.trim().toLowerCase();
			var shown = 0;

			Array.prototype.forEach.call( select.options, function ( option ) {
				var keep = '' === option.value || option.selected || '' === query || -1 !== option.text.toLowerCase().indexOf( query );

				option.hidden = ! keep;

				if ( keep && '' !== option.value ) {
					shown++;
				}
			} );

			status.textContent = '' === query ? '' : countText.replace( '%d', String( shown ) );
		}

		input.addEventListener( 'input', filter );
		input.addEventListener( 'keydown', function ( event ) {
			// Enter filters; it must not submit the surrounding post form.
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				filter();
			}
		} );
		select.addEventListener( 'change', filter );
	}

	// "Select all listed" checkboxes tick or clear the named checkboxes in the same form.
	function selectAll( box ) {
		var form = box.form;
		var name = box.getAttribute( 'data-leagueflow-select-all' );
		if ( ! form ) {
			return;
		}
		function records() {
			return Array.prototype.filter.call( form.querySelectorAll( 'input[type="checkbox"]' ), function ( input ) {
				return input.name === name && ! input.disabled;
			} );
		}
		function sync() {
			var inputs = records();
			var checked = inputs.filter( function ( input ) { return input.checked; } ).length;
			box.checked = inputs.length > 0 && checked === inputs.length;
			box.indeterminate = checked > 0 && checked < inputs.length;
			box.disabled = inputs.length === 0;
		}
		box.addEventListener( 'change', function () {
			records().forEach( function ( input ) {
				input.checked = box.checked;
			} );
			sync();
		} );
		form.addEventListener( 'change', function ( event ) {
			if ( event.target.name === name ) { sync(); }
		} );
		sync();
	}

	// Competition and season options belong to the selected sport. A native
	// Filter submit must not carry the previous sport's selections forward.
	function listSportFilter( select ) {
		select.addEventListener( 'change', function () {
			if ( ! select.form ) { return; }
			[ 'lf_competition_filter', 'lf_season_filter', 'lf_batch' ].forEach( function ( name ) {
				var field = select.form.elements.namedItem( name );
				if ( field ) { field.value = ''; }
			} );
		} );
	}

	function resultPreview( preview ) {
		var home = document.getElementById( 'lf_home_score' );
		var away = document.getElementById( 'lf_away_score' );
		var status = document.getElementById( 'lf_status' );
		var outcome = document.getElementById( 'lf_outcome' );
		var keep = document.getElementById( 'lf_keep_status' );
		if ( ! home || ! away || ! status || ! outcome ) { return; }
		var initial = preview.textContent;
		function valid( field ) {
			var value = field.value.trim();
			if ( ! /^[0-9]+$/.test( value ) || field.validity && field.validity.badInput ) { return false; }
			var digits = value.replace( /^0+/, '' ) || '0';
			var max = preview.getAttribute( 'data-score-max' );
			return ! max || digits.length < max.length || digits.length === max.length && digits <= max;
		}
		function text( attribute, fallback ) {
			return preview.getAttribute( attribute ) || fallback || initial;
		}
		function update() {
			var invalid = [ home, away ].some( function ( field ) {
				return ( '' !== field.value.trim() || field.validity && field.validity.badInput ) && ! valid( field );
			} );
			var forfeited = [ 'forfeit_home', 'forfeit_away', 'double_forfeit' ].indexOf( outcome.value ) !== -1;
			var complete = forfeited || valid( home ) && valid( away );
			var automatic = [ '', 'scheduled' ].indexOf( status.value ) !== -1 && ! ( keep && keep.checked );
			if ( invalid ) {
				preview.textContent = text( 'data-invalid-text' );
			} else if ( complete && ( automatic || status.value === 'finished' ) ) {
				preview.textContent = text( 'data-final-text' );
			} else if ( status.value === 'finished' && ! complete ) {
				preview.textContent = text( 'data-incomplete-text' );
			} else if ( status.value === 'scheduled' && automatic && ! complete ) {
				preview.textContent = initial;
			} else {
				var selected = status.options[ status.selectedIndex ];
				preview.textContent = text( 'data-status-text' ).replace( '%s', selected ? selected.textContent : status.value );
			}
		}
		[ home, away, status, outcome, keep ].filter( Boolean ).forEach( function ( field ) {
			field.addEventListener( 'input', update );
			field.addEventListener( 'change', update );
		} );
		update();
	}

	// Calendar Event poster: pick or clear an image with the media library.
	function posterField( field ) {
		var id = field.querySelector( '[data-leagueflow-poster-id]' );
		var preview = field.querySelector( '[data-leagueflow-poster-preview]' );
		var choose = field.querySelector( '[data-leagueflow-poster-choose]' );
		var remove = field.querySelector( '[data-leagueflow-poster-remove]' );
		var frame = null;
		if ( ! id || ! choose || ! window.wp || ! window.wp.media ) {
			if ( choose ) { choose.disabled = true; }
			return;
		}
		function set( attachment ) {
			var sizes = attachment && attachment.sizes ? attachment.sizes : {};
			var image = sizes.medium || sizes.thumbnail || sizes.full || attachment;
			id.value = attachment ? String( attachment.id ) : '0';
			preview.hidden = ! attachment;
			preview.src = attachment && image ? image.url : '';
			remove.hidden = ! attachment;
			choose.textContent = field.getAttribute( attachment ? 'data-change' : 'data-upload' );
		}
		choose.addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = window.wp.media( { title: field.getAttribute( 'data-title' ), button: { text: field.getAttribute( 'data-button' ) }, library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					set( frame.state().get( 'selection' ).first().toJSON() );
					choose.focus();
				} );
			}
			frame.open();
		} );
		remove.addEventListener( 'click', function () {
			set( null );
			choose.focus();
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( 'select[data-leagueflow-searchable]' ), enhance );
		Array.prototype.forEach.call( document.querySelectorAll( 'input[data-leagueflow-select-all]' ), selectAll );
		Array.prototype.forEach.call( document.querySelectorAll( 'select[id^="leagueflow-filter-by-sport-"]' ), listSportFilter );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-leagueflow-result-preview]' ), resultPreview );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-leagueflow-poster]' ), posterField );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
