( function () {
	'use strict';

	function selectedOptionText( select ) {
		if ( ! select || select.selectedIndex < 0 ) {
			return '';
		}

		return select.options[ select.selectedIndex ].textContent.trim();
	}

	function syncTeamSelect( form ) {
		if ( ! form ) {
			return;
		}

		var sportSelect = form.querySelector( '[data-leagueflow-sport-select]' );
		var levelSelect = form.querySelector( '[data-leagueflow-level-select]' );
		var teamSelect = form.querySelector( '[data-leagueflow-team-select]' );
		var help = form.querySelector( '[data-leagueflow-team-help]' );

		if ( ! sportSelect || ! levelSelect || ! teamSelect ) {
			return;
		}

		var selectedSport = sportSelect.value;
		levelSelect.disabled = ! selectedSport;
		levelSelect.setAttribute( 'aria-disabled', levelSelect.disabled ? 'true' : 'false' );

		var selectedLevel = selectedSport ? levelSelect.value : '';
		var availableTeams = 0;
		var selectedOption = teamSelect.options[ teamSelect.selectedIndex ];

		Array.prototype.forEach.call( teamSelect.options, function ( option ) {
			if ( '' === option.value ) {
				option.textContent = selectedSport && selectedLevel ? 'Choose a team or placement' : 'Choose a sport and level first';
				option.hidden = false;
				option.disabled = false;
				return;
			}

			if ( option.hasAttribute( 'data-placement-option' ) ) {
				option.hidden = ! selectedSport || ! selectedLevel;
				option.disabled = ! selectedSport || ! selectedLevel;
				return;
			}

			var matches = selectedSport && selectedLevel &&
				option.getAttribute( 'data-sport' ) === selectedSport &&
				option.getAttribute( 'data-level-id' ) === selectedLevel;
			option.hidden = ! matches;
			option.disabled = ! matches;

			if ( matches ) {
				availableTeams += 1;
			}
		} );

		if ( ! selectedOption || selectedOption.disabled ) {
			teamSelect.value = '';
		}

		teamSelect.disabled = ! selectedSport || ! selectedLevel;
		teamSelect.setAttribute( 'aria-disabled', teamSelect.disabled ? 'true' : 'false' );

		if ( help ) {
			if ( ! selectedSport || ! selectedLevel ) {
				help.textContent = help.getAttribute( 'data-default-text' ) || help.textContent;
			} else if ( availableTeams ) {
				help.textContent = availableTeams + ' ' + ( 1 === availableTeams ? 'team' : 'teams' ) + ' available at this sport and level.';
			} else {
				help.textContent = 'No matching teams are listed. Choose staff placement to continue.';
			}
		}
	}

	function initJoinRequestForm( form ) {
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

	function filterOnboardingTeams( row ) {
		var levelSelect = row.querySelector( '[data-leagueflow-player-level-select]' );
		var teamSelect = row.querySelector( '[data-leagueflow-onboarding-team-select]' );

		if ( ! levelSelect || ! teamSelect || levelSelect.disabled ) {
			return;
		}

		var selectedLevel = levelSelect.value;
		var availableTeams = 0;
		var selectedOption = teamSelect.options[ teamSelect.selectedIndex ];

		Array.prototype.forEach.call( teamSelect.options, function ( option ) {
			if ( '' === option.value ) {
				option.hidden = false;
				option.disabled = false;
				return;
			}

			if ( option.hasAttribute( 'data-placement-option' ) ) {
				option.hidden = ! selectedLevel;
				option.disabled = ! selectedLevel;
				return;
			}

			var matches = option.getAttribute( 'data-level-id' ) === selectedLevel;
			option.hidden = ! matches;
			option.disabled = ! matches;

			if ( matches ) {
				availableTeams += 1;
			}
		} );

		if ( ! selectedOption || selectedOption.disabled ) {
			teamSelect.value = '';
		}

		teamSelect.disabled = ! selectedLevel;
		teamSelect.setAttribute( 'aria-disabled', teamSelect.disabled ? 'true' : 'false' );
		teamSelect.options[ 0 ].textContent = ! selectedLevel ? 'Choose a level first' : ( availableTeams ? 'Choose a team' : 'Choose staff placement' );
	}

	function syncOnboardingSportRow( row ) {
		if ( ! row || row.classList.contains( 'is-locked' ) ) {
			return;
		}

		var toggle = row.querySelector( '[data-leagueflow-player-sport-toggle]' );
		var controls = row.querySelectorAll( 'select, textarea' );
		var levelSelect = row.querySelector( '[data-leagueflow-player-level-select]' );
		var teamSelect = row.querySelector( '[data-leagueflow-onboarding-team-select]' );

		if ( ! toggle ) {
			return;
		}

		Array.prototype.forEach.call( controls, function ( control ) {
			control.disabled = ! toggle.checked;
			control.setAttribute( 'aria-disabled', control.disabled ? 'true' : 'false' );
		} );

		if ( levelSelect ) {
			levelSelect.required = toggle.checked;
		}

		if ( teamSelect ) {
			teamSelect.required = toggle.checked;
		}

		row.classList.toggle( 'is-selected', toggle.checked );

		if ( toggle.checked ) {
			filterOnboardingTeams( row );
		}
	}

	function storageKey( form ) {
		var portal = form.closest( '[data-leagueflow-user-id]' );
		var userId = portal ? portal.getAttribute( 'data-leagueflow-user-id' ) : 'anonymous';

		return 'leagueflow-onboarding:' + ( form.getAttribute( 'data-onboarding-kind' ) || 'profile' ) + ':' + userId;
	}

	function draftElements( form ) {
		return Array.prototype.filter.call( form.elements, function ( field ) {
			return field.name && 'file' !== field.type &&
				'action' !== field.name &&
				'leagueflow_portal_nonce' !== field.name &&
				'leagueflow_redirect_to' !== field.name &&
				'leagueflow_portal_action' !== field.name;
		} );
	}

	function saveDraft( form ) {
		try {
			var data = {};

			draftElements( form ).forEach( function ( field ) {
				var isArrayField = /\[\]$/.test( field.name );

				if ( 'checkbox' === field.type || isArrayField ) {
					if ( ! Array.isArray( data[ field.name ] ) ) {
						data[ field.name ] = [];
					}

					if ( 'checkbox' !== field.type || field.checked ) {
						data[ field.name ].push( field.value );
					}
				} else {
					data[ field.name ] = field.value;
				}
			} );

			window.sessionStorage.setItem( storageKey( form ), JSON.stringify( data ) );
		} catch ( error ) {
			return;
		}
	}

	function restoreDraft( form ) {
		try {
			var raw = window.sessionStorage.getItem( storageKey( form ) );
			var data = raw ? JSON.parse( raw ) : null;

			if ( ! data ) {
				return;
			}

			draftElements( form ).forEach( function ( field ) {
				if ( ! Object.prototype.hasOwnProperty.call( data, field.name ) ) {
					return;
				}

				if ( 'checkbox' === field.type ) {
					field.checked = Array.isArray( data[ field.name ] ) && -1 !== data[ field.name ].indexOf( field.value );
				} else if ( 'hidden' !== field.type ) {
					field.value = data[ field.name ];
				}
			} );
		} catch ( error ) {
			return;
		}
	}

	function clearCompletedDraft( form ) {
		var notice = new URLSearchParams( window.location.search ).get( 'leagueflow_notice' );

		if ( 'team-registered' === notice || 'player-onboarding-complete' === notice ) {
			try {
				window.sessionStorage.removeItem( storageKey( form ) );
			} catch ( error ) {
				return;
			}
		}
	}

	function hasOnboardingValidationNotice() {
		var notice = new URLSearchParams( window.location.search ).get( 'leagueflow_notice' );
		var validationNotices = [
			'invalid-request',
			'name-setup-required',
			'sport-level-required',
			'sport-request-required',
			'team-level-mismatch',
			'team-exists',
			'captain-sport-exists',
			'player-sport-exists',
			'registration-email-denied',
			'onboarding-save-error',
			'registration-closed',
			'upload-error'
		];

		return -1 !== validationNotices.indexOf( notice );
	}

	function clearSuccessfulDrafts() {
		var notice = new URLSearchParams( window.location.search ).get( 'leagueflow_notice' );

		if ( 'team-registered' !== notice && 'player-onboarding-complete' !== notice ) {
			return;
		}

		try {
			var portal = document.querySelector( '[data-leagueflow-user-id]' );
			var userId = portal ? portal.getAttribute( 'data-leagueflow-user-id' ) : 'anonymous';
			window.sessionStorage.removeItem( 'leagueflow-onboarding:captain:' + userId );
			window.sessionStorage.removeItem( 'leagueflow-onboarding:player:' + userId );
			window.sessionStorage.removeItem( 'leagueflow-onboarding:captain' );
			window.sessionStorage.removeItem( 'leagueflow-onboarding:player' );
		} catch ( error ) {
			return;
		}
	}

	function hasSelectedSport( step ) {
		return !! step.querySelector( 'input[name="lf_player_sports[]"][type="hidden"], input[name="lf_player_sports[]"][type="checkbox"]:checked' );
	}

	function validateStep( form, step ) {
		var sportError = step.querySelector( '[data-leagueflow-sport-error]' );

		if ( 'player' === form.getAttribute( 'data-onboarding-kind' ) && '2' === step.getAttribute( 'data-leagueflow-step' ) ) {
			var hasSport = hasSelectedSport( step );

			if ( sportError ) {
				sportError.hidden = hasSport;
			}

			if ( ! hasSport ) {
				var firstToggle = step.querySelector( '[data-leagueflow-player-sport-toggle]' );
				if ( firstToggle ) {
					firstToggle.focus();
				}
				return false;
			}
		}

		var required = step.querySelectorAll( '[required]' );
		var valid = true;

		Array.prototype.some.call( required, function ( field ) {
			if ( ! field.disabled && ! field.checkValidity() ) {
				field.reportValidity();
				valid = false;
				return true;
			}
			return false;
		} );

		return valid;
	}

	function addReviewItem( list, label, value ) {
		if ( ! value ) {
			return;
		}

		var item = document.createElement( 'li' );
		var heading = document.createElement( 'strong' );
		var content = document.createElement( 'span' );
		heading.textContent = label;
		content.textContent = value;
		item.appendChild( heading );
		item.appendChild( content );
		list.appendChild( item );
	}

	function buildReview( form ) {
		var list = form.querySelector( '[data-leagueflow-review-list]' );

		if ( ! list ) {
			return;
		}

		list.textContent = '';
		addReviewItem( list, 'Verified email', form.getAttribute( 'data-verified-email' ) || '' );

		Array.prototype.forEach.call( form.elements, function ( field ) {
			if ( ! field.hasAttribute || ! field.hasAttribute( 'data-review-label' ) ) {
				return;
			}

			var value = 'SELECT' === field.tagName ? selectedOptionText( field ) : field.value.trim();
			if ( 'file' === field.type ) {
				value = field.files && field.files[ 0 ] ? field.files[ 0 ].name : '';
			}
			addReviewItem( list, field.getAttribute( 'data-review-label' ), value );
		} );

		Array.prototype.forEach.call( form.querySelectorAll( '[data-leagueflow-player-sport-row]' ), function ( row ) {
			var toggle = row.querySelector( '[data-leagueflow-player-sport-toggle]' );
			var locked = row.classList.contains( 'is-locked' );

			if ( ! locked && ( ! toggle || ! toggle.checked ) ) {
				return;
			}

			if ( locked ) {
				var lockedValue = row.querySelector( 'span' );
				addReviewItem( list, row.getAttribute( 'data-sport-label' ), lockedValue ? lockedValue.textContent.trim() : 'Already registered' );
				return;
			}

			var level = row.querySelector( '[data-leagueflow-player-level-select]' );
			var team = row.querySelector( '[data-leagueflow-onboarding-team-select]' );
			var note = row.querySelector( '[data-leagueflow-onboarding-note]' );
			var value = selectedOptionText( level ) + ' - ' + selectedOptionText( team );
			if ( note && note.value.trim() ) {
				value += ' | Note: ' + note.value.trim();
			}
			addReviewItem( list, row.getAttribute( 'data-sport-label' ), value );
		} );
	}

	function showStep( form, stepNumber, shouldFocus ) {
		var steps = form.querySelectorAll( '[data-leagueflow-step]' );
		var indicators = form.querySelectorAll( '[data-leagueflow-step-indicator]' );
		var activeStep = null;

		Array.prototype.forEach.call( steps, function ( step ) {
			var isActive = String( stepNumber ) === step.getAttribute( 'data-leagueflow-step' );
			step.hidden = ! isActive;
			if ( isActive ) {
				activeStep = step;
			}
		} );

		Array.prototype.forEach.call( indicators, function ( indicator ) {
			var indicatorNumber = parseInt( indicator.getAttribute( 'data-leagueflow-step-indicator' ), 10 );
			indicator.classList.toggle( 'is-current', indicatorNumber === stepNumber );
			indicator.classList.toggle( 'is-complete', indicatorNumber < stepNumber );
			if ( indicatorNumber === stepNumber ) {
				indicator.setAttribute( 'aria-current', 'step' );
			} else {
				indicator.removeAttribute( 'aria-current' );
			}
		} );

		form.setAttribute( 'data-current-step', String( stepNumber ) );

		if ( activeStep && shouldFocus ) {
			activeStep.setAttribute( 'tabindex', '-1' );
			activeStep.focus();
		}
	}

	function initOnboarding( form ) {
		var hasServerDraft = hasOnboardingValidationNotice();

		clearCompletedDraft( form );
		if ( ! hasServerDraft ) {
			restoreDraft( form );
		}
		form.classList.add( 'is-enhanced' );

		Array.prototype.forEach.call( form.querySelectorAll( '[data-leagueflow-player-sport-row]' ), syncOnboardingSportRow );
		if ( hasServerDraft ) {
			saveDraft( form );
		}
		showStep( form, 1, false );

		form.addEventListener( 'click', function ( event ) {
			var next = event.target.closest( '[data-leagueflow-step-next]' );
			var back = event.target.closest( '[data-leagueflow-step-back]' );

			if ( ! next && ! back ) {
				return;
			}

			var current = parseInt( form.getAttribute( 'data-current-step' ) || '1', 10 );
			var currentStep = form.querySelector( '[data-leagueflow-step="' + current + '"]' );
			var totalSteps = form.querySelectorAll( '[data-leagueflow-step]' ).length;

			if ( next ) {
				if ( ! validateStep( form, currentStep ) ) {
					return;
				}
				if ( current + 1 === totalSteps ) {
					buildReview( form );
				}
				showStep( form, Math.min( totalSteps, current + 1 ), true );
			} else {
				showStep( form, Math.max( 1, current - 1 ), true );
			}
		} );

		form.addEventListener( 'input', function () {
			saveDraft( form );
		} );

		form.addEventListener( 'change', function () {
			saveDraft( form );
		} );

		form.addEventListener( 'submit', function ( event ) {
			var steps = form.querySelectorAll( '[data-leagueflow-step]' );

			for ( var index = 0; index < steps.length; index += 1 ) {
				if ( ! validateStep( form, steps[ index ] ) ) {
					event.preventDefault();
					showStep( form, index + 1, true );
					return;
				}
			}

			saveDraft( form );
		} );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target && ( event.target.matches( '[data-leagueflow-sport-select]' ) || event.target.matches( '[data-leagueflow-level-select]' ) ) ) {
			syncTeamSelect( event.target.closest( 'form' ) );
		}

		if ( event.target && event.target.matches( '[data-leagueflow-player-sport-toggle]' ) ) {
			var onboardingRow = event.target.closest( '[data-leagueflow-player-sport-row]' );
			if ( onboardingRow ) {
				syncOnboardingSportRow( onboardingRow );
			} else {
				syncPlayerSportPreferenceRow( event.target.closest( '.leagueflow-portal__sport-preference-row' ) );
			}
		}

		if ( event.target && event.target.matches( '[data-leagueflow-player-level-select]' ) ) {
			var row = event.target.closest( '[data-leagueflow-player-sport-row]' );
			if ( row ) {
				filterOnboardingTeams( row );
			}
		}
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		clearSuccessfulDrafts();
		Array.prototype.forEach.call( document.querySelectorAll( '.leagueflow-portal__form--join-request' ), initJoinRequestForm );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-leagueflow-player-sport-preferences]' ), function ( fieldset ) {
			Array.prototype.forEach.call( fieldset.querySelectorAll( '.leagueflow-portal__sport-preference-row' ), syncPlayerSportPreferenceRow );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-leagueflow-onboarding]' ), initOnboarding );
	} );
}() );
