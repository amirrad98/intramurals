( function () {
	'use strict';

	function syncTeamSelect( form ) {
		if ( ! form ) {
			return;
		}

		var sportSelect = form.querySelector( '[data-leagueflow-sport-select]' );
		var teamSelect = form.querySelector( '[data-leagueflow-team-select]' );
		var help = form.querySelector( '[data-leagueflow-team-help]' );

		if ( ! sportSelect || ! teamSelect ) {
			return;
		}

		var selectedSport = sportSelect.value;
		var availableTeams = 0;
		var hasPlacementOption = false;
		var selectedOption = teamSelect.options[ teamSelect.selectedIndex ];

		Array.prototype.forEach.call( teamSelect.options, function ( option ) {
			if ( '' === option.value ) {
				option.textContent = selectedSport ? 'Choose a team or placement' : 'Choose a sport first';
				option.hidden = false;
				option.disabled = false;
				return;
			}

			if ( option.hasAttribute( 'data-placement-option' ) ) {
				option.hidden = ! selectedSport;
				option.disabled = ! selectedSport;
				hasPlacementOption = !! selectedSport;
				return;
			}

			var matchesSport = selectedSport && option.getAttribute( 'data-sport' ) === selectedSport;
			option.hidden = ! matchesSport;
			option.disabled = ! matchesSport;

			if ( matchesSport ) {
				availableTeams += 1;
			}
		} );

		if ( ! selectedSport || ! selectedOption || selectedOption.disabled ) {
			teamSelect.value = '';
		}

		teamSelect.disabled = ! selectedSport || ( 0 === availableTeams && ! hasPlacementOption );
		teamSelect.setAttribute( 'aria-disabled', teamSelect.disabled ? 'true' : 'false' );

		if ( help ) {
			if ( ! selectedSport ) {
				help.textContent = help.getAttribute( 'data-default-text' ) || help.textContent;
			} else if ( availableTeams ) {
				help.textContent = availableTeams + ' ' + ( 1 === availableTeams ? 'team' : 'teams' ) + ' available for the selected sport. Pick a team or choose the placement option.';
			} else {
				help.textContent = 'No teams are listed for the selected sport yet. Choose the placement option to ask intramurals to place you.';
			}
		}
	}

	function initForm( form ) {
		var help = form.querySelector( '[data-leagueflow-team-help]' );

		if ( help && ! help.getAttribute( 'data-default-text' ) ) {
			help.setAttribute( 'data-default-text', help.textContent );
		}

		syncTeamSelect( form );
	}

	function syncPlayerSportPreferenceRow( row ) {
		if ( ! row ) {
			return;
		}

		var toggle = row.querySelector( '[data-leagueflow-player-sport-toggle]' );
		var select = row.querySelector( '[data-leagueflow-player-level-select]' );

		if ( ! toggle || ! select ) {
			return;
		}

		select.disabled = ! toggle.checked;
		select.setAttribute( 'aria-disabled', select.disabled ? 'true' : 'false' );
		row.classList.toggle( 'is-selected', toggle.checked );
	}

	function initPlayerSportPreferences( fieldset ) {
		Array.prototype.forEach.call(
			fieldset.querySelectorAll( '.leagueflow-portal__sport-preference-row' ),
			syncPlayerSportPreferenceRow
		);
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target && event.target.matches( '[data-leagueflow-sport-select]' ) ) {
			syncTeamSelect( event.target.closest( 'form' ) );
		}

		if ( event.target && event.target.matches( '[data-leagueflow-player-sport-toggle]' ) ) {
			syncPlayerSportPreferenceRow( event.target.closest( '.leagueflow-portal__sport-preference-row' ) );
		}
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.leagueflow-portal__form--join-request' ),
			initForm
		);

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-leagueflow-player-sport-preferences]' ),
			initPlayerSportPreferences
		);
	} );
}() );
