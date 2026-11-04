/**
 * LeagueFlow sports calendar.
 *
 * Renders normalized match and standalone event data embedded by
 * templates/match-calendar.php. The implementation is intentionally
 * framework-free so the plugin can keep its existing asset pipeline.
 */
( function () {
	'use strict';

	function pad( n ) {
		return n < 10 ? '0' + n : String( n );
	}

	function dayKeyFromDate( date ) {
		return date.getFullYear() + '-' + pad( date.getMonth() + 1 ) + '-' + pad( date.getDate() );
	}

	function dayKey( y, m, d ) {
		return y + '-' + pad( m + 1 ) + '-' + pad( d );
	}

	function monthKey( date ) {
		return date.getFullYear() + '-' + pad( date.getMonth() + 1 );
	}

	function parseDay( key ) {
		var parts = key.split( '-' );
		return new Date( parseInt( parts[ 0 ], 10 ), parseInt( parts[ 1 ], 10 ) - 1, parseInt( parts[ 2 ], 10 ) );
	}

	// Event times are rendered in the site timezone, so lay them out by
	// their wall-clock fields rather than converting to the viewer's zone.
	function parseWallClock( value ) {
		var match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?/.exec( value || '' );

		if ( ! match ) {
			return new Date( value );
		}

		return new Date(
			parseInt( match[ 1 ], 10 ),
			parseInt( match[ 2 ], 10 ) - 1,
			parseInt( match[ 3 ], 10 ),
			parseInt( match[ 4 ], 10 ),
			parseInt( match[ 5 ], 10 ),
			parseInt( match[ 6 ] || '0', 10 )
		);
	}

	function addDays( date, amount ) {
		var next = new Date( date.getTime() );
		next.setDate( next.getDate() + amount );
		return next;
	}

	function startOfWeekDate( date, startOfWeek ) {
		var start = new Date( date.getFullYear(), date.getMonth(), date.getDate() );
		var offset = ( start.getDay() - startOfWeek + 7 ) % 7;
		start.setDate( start.getDate() - offset );
		return start;
	}

	function sameDay( a, b ) {
		return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( undefined !== text && null !== text && '' !== text ) {
			node.textContent = text;
		}

		return node;
	}

	function button( className, text ) {
		var node = el( 'button', className, text );
		node.type = 'button';
		return node;
	}

	function sprintf( template, value ) {
		return template.replace( '%s', value );
	}

	function formatDateKey( key, monthNames, weekdays ) {
		var date = parseDay( key );
		var weekday = weekdays[ date.getDay() ] || '';
		var month = monthNames[ date.getMonth() ] || '';
		return weekday + ', ' + month + ' ' + date.getDate() + ', ' + date.getFullYear();
	}

	function formatShortDate( date, monthNames ) {
		return ( monthNames[ date.getMonth() ] || '' ) + ' ' + date.getDate();
	}

	function isFutureOrToday( event, todayKey ) {
		return event.day >= todayKey || ( event.endDate && event.endDate > parseDay( todayKey ) );
	}

	function cleanFilename( title ) {
		return String( title || 'calendar-event' ).replace( /[^a-z0-9]+/gi, '-' ).replace( /^-|-$/g, '' ).toLowerCase() || 'calendar-event';
	}

	function escapeIcsText( value ) {
		return String( value || '' )
			.replace( /\\/g, '\\\\' )
			.replace( /\n/g, '\\n' )
			.replace( /,/g, '\\,' )
			.replace( /;/g, '\\;' );
	}

	function formatIcsDate( date ) {
		return date.toISOString().replace( /[-:]/g, '' ).replace( /\.\d{3}/, '' );
	}

	function init( root ) {
		var document = root.ownerDocument;
		var window = document.defaultView;
		if ( root.dataset.calendarInitialized ) { return; }
		var dataNode = root.querySelector( '[data-calendar-data]' );

		if ( ! dataNode ) {
			return;
		}

		var data;

		try {
			data = JSON.parse( dataNode.textContent );
		} catch ( err ) {
			return;
		}

		var config = data.config || {};
		var strings = data.strings || {};
		var sports = data.sports || [];
		function normalizeEvents( items ) {
		return items.map( function ( event ) {
			event.startDate = parseWallClock( event.start );
			event.endDate = parseWallClock( event.end );
			event.startInstant = new Date( event.start );
			event.endInstant = new Date( event.end );
			event.searchText = [
				event.title,
				event.description,
				event.sportLabel,
				event.leagueLevelLabel,
				event.kindLabel,
				event.venue,
				event.home,
				event.away,
				event.competition,
				event.season
			].join( ' ' ).toLowerCase();
			return event;
		} ).filter( function ( event ) {
			return ! isNaN( event.startDate.getTime() );
		} );
		}
		var events = normalizeEvents( data.events || [] );

		var startOfWeek = parseInt( config.startOfWeek, 10 ) || 0;
		var monthNames = config.monthNames || [];
		var weekdays = config.weekdays || [];
		var weekdaysShort = config.weekdaysShort || [];

		var stage = root.querySelector( '[data-calendar-stage]' );
		var rangeLabel = root.querySelector( '[data-calendar-range-label]' );
		var prevBtn = root.querySelector( '[data-calendar-prev]' );
		var nextBtn = root.querySelector( '[data-calendar-next]' );
		var todayBtn = root.querySelector( '[data-calendar-today]' );
		var panelTitle = root.querySelector( '[data-calendar-panel-title]' );
		var panelList = root.querySelector( '[data-calendar-events]' );
		var clearBtn = root.querySelector( '[data-calendar-clear]' );
		var searchInput = root.querySelector( '[data-calendar-search]' );
		var viewButtons = root.querySelectorAll( '[data-calendar-view]' );
		var dialog = root.querySelector( '[data-calendar-dialog]' );
		var dialogContent = root.querySelector( '[data-calendar-dialog-content]' );
		var panelWrap = root.querySelector( '[data-calendar-panel]' );
		var panelClose = root.querySelector( '[data-calendar-panel-close]' );
		var jumpInput = root.querySelector( '[data-calendar-jump]' );
		var monthDisplay = [ 'popover', 'dropdown', 'sidebar' ].indexOf( config.monthDisplay ) >= 0 ? config.monthDisplay : 'sidebar';
		var mobileQuery = window.matchMedia ? window.matchMedia( '(max-width: 760px)' ) : null;

		if ( ! stage || ! panelList || ! rangeLabel ) {
			return;
		}

		root.dataset.calendarInitialized = 'true';
		var sportColors = {};

		sports.forEach( function ( sport ) {
			sportColors[ sport.slug ] = sport.color;
		} );

		var today = parseDay( config.today || dayKeyFromDate( new Date() ) );
		var initialDate = today;
		var initialView = config.initialView || 'month';
		var listInitial = parseInt( config.listInitial, 10 ) || 30;
		var listMore = parseInt( config.listMore, 10 ) || 15;

		if ( ! config.rangeLoading && events.length && ! events.some( function ( event ) {
			return monthKey( event.startDate ) === monthKey( today );
		} ) ) {
			var upcoming = events.filter( function ( event ) {
				return event.day >= dayKeyFromDate( today );
			} );
			initialDate = ( upcoming[ 0 ] || events[ 0 ] ).startDate;
		}

		var state = {
			view: initialView,
			date: new Date( initialDate.getFullYear(), initialDate.getMonth(), initialDate.getDate() ),
			selectedDay: null,
			fullMonth: false,
			sport: '',
			type: '',
			search: '',
			listCount: listInitial
		};

		function filteredEvents() {
			var query = state.search.trim().toLowerCase();

			return events.filter( function ( event ) {
				if ( state.sport && event.sport !== state.sport ) {
					return false;
				}

				if ( state.type && event.kind !== state.type ) {
					return false;
				}

				if ( query && event.searchText.indexOf( query ) === -1 ) {
					return false;
				}

				return true;
			} );
		}

		function eventsForDay( key, source ) {
			var start = parseDay( key ), end = addDays( start, 1 );
			return source.filter( function ( event ) { return event.startDate < end && event.endDate > start; } ).map( function ( event ) {
				return Object.assign( {}, event, { _original: event, _startMinute: event.startDate < start ? 0 : event.startDate.getHours() * 60 + event.startDate.getMinutes(), _endMinute: event.endDate >= end ? 1440 : event.endDate.getHours() * 60 + event.endDate.getMinutes(), time: event.startDate < start ? ( strings.continues || 'Continues' ) : event.time } );
			} );
		}

		function eventsForMonth( date, source ) {
			var start = new Date( date.getFullYear(), date.getMonth(), 1 ), end = new Date( date.getFullYear(), date.getMonth() + 1, 1 );
			return source.filter( function ( event ) { return event.startDate < end && event.endDate > start; } );
		}

		function isMobileCalendar() {
			var width = root.getBoundingClientRect().width;
			return ( width > 0 && width <= 760 ) || !! ( mobileQuery && mobileQuery.matches );
		}

		function defaultMobileMonthDay( source ) {
			var todayKey = config.today || dayKeyFromDate( new Date() );
			var monthItems = eventsForMonth( state.date, source );

			if ( monthKey( today ) === monthKey( state.date ) ) {
				return todayKey;
			}

			if ( monthItems.length ) {
				return monthItems[ 0 ].day;
			}

			return dayKey( state.date.getFullYear(), state.date.getMonth(), 1 );
		}

		function applyResponsiveState( source ) {
			var mobile = isMobileCalendar();

			root.classList.toggle( 'is-mobile-calendar', mobile );
			root.classList.remove( 'is-view-month', 'is-view-list', 'is-view-week', 'is-view-day' );
			root.classList.add( 'is-view-' + state.view );
			root.classList.add( 'is-month-' + monthDisplay );
			root.classList.toggle( 'is-sidebar-left', 'left' === config.sidebarPosition );

			if ( mobile && 'week' === state.view ) {
				state.view = 'month';
				root.classList.remove( 'is-view-week' );
				root.classList.add( 'is-view-month' );
			}

			if ( mobile && 'month' === state.view && 'sidebar' === monthDisplay && ! state.selectedDay && ! state.fullMonth ) {
				state.selectedDay = defaultMobileMonthDay( source );
			}
		}

		function setActiveButtons() {
			viewButtons.forEach( function ( control ) {
				var active = control.dataset.calendarView === state.view;
				control.classList.toggle( 'is-active', active );
				control.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );
		}

		function setChipState( chips, attr, value ) {
			chips.forEach( function ( chip ) {
				var active = ( chip.dataset[ attr ] || '' ) === value;
				chip.classList.toggle( 'is-active', active );
				chip.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );
		}

		function eventAccent( event ) {
			return sportColors[ event.sport ] || 'var(--leagueflow-accent)';
		}

		function statusBadge( event ) {
			if ( ! event.status || 'scheduled' === event.status ) {
				return null;
			}

			var badge = el( 'span', 'leagueflow-calendar__event-status is-' + event.status, event.statusLabel || event.status );
			return badge;
		}

		function eventMetaText( event ) {
			var bits = [];

			if ( event.venue ) {
				bits.push( event.venue );
			}

			if ( event.competition ) {
				bits.push( event.competition );
			}

			if ( event.leagueLevelLabel ) {
				bits.push( event.leagueLevelLabel );
			}

			if ( event.round ) {
				bits.push( event.round );
			}

			if ( false !== config.showCost && event.cost ) {
				bits.push( event.cost );
			}

			return bits.join( ' - ' );
		}

		function buildEventCard( event, options ) {
			options = options || {};

			var card = button( 'leagueflow-calendar__event' + ( options.compact ? ' leagueflow-calendar__event--compact' : '' ) );
			card.style.setProperty( '--lf-cal-accent', eventAccent( event ) );

			var top = el( 'div', 'leagueflow-calendar__event-top' );
			var sportBadge = el( 'span', 'leagueflow-calendar__event-sport' );
			sportBadge.appendChild( el( 'span', 'leagueflow-calendar__event-dot' ) );
			sportBadge.appendChild( document.createTextNode( event.sportLabel || '' ) );
			top.appendChild( sportBadge );
			if ( event.leagueLevelLabel ) {
				top.appendChild( el( 'span', 'leagueflow-calendar__event-type', event.leagueLevelLabel ) );
			}
			top.appendChild( el( 'span', 'leagueflow-calendar__event-type', event.kindLabel || '' ) );
			top.appendChild( el( 'span', 'leagueflow-calendar__event-time', event.time + ( options.showDate ? ' - ' + formatDateKey( event.day, monthNames, weekdays ) : '' ) ) );

			var status = statusBadge( event );

			if ( status ) {
				top.appendChild( status );
			}

			card.appendChild( top );

			if ( 'match' === event.source ) {
				var teams = el( 'p', 'leagueflow-calendar__event-teams' );
				teams.appendChild( el( 'span', null, event.home ) );
				teams.appendChild( el( 'strong', 'leagueflow-calendar__event-score', event.scoreline ? event.scoreline : ( event.resultLabel ? '—' : ( strings.vs || 'vs' ) ) ) );
				teams.appendChild( el( 'span', null, event.away ) );
				card.appendChild( teams );
				if ( event.resultLabel ) {
					card.appendChild( el( 'p', 'leagueflow-calendar__event-result', event.resultLabel ) );
				}
			} else {
				if ( event.poster && event.poster.src && ! options.compact ) {
					var posterThumb = el( 'img', 'leagueflow-calendar__event-poster' );
					posterThumb.src = event.poster.src;
					posterThumb.alt = '';
					posterThumb.loading = 'lazy';
					posterThumb.decoding = 'async';
					card.classList.add( 'has-poster' );
					card.appendChild( posterThumb );
				}
				card.appendChild( el( 'p', 'leagueflow-calendar__event-title', event.title ) );

				if ( event.registrationRequired ) {
					card.appendChild( el( 'span', 'leagueflow-calendar__event-requires-registration', strings.registration || 'Registration required' ) );
				}
			}

			var meta = eventMetaText( event );

			if ( meta && ! options.compact ) {
				card.appendChild( el( 'p', 'leagueflow-calendar__event-meta', meta ) );
			}

			card.addEventListener( 'click', function ( evt ) {
				evt.stopPropagation();
				openDialog( event );
			} );

			return card;
		}

		function countLabel( count ) {
			return count + ' ' + ( 1 === count ? ( strings.event || 'event' ) : ( strings.eventsPlural || 'events' ) );
		}

		// Count pill beside the panel title (selected day or month total).
		var panelCount = null;
		function setPanelCount( count ) {
			if ( ! panelCount && panelTitle && panelTitle.parentNode ) {
				panelCount = el( 'span', 'leagueflow-calendar__count' );
				panelCount.setAttribute( 'data-calendar-panel-count', '' );
				panelTitle.parentNode.insertBefore( panelCount, panelTitle.nextSibling );
			}
			if ( panelCount ) {
				panelCount.hidden = null === count;
				panelCount.textContent = null === count ? '' : countLabel( count );
			}
		}

		function renderPanel( source ) {
			panelList.textContent = '';
			if ( panelWrap ) { panelWrap.hidden = 'list' === state.view || ( 'month' === state.view && 'sidebar' !== monthDisplay && ! state.selectedDay ); }
			if ( panelClose ) { panelClose.hidden = ! ( 'month' === state.view && 'sidebar' !== monthDisplay && state.selectedDay ); }

			if ( state.selectedDay ) {
				panelTitle.textContent = sprintf( strings.eventsOn || 'Schedule on %s', formatDateKey( state.selectedDay, monthNames, weekdays ) );
				// The month view has no separate deselect control: selecting the day
				// again returns the panel to the whole month (desktop only).
				clearBtn.hidden = 'month' === state.view;

				var selectedItems = eventsForDay( state.selectedDay, source );
				setPanelCount( selectedItems.length );

				if ( ! selectedItems.length ) {
					panelList.appendChild( el( 'p', 'leagueflow-calendar__empty', strings.noEventsDay || 'No events on this day.' ) );
					return;
				}

				selectedItems.forEach( function ( event ) {
					panelList.appendChild( buildEventCard( event ) );
				} );

				return;
			}

			clearBtn.hidden = true;

			var monthName = ( monthNames[ state.date.getMonth() ] || '' ) + ' ' + state.date.getFullYear();
			var monthItems = eventsForMonth( state.date, source );
			panelTitle.textContent = sprintf( strings.monthSchedule || 'Schedule in %s', monthName );

			setPanelCount( 'list' === state.view ? null : monthItems.length );

			if ( 'list' === state.view ) {
				panelTitle.textContent = strings.details || 'Details';
				panelList.appendChild( el( 'p', 'leagueflow-calendar__empty', strings.selectEvent || 'Select an event to see details.' ) );
				return;
			}

			if ( ! monthItems.length ) {
				panelList.appendChild( el( 'p', 'leagueflow-calendar__empty', sprintf( strings.noEventsMonth || 'No events scheduled in %s.', monthName ) ) );
				return;
			}

			var currentDay = '';

			monthItems.forEach( function ( event ) {
				if ( event.day !== currentDay ) {
					currentDay = event.day;
					panelList.appendChild( el( 'h4', 'leagueflow-calendar__day-heading', formatDateKey( event.day, monthNames, weekdays ) ) );
				}

				panelList.appendChild( buildEventCard( event ) );
			} );
		}

		function renderMonth( source ) {
			var wrap = el( 'div', 'leagueflow-calendar__month-view' );
			var weekdaysRow = el( 'div', 'leagueflow-calendar__weekdays' );
			var grid = el( 'div', 'leagueflow-calendar__grid' );
			var firstWeekday = new Date( state.date.getFullYear(), state.date.getMonth(), 1 ).getDay();
			var offset = ( firstWeekday - startOfWeek + 7 ) % 7;
			var daysInMonth = new Date( state.date.getFullYear(), state.date.getMonth() + 1, 0 ).getDate();
			var daysInPrev = new Date( state.date.getFullYear(), state.date.getMonth(), 0 ).getDate();
			var totalCells = Math.ceil( ( offset + daysInMonth ) / 7 ) * 7;
			var todayKey = config.today || dayKeyFromDate( new Date() );

			for ( var w = 0; w < 7; w++ ) {
				var weekdayIndex = ( startOfWeek + w ) % 7;
				weekdaysRow.appendChild( el( 'span', 'leagueflow-calendar__weekday', weekdaysShort[ weekdayIndex ] || '' ) );
			}

			for ( var cell = 0; cell < totalCells; cell++ ) {
				var dayNum = cell - offset + 1;

				if ( dayNum < 1 || dayNum > daysInMonth ) {
					var outside = el( 'div', 'leagueflow-calendar__day is-outside' );
					outside.appendChild( el( 'span', 'leagueflow-calendar__day-num', String( dayNum < 1 ? daysInPrev + dayNum : dayNum - daysInMonth ) ) );
					grid.appendChild( outside );
					continue;
				}

				var key = dayKey( state.date.getFullYear(), state.date.getMonth(), dayNum );
				var items = eventsForDay( key, source );
				var day = button( 'leagueflow-calendar__day' + ( items.length ? ' has-games' : '' ) + ( key === todayKey ? ' is-today' : '' ) + ( key === state.selectedDay ? ' is-selected' : '' ) );
				day.dataset.day = key;
				day.setAttribute( 'aria-label', formatDateKey( key, monthNames, weekdays ) + ': ' + items.length + ' ' + ( strings.eventsPlural || 'events' ) );
				day.setAttribute( 'aria-expanded', state.selectedDay === key ? 'true' : 'false' );
				if ( panelWrap ) { day.setAttribute( 'aria-controls', panelWrap.id || ( panelWrap.id = root.id + '-day-panel' ) ); }
				day.appendChild( el( 'span', 'leagueflow-calendar__day-num', String( dayNum ) ) );

				if ( items.length ) {
					var summary = el( 'span', 'leagueflow-calendar__day-summary' );
					var visible = items.slice( 0, 3 );

					visible.forEach( function ( event ) {
						var pill = el( 'span', 'leagueflow-calendar__day-event' );
						pill.style.setProperty( '--lf-cal-accent', eventAccent( event ) );
						pill.textContent = event.time + ' ' + ( 'match' === event.source ? event.home + ' vs ' + event.away : event.title );
						summary.appendChild( pill );
					} );

					if ( items.length > visible.length ) {
						summary.appendChild( el( 'span', 'leagueflow-calendar__day-more', '+' + ( items.length - visible.length ) ) );
					}

					day.appendChild( summary );
				}

				day.addEventListener( 'click', function () {
					state.fullMonth = false;
					// Phones always show one selected day under the compact month.
					state.selectedDay = state.selectedDay === this.dataset.day && ! isMobileCalendar() ? null : this.dataset.day;
					var tappedDay = this.dataset.day;
					state.date = parseDay( tappedDay );
					render();
					// `key` is the loop variable (last cell); refocus the day actually tapped.
					var selectedControl = root.querySelector( '[data-day="' + tappedDay + '"]' );
					if ( selectedControl ) { selectedControl.focus(); }
				} );

				grid.appendChild( day );
			}

			wrap.appendChild( weekdaysRow );
			wrap.appendChild( grid );
			stage.appendChild( wrap );
		}

		function groupEventsByDay( items ) {
			return items.reduce( function ( grouped, event ) {
				if ( ! grouped[ event.day ] ) {
					grouped[ event.day ] = [];
				}

				grouped[ event.day ].push( event );
				return grouped;
			}, {} );
		}

		function renderList( source ) {
			var todayKey = config.today || dayKeyFromDate( new Date() );
			var upcoming = source.filter( function ( event ) {
				return isFutureOrToday( event, todayKey );
			} );
			var shown = upcoming.slice( 0, state.listCount );
			var grouped = groupEventsByDay( shown );
			var keys = Object.keys( grouped ).sort();
			if ( 'desc' === config.eventSortOrder ) { keys.reverse(); }
			var wrap = el( 'div', 'leagueflow-calendar__list-view' );

			if ( ! keys.length ) {
				wrap.appendChild( el( 'p', 'leagueflow-calendar__empty leagueflow-calendar__empty--large', strings.noEventsList || 'No upcoming events match the current filters.' ) );
				stage.appendChild( wrap );
				return;
			}

			keys.forEach( function ( key ) {
				var group = el( 'section', 'leagueflow-calendar__list-group' );
				var header = el( 'div', 'leagueflow-calendar__list-date' );
				var rule = el( 'span', 'leagueflow-calendar__list-rule' );
				rule.setAttribute( 'aria-hidden', 'true' );
				header.appendChild( el( 'h4', null, formatDateKey( key, monthNames, weekdays ) ) );
				header.appendChild( rule );
				header.appendChild( el( 'span', 'leagueflow-calendar__count', countLabel( grouped[ key ].length ) ) );
				group.appendChild( header );

				grouped[ key ].forEach( function ( event ) {
					group.appendChild( buildEventCard( event, { showDate: false } ) );
				} );

				wrap.appendChild( group );
			} );

			if ( upcoming.length > shown.length || ( config.rangeLoading && pagination.nextPage ) ) {
				var more = button( 'leagueflow-calendar__load-more', strings.loadMore || 'Load more events' );
				more.disabled = loadingPage;
				more.addEventListener( 'click', function () {
					state.listCount += listMore;
					if ( shown.length >= upcoming.length && pagination.nextPage ) { loadCalendarPage( activeScope, pagination.nextPage, false ); }
					render();
				} );
				wrap.appendChild( more );
			}

			stage.appendChild( wrap );
		}

		function visibleHours( items ) {
			if ( ! items.length ) {
				return { start: 8, end: 22 };
			}

			var min = 23;
			var max = 0;

			items.forEach( function ( event ) {
				min = Math.min( min, undefined !== event._startMinute ? Math.floor( event._startMinute / 60 ) : event.startDate.getHours() );
				max = Math.max( max, undefined !== event._endMinute ? Math.ceil( event._endMinute / 60 ) : event.endDate.getHours() + ( event.endDate.getMinutes() > 0 ? 1 : 0 ) );
			} );

			return {
				start: Math.max( 0, Math.min( 8, min ) ),
				end: Math.min( 24, Math.max( 22, max ) )
			};
		}

		function hourLabel( hour ) {
			if ( 0 === hour ) {
				return '12 AM';
			}

			if ( 12 === hour ) {
				return '12 PM';
			}

			return hour > 12 ? ( hour - 12 ) + ' PM' : hour + ' AM';
		}

		function eventBlockPosition( event, hourStart ) {
			var start = undefined !== event._startMinute ? event._startMinute / 60 : event.startDate.getHours() + event.startDate.getMinutes() / 60;
			var end = undefined !== event._endMinute ? event._endMinute / 60 : event.endDate.getHours() + event.endDate.getMinutes() / 60;
			var duration = Math.max( 0.5, end - start );

			return {
				top: ( start - hourStart ) * 72,
				height: Math.max( 42, duration * 72 )
			};
		}

		// Side-by-side lanes for events whose rendered blocks overlap in one day
		// column. Blocks are compared by their drawn top/bottom (including the
		// minimum block height), clustered while they keep overlapping, and each
		// takes the first free lane; every block in a cluster shares its lane count.
		// Blocks that only touch (one ends where the next starts) do not overlap.
		function layoutTimedEvents( items, hourStart ) {
			var slots = items.map( function ( event, index ) {
				var pos = eventBlockPosition( event, hourStart );
				return { event: event, index: index, top: pos.top, bottom: pos.top + pos.height, lane: 0, lanes: 1 };
			} );
			var ordered = slots.slice().sort( function ( a, b ) {
				return ( a.top - b.top ) || ( b.bottom - a.bottom ) || ( a.index - b.index );
			} );
			var cluster = [];
			var laneEnds = [];
			var clusterEnd = -Infinity;

			function closeCluster() {
				cluster.forEach( function ( slot ) {
					slot.lanes = laneEnds.length;
				} );
				cluster = [];
				laneEnds = [];
			}

			ordered.forEach( function ( slot ) {
				if ( cluster.length && slot.top >= clusterEnd ) {
					closeCluster();
				}

				var lane = 0;
				while ( lane < laneEnds.length && laneEnds[ lane ] > slot.top ) {
					lane++;
				}

				laneEnds[ lane ] = slot.bottom;
				slot.lane = lane;
				cluster.push( slot );
				clusterEnd = cluster.length > 1 ? Math.max( clusterEnd, slot.bottom ) : slot.bottom;
			} );
			closeCluster();

			return slots;
		}

		function appendTimedEvents( column, items, hourStart ) {
			layoutTimedEvents( items, hourStart ).forEach( function ( slot ) {
				column.appendChild( buildTimedEventBlock( slot.event, hourStart, slot ) );
			} );
		}

		function buildTimedEventBlock( event, hourStart, slot ) {
			var pos = eventBlockPosition( event, hourStart );
			var block = button( 'leagueflow-calendar__time-event' );
			block.style.setProperty( '--lf-cal-accent', eventAccent( event ) );
			block.style.top = pos.top + 'px';
			block.style.height = pos.height + 'px';

			if ( slot && slot.lanes > 1 ) {
				block.classList.add( 'is-concurrent' );
				block.style.setProperty( '--lf-cal-lane', String( slot.lane ) );
				block.style.setProperty( '--lf-cal-lanes', String( slot.lanes ) );
				block.setAttribute( 'data-lane', String( slot.lane ) );
				block.setAttribute( 'data-lanes', String( slot.lanes ) );
			}
			block.appendChild( el( 'strong', null, 'match' === event.source ? event.home + ' vs ' + event.away : event.title ) );
			block.appendChild( el( 'span', null, event.time + ' - ' + event.endTime ) );

			if ( event.venue ) {
				block.appendChild( el( 'span', null, event.venue ) );
			}

			block.addEventListener( 'click', function ( evt ) {
				evt.stopPropagation();
				openDialog( event );
			} );

			return block;
		}

		function renderWeek( source ) {
			var weekStart = startOfWeekDate( state.date, startOfWeek );
			var weekDays = [];
			var weekItems = [];

			for ( var i = 0; i < 7; i++ ) {
				weekDays.push( addDays( weekStart, i ) );
			}

			weekDays.forEach( function ( date ) { weekItems = weekItems.concat( eventsForDay( dayKeyFromDate( date ), source ) ); } );

			var hours = visibleHours( weekItems );
			var wrap = el( 'div', 'leagueflow-calendar__week-view' );
			var header = el( 'div', 'leagueflow-calendar__week-head' );
			header.appendChild( el( 'span', 'leagueflow-calendar__time-head', '' ) );

			weekDays.forEach( function ( date ) {
				var head = el( 'div', 'leagueflow-calendar__week-day-head' + ( sameDay( date, today ) ? ' is-today' : '' ) );
				head.appendChild( el( 'span', null, weekdaysShort[ date.getDay() ] || '' ) );
				head.appendChild( el( 'strong', null, String( date.getDate() ) ) );
				header.appendChild( head );
			} );

			wrap.appendChild( header );

			var grid = el( 'div', 'leagueflow-calendar__week-grid' );
			var timeCol = el( 'div', 'leagueflow-calendar__time-col' );

			for ( var hour = hours.start; hour < hours.end; hour++ ) {
				timeCol.appendChild( el( 'div', 'leagueflow-calendar__time-slot', hourLabel( hour ) ) );
			}

			grid.appendChild( timeCol );

			weekDays.forEach( function ( date ) {
				var dayCol = el( 'div', 'leagueflow-calendar__week-col' );

				for ( var hour = hours.start; hour < hours.end; hour++ ) {
					dayCol.appendChild( el( 'div', 'leagueflow-calendar__hour-line' ) );
				}

				appendTimedEvents( dayCol, eventsForDay( dayKeyFromDate( date ), source ), hours.start );

				grid.appendChild( dayCol );
			} );

			wrap.appendChild( grid );
			stage.appendChild( wrap );
		}

		function renderDay( source ) {
			var key = dayKeyFromDate( state.date );
			var items = eventsForDay( key, source );
			var hours = visibleHours( items );
			var wrap = el( 'div', 'leagueflow-calendar__day-view' );
			var timeCol = el( 'div', 'leagueflow-calendar__time-col' );
			var dayCol = el( 'div', 'leagueflow-calendar__day-col' );

			for ( var hour = hours.start; hour < hours.end; hour++ ) {
				timeCol.appendChild( el( 'div', 'leagueflow-calendar__time-slot', hourLabel( hour ) ) );
				dayCol.appendChild( el( 'div', 'leagueflow-calendar__hour-line' ) );
			}

			appendTimedEvents( dayCol, items, hours.start );

			if ( ! items.length ) {
				dayCol.appendChild( el( 'p', 'leagueflow-calendar__empty leagueflow-calendar__empty--time', strings.noEventsDay || 'No events on this day.' ) );
			}

			wrap.appendChild( timeCol );
			wrap.appendChild( dayCol );
			stage.appendChild( wrap );
		}

		function updateRangeLabel() {
			if ( 'month' === state.view ) {
				rangeLabel.textContent = ( monthNames[ state.date.getMonth() ] || '' ) + ' ' + state.date.getFullYear();
				return;
			}

			if ( 'week' === state.view ) {
				var start = startOfWeekDate( state.date, startOfWeek );
				var end = addDays( start, 6 );
				rangeLabel.textContent = formatShortDate( start, monthNames ) + ' - ' + formatShortDate( end, monthNames ) + ', ' + end.getFullYear();
				return;
			}

			if ( 'day' === state.view ) {
				rangeLabel.textContent = formatDateKey( dayKeyFromDate( state.date ), monthNames, weekdays );
				return;
			}

			rangeLabel.textContent = strings.list || 'List';
		}

		function render() {
			ensureVisibleRange();
			var source = filteredEvents();

			applyResponsiveState( source );
			stage.textContent = '';
			updateRangeLabel();
			setActiveButtons();
			prevBtn.hidden = 'list' === state.view;
			nextBtn.hidden = 'list' === state.view;
			todayBtn.hidden = 'list' === state.view;

			if ( state.selectedDay && ! eventsForDay( state.selectedDay, source ).length && ! ( isMobileCalendar() && 'month' === state.view ) ) {
				state.selectedDay = null;
			}

			if ( 'month' === state.view ) {
				renderMonth( source );
			} else if ( 'week' === state.view ) {
				renderWeek( source );
			} else if ( 'day' === state.view ) {
				renderDay( source );
			} else {
				renderList( source );
			}

			renderPanel( source );
			if ( jumpInput ) { jumpInput.value = dayKeyFromDate( state.date ); jumpInput.parentElement.hidden = 'list' === state.view; }
			if ( panelWrap && 'popover' === monthDisplay && 'month' === state.view && state.selectedDay && ! isMobileCalendar() ) {
				var selectedCell = root.querySelector( '[data-day="' + state.selectedDay + '"]' );
				var layout = root.querySelector( '.leagueflow-calendar__layout' );
				if ( selectedCell && layout ) {
					var cellRect = selectedCell.getBoundingClientRect(), layoutRect = layout.getBoundingClientRect();
					panelWrap.style.left = Math.max( 0, Math.min( cellRect.left - layoutRect.left, layoutRect.width - 360 ) ) + 'px';
					panelWrap.style.top = cellRect.bottom - layoutRect.top + 8 + 'px';
				}
			} else if ( panelWrap ) { panelWrap.style.left = ''; panelWrap.style.top = ''; }
			if ( config.rangeLoading && ! events.length && ( loadingPage || pageError ) ) {
				var statusText = pageError || strings.rangeLoading || 'Loading this schedule…';
				stage.textContent = '';
				stage.appendChild( el( 'p', 'leagueflow-calendar__empty', statusText ) );
				panelList.textContent = statusText;
				setPanelCount( null );
			}
			root.classList.add( 'is-ready' );
		}

		function shiftRange( amount ) {
			state.fullMonth = false;

			if ( 'month' === state.view ) {
				state.date = new Date( state.date.getFullYear(), state.date.getMonth() + amount, 1 );
				state.selectedDay = null;
			} else if ( 'week' === state.view ) {
				state.date = addDays( state.date, amount * 7 );
				state.selectedDay = null;
			} else if ( 'day' === state.view ) {
				state.date = addDays( state.date, amount );
				state.selectedDay = dayKeyFromDate( state.date );
			}

			render();
		}

		var dialogReturnFocus = null;
		var posterViewer = null;

		// Poster: a card-sized image in the dialog; activating it shows the full
		// image inside the same dialog, so focus handling and Escape stay simple.
		function addPoster( event ) {
			var poster = event.poster;
			if ( ! poster || ! poster.src || ! poster.full ) {
				return;
			}
			var card = dialog.querySelector( '[role="dialog"]' ) || dialog;
			var open = el( 'button', 'leagueflow-calendar__poster' );
			open.type = 'button';
			open.setAttribute( 'aria-label', ( strings.viewPoster || 'View poster full size' ) + ': ' + ( poster.alt || event.title ) );
			var thumb = el( 'img' );
			thumb.src = poster.src;
			thumb.alt = '';
			if ( poster.width && poster.height ) { thumb.width = poster.width; thumb.height = poster.height; }
			thumb.decoding = 'async';
			open.appendChild( thumb );
			open.appendChild( el( 'span', 'leagueflow-calendar__poster-hint', strings.viewPoster || 'View poster full size' ) );
			dialogContent.appendChild( open );

			var viewer = el( 'div', 'leagueflow-calendar__poster-viewer' );
			viewer.hidden = true;
			var bar = el( 'div', 'leagueflow-calendar__poster-bar' );
			var back = el( 'button', 'leagueflow-calendar__poster-link', '‹ ' + ( strings.backToEvent || 'Back to details' ) );
			back.type = 'button';
			var tab = el( 'a', 'leagueflow-calendar__poster-link', strings.openPoster || 'Open in new tab' );
			tab.href = poster.full;
			tab.target = '_blank';
			tab.rel = 'noopener noreferrer';
			bar.appendChild( back );
			bar.appendChild( tab );
			var full = el( 'img', 'leagueflow-calendar__poster-full' );
			full.alt = poster.alt || event.title;
			if ( poster.fullWidth && poster.fullHeight ) { full.width = poster.fullWidth; full.height = poster.fullHeight; }
			viewer.appendChild( bar );
			viewer.appendChild( full );
			dialogContent.appendChild( viewer );

			function show() {
				if ( ! full.getAttribute( 'src' ) ) { full.src = poster.full; }
				viewer.hidden = false;
				card.classList.add( 'is-viewing-poster' );
				posterViewer = hide;
				back.focus();
			}
			function hide() {
				viewer.hidden = true;
				card.classList.remove( 'is-viewing-poster' );
				posterViewer = null;
				if ( open.isConnected ) { open.focus(); }
			}
			open.addEventListener( 'click', show );
			back.addEventListener( 'click', hide );
		}

		function openDialog( event ) {
			event = event._original || event;
			if ( ! dialog || ! dialogContent ) {
				return;
			}

			dialogReturnFocus = document.activeElement;

			dialogContent.textContent = '';
			posterViewer = null;
			( dialog.querySelector( '[role="dialog"]' ) || dialog ).classList.remove( 'is-viewing-poster' );

			var title = el( 'h3', 'leagueflow-calendar__dialog-title', 'match' === event.source ? event.home + ' vs ' + event.away : event.title );
			title.id = dialog.querySelector( '[role="dialog"]' ).getAttribute( 'aria-labelledby' ) || 'leagueflow-calendar-dialog-title';
			dialogContent.appendChild( title );

			var badges = el( 'div', 'leagueflow-calendar__dialog-badges' );
			var sport = el( 'span', 'leagueflow-calendar__dialog-badge', event.sportLabel || '' );
			sport.style.setProperty( '--lf-cal-accent', eventAccent( event ) );
			badges.appendChild( sport );
			if ( event.leagueLevelLabel ) {
				badges.appendChild( el( 'span', 'leagueflow-calendar__dialog-badge', event.leagueLevelLabel ) );
			}
			badges.appendChild( el( 'span', 'leagueflow-calendar__dialog-badge', event.kindLabel || '' ) );

			if ( event.status && 'scheduled' !== event.status ) {
				badges.appendChild( el( 'span', 'leagueflow-calendar__dialog-badge is-' + event.status, event.statusLabel || event.status ) );
			}

			dialogContent.appendChild( badges );
			addPoster( event );

			var details = el( 'dl', 'leagueflow-calendar__dialog-details' );

			function addDetail( label, value ) {
				if ( ! value ) {
					return;
				}

				details.appendChild( el( 'dt', null, label ) );
				details.appendChild( el( 'dd', null, value ) );
			}

			var dateLabel = formatDateKey( event.day, monthNames, weekdays );
			if ( dayKeyFromDate( event.endDate ) !== event.day ) { dateLabel += ' – ' + formatDateKey( dayKeyFromDate( event.endDate ), monthNames, weekdays ); }
			addDetail( strings.date || 'Date', dateLabel );
			addDetail( 'Time', event.time + ' - ' + event.endTime );
			addDetail( 'Venue', event.venue );
			addDetail( strings.level || 'Level', event.leagueLevelLabel );
			addDetail( 'Competition', event.competition );
			addDetail( 'Season', event.season );
			if ( 'match' === event.source ) { addDetail( strings.score || 'Score', event.scoreline ); }
			if ( false !== config.showCost ) { addDetail( strings.cost || 'Cost', event.cost ); }

			if ( event.registrationRequired ) {
				addDetail( 'Registration', strings.registration || 'Registration required' );
			}

			dialogContent.appendChild( details );

			if ( event.description ) {
				dialogContent.appendChild( el( 'p', 'leagueflow-calendar__dialog-description', event.description ) );
			}

			var actions = el( 'div', 'leagueflow-calendar__dialog-actions' );

			if ( event.registrationUrl ) {
				var register = el( 'a', 'leagueflow-calendar__dialog-action', strings.register || 'Register' );
				register.href = event.registrationUrl;
				register.target = '_blank';
				register.rel = 'noopener noreferrer';
				actions.appendChild( register );
			}

			if ( event.url ) {
				var detailsLink = el( 'a', 'leagueflow-calendar__dialog-action', strings.details || 'Details' );
				detailsLink.href = event.url;
				actions.appendChild( detailsLink );
			}

			var google = el( 'a', 'leagueflow-calendar__dialog-action', strings.addGoogle || 'Add to Google Calendar' );
			google.href = googleCalendarUrl( event );
			google.target = '_blank';
			google.rel = 'noopener noreferrer';
			actions.appendChild( google );

			var outlook = el( 'a', 'leagueflow-calendar__dialog-action', strings.addOutlook || 'Add to Outlook' );
			var outlookUrl = new URL( 'https://outlook.live.com/calendar/0/deeplink/compose' );
			outlookUrl.searchParams.set( 'path', '/calendar/action/compose' );
			outlookUrl.searchParams.set( 'rru', 'addevent' );
			outlookUrl.searchParams.set( 'subject', event.title );
			outlookUrl.searchParams.set( 'startdt', event.startInstant.toISOString() );
			outlookUrl.searchParams.set( 'enddt', event.endInstant.toISOString() );
			outlookUrl.searchParams.set( 'location', event.venue || '' );
			outlookUrl.searchParams.set( 'body', [ event.description, event.url ].filter( Boolean ).join( '\n\n' ) );
			outlook.href = outlookUrl.toString(); outlook.target = '_blank'; outlook.rel = 'noopener noreferrer';
			actions.appendChild( outlook );

			var apple = el( 'a', 'leagueflow-calendar__dialog-action', strings.addApple || strings.downloadIcs || 'Add to your Apple Calendar' );
			apple.href = icsUrl( event );
			apple.download = cleanFilename( event.title ) + '.ics';
			actions.appendChild( apple );

			dialogContent.appendChild( actions );

			dialog.hidden = false;
			document.documentElement.classList.add( 'leagueflow-calendar-dialog-open' );

			var closeControl = dialog.querySelector( '.leagueflow-calendar__dialog-close' );

			if ( closeControl ) {
				closeControl.focus();
			}
		}

		function closeDialog() {
			if ( ! dialog || dialog.hidden ) {
				return;
			}

			dialog.hidden = true;
			document.documentElement.classList.remove( 'leagueflow-calendar-dialog-open' );
			posterViewer = null;

			if ( dialogReturnFocus && dialogReturnFocus.isConnected && dialogReturnFocus.focus ) {
				dialogReturnFocus.focus();
			}

			dialogReturnFocus = null;
		}

		function googleCalendarUrl( event ) {
			var url = new URL( 'https://calendar.google.com/calendar/render' );
			url.searchParams.set( 'action', 'TEMPLATE' );
			url.searchParams.set( 'text', 'match' === event.source ? event.home + ' vs ' + event.away : event.title );
			url.searchParams.set( 'dates', formatIcsDate( event.startInstant ) + '/' + formatIcsDate( event.endInstant ) );
			url.searchParams.set( 'details', event.description || event.url || '' );

			if ( event.venue ) {
				url.searchParams.set( 'location', event.venue );
			}

			return url.toString();
		}

		function icsUrl( event ) {
			var title = 'match' === event.source ? event.home + ' vs ' + event.away : event.title;
			var lines = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//LeagueFlow//Sports Calendar//EN',
				'BEGIN:VEVENT',
				'UID:' + event.id + '@leagueflow',
				'DTSTART:' + formatIcsDate( event.startInstant ),
				'DTEND:' + formatIcsDate( event.endInstant ),
				'SUMMARY:' + escapeIcsText( title ),
				'DESCRIPTION:' + escapeIcsText( event.description || event.url || '' ),
				event.venue ? 'LOCATION:' + escapeIcsText( event.venue ) : '',
				event.url ? 'URL:' + escapeIcsText( event.url ) : '',
				'STATUS:CONFIRMED',
				'END:VEVENT',
				'END:VCALENDAR'
			].filter( Boolean );

			return 'data:text/calendar;charset=utf8,' + encodeURIComponent( lines.join( '\r\n' ) );
		}

		var pageButton = root.querySelector( '[data-calendar-load-page]' );
		var pageStatus = root.querySelector( '[data-calendar-progress]' );
		var pageBox = root.querySelector( '[data-calendar-pagination]' );
		var pagination = data.pagination || {};
		var loadingPage = false;
		var pageError = '';
		var failedPage = null;
		var requestVersion = 0;
		var pageController = null;
		var activeScope = config.rangeLoading ? Object.assign( {}, config.calendarRange || {}, { sport: '', type: '', search: '' } ) : {};
		var activeScopeKey = JSON.stringify( activeScope );
		var knownSports = {};
		var knownTypes = {};
		( data.sports || [] ).forEach( function ( item ) { knownSports[ item.slug ] = item.label; } );
		( data.types || [] ).forEach( function ( item ) { knownTypes[ item.slug ] = item.label; } );

		function visibleScope() {
			var start = new Date( state.date.getFullYear(), state.date.getMonth(), 1 );
			var end = new Date( state.date.getFullYear(), state.date.getMonth() + 1, 0 );
			if ( 'week' === state.view && ! isMobileCalendar() ) { start = startOfWeekDate( state.date, startOfWeek ); end = addDays( start, 6 ); }
			if ( 'day' === state.view ) { start = state.date; end = state.date; }
			if ( 'list' === state.view ) { start = today; end = null; }
			return { start_date: dayKeyFromDate( start ), end_date: end ? dayKeyFromDate( end ) : '', sport: state.sport, type: state.type, search: state.search.trim() };
		}

		function ensureVisibleRange() {
			if ( ! config.rangeLoading || ! config.calendarEndpoint ) { return; }
			var scope = visibleScope();
			if ( JSON.stringify( scope ) !== activeScopeKey ) { loadCalendarPage( scope, 1, true ); }
			else if ( state.view !== 'list' && pagination.nextPage && ! loadingPage && ! failedPage ) {
				// Complete the visible range in bounded pages. Lists remain
				// incremental, but a month must not omit its later fixtures.
				loadCalendarPage( scope, pagination.nextPage, false );
			}
		}

		function showPageProgress( message ) {
			var text = message || ( strings.batchProgress || '%1$d of %2$d scheduled events loaded.' ).replace( '%1$d', events.length ).replace( '%2$d', pagination.total || 0 );
			if ( loadingPage ) { text = strings.rangeLoading || 'Loading this schedule…'; }
			else if ( config.rangeLoading && pagination.nextPage && ! message ) { text += ' ' + ( strings.rangePartial || 'More events exist in this range. Load the next page to see them.' ); }
			if ( pageStatus ) { pageStatus.textContent = text; }
			if ( pageBox ) { pageBox.hidden = ! ( pagination.nextPage || loadingPage || message ); }
			if ( pageButton ) {
				pageButton.disabled = loadingPage;
				pageButton.hidden = config.rangeLoading && 'list' === state.view && ! failedPage;
				pageButton.textContent = failedPage ? ( strings.retry || 'Retry loading schedule' ) : ( strings.loadPage || 'Load next schedule page' );
			}
			root.setAttribute( 'aria-busy', loadingPage ? 'true' : 'false' );
		}

		function refreshLoadedFilters( selector, attribute, labelField, allLabel, selected ) {
			var group = root.querySelector( selector );
			if ( ! group ) { return; }
			var choices = {};
			var known = attribute === 'sport' ? knownSports : knownTypes;
			if ( config.rangeLoading ) { Object.keys( known ).forEach( function ( key ) { choices[ key ] = { label: known[ key ], count: 0 }; } ); }
			events.forEach( function ( event ) {
				var key = event[ attribute ];
				if ( key ) {
					if ( ! choices[ key ] ) { choices[ key ] = { label: event[ labelField ] || key, count: 0 }; }
					known[ key ] = choices[ key ].label;
					choices[ key ].count++;
				}
			} );
			group.textContent = '';
			[ '' ].concat( Object.keys( choices ).sort() ).forEach( function ( key ) {
				var control = button( 'leagueflow-calendar__chip', key ? choices[ key ].label + ( config.rangeLoading ? '' : ' (' + choices[ key ].count + ')' ) : allLabel );
				control.dataset[ attribute === 'sport' ? 'sport' : 'type' ] = key;
				control.classList.toggle( 'is-active', selected === key );
				control.setAttribute( 'aria-pressed', selected === key ? 'true' : 'false' );
				group.appendChild( control );
			} );
			group.hidden = Object.keys( choices ).length <= 1;
		}

		async function loadCalendarPage( scope, page, replace ) {
			if ( loadingPage && ! replace ) { return; }
			if ( pageController ) { pageController.abort(); }
			pageController = window.AbortController ? new window.AbortController() : null;
			var version = ++requestVersion;
			activeScope = scope;
			activeScopeKey = JSON.stringify( scope );
			loadingPage = true;
			pageError = '';
			failedPage = null;
			if ( replace ) { events = []; pagination = { perPage: pagination.perPage || 100, nextPage: null, total: 0 }; }
			showPageProgress();
			try {
				var pageUrl = new URL( document.baseURI );
				var endpoint = new URL( config.calendarEndpoint, pageUrl );
				if ( endpoint.origin !== pageUrl.origin ) { throw new Error( 'Calendar endpoint must be on this site.' ); }
				Object.keys( config.calendarFilters || {} ).forEach( function ( key ) {
					var value = config.calendarFilters[ key ];
					if ( value !== null && value !== '' ) { endpoint.searchParams.set( key, value === false ? '0' : String( value ) ); }
				} );
				Object.keys( scope ).forEach( function ( key ) {
					// Visitor filters refine a block's published scope; they must not
					// replace an author's fixed sport or event type.
					var fixed = config.calendarFilters || {};
					if ( key === 'sport' && fixed.sport || key === 'type' && fixed.event_type ) { return; }
					if ( scope[ key ] !== '' ) { endpoint.searchParams.set( key, scope[ key ] ); }
				} );
				endpoint.searchParams.set( 'per_page', Math.min( 100, pagination.perPage || 100 ) );
				endpoint.searchParams.set( 'page', page );
				var options = { credentials: 'omit', cache: 'no-store', headers: { Accept: 'application/json' } };
				if ( pageController ) { options.signal = pageController.signal; }
				var response = await fetch( endpoint.toString(), options );
				if ( ! response.ok ) { throw new Error( 'Calendar request failed.' ); }
				var result = await response.json();
				if ( version !== requestVersion ) { return; }
				if ( ! Array.isArray( result.events ) || result.events.length > 100 || ! result.pagination || result.pagination.currentPage !== page ) { throw new Error( 'Invalid calendar page.' ); }
				var nextPage = result.pagination.nextPage;
				if ( nextPage !== null && ( ! Number.isInteger( nextPage ) || nextPage !== page + 1 || nextPage > 10000 ) ) { throw new Error( 'Invalid next page.' ); }
				var ids = new Set( events.map( function ( event ) { return event.id; } ) );
				events = events.concat( normalizeEvents( result.events ).filter( function ( event ) {
					if ( ids.has( event.id ) ) { return false; }
					ids.add( event.id ); return true;
				} ) ).sort( function ( a, b ) { return ( 'desc' === config.eventSortOrder ? -1 : 1 ) * ( a.startDate - b.startDate || String( a.id ).localeCompare( String( b.id ) ) ); } );
				( result.sports || [] ).forEach( function ( sport ) { sportColors[ sport.slug ] = sport.color; } );
				pagination.nextPage = nextPage;
				pagination.total = result.total;
				loadingPage = false;
				refreshLoadedFilters( '[data-calendar-sport-filters]', 'sport', 'sportLabel', strings.allSports || 'All sports', state.sport );
				refreshLoadedFilters( '[data-calendar-type-filters]', 'kind', 'kindLabel', strings.allTypes || 'All types', state.type );
				render();
				showPageProgress();
			} catch ( error ) {
				if ( version !== requestVersion ) { return; }
				loadingPage = false;
				failedPage = { scope: scope, page: page, replace: replace };
				pageError = config.rangeLoading ? ( strings.rangeError || 'This schedule could not be loaded. Please retry.' ) : ( strings.batchError || 'Could not load the next schedule page. Please retry.' );
				render(); showPageProgress( pageError );
			}
		}

		if ( pageButton && config.calendarEndpoint ) {
			pageButton.addEventListener( 'click', function () {
				if ( loadingPage ) { return; }
				if ( failedPage ) { loadCalendarPage( failedPage.scope, failedPage.page, failedPage.replace ); }
				else if ( pagination.nextPage ) { loadCalendarPage( activeScope, pagination.nextPage, false ); }
			} );
			showPageProgress();
		}

		prevBtn.addEventListener( 'click', function () {
			shiftRange( -1 );
		} );

		nextBtn.addEventListener( 'click', function () {
			shiftRange( 1 );
		} );

		todayBtn.addEventListener( 'click', function () {
			state.date = new Date( today.getFullYear(), today.getMonth(), today.getDate() );
			state.selectedDay = 'day' === state.view ? dayKeyFromDate( state.date ) : null;
			render();
		} );

		clearBtn.addEventListener( 'click', function () {
			state.selectedDay = null;
			state.fullMonth = true;
			render();
		} );

		viewButtons.forEach( function ( control ) {
			control.addEventListener( 'click', function () {
				state.view = this.dataset.calendarView || 'month';
				state.fullMonth = false;
				state.selectedDay = 'day' === state.view ? dayKeyFromDate( state.date ) : null;
				state.listCount = listInitial;
				render();
			} );
		} );

		root.addEventListener( 'click', function ( event ) {
			var chip = event.target.closest( '[data-sport], [data-type]' );
			if ( ! chip || ! root.contains( chip ) ) { return; }
			var isSport = chip.hasAttribute( 'data-sport' );
			state[ isSport ? 'sport' : 'type' ] = chip.dataset[ isSport ? 'sport' : 'type' ] || '';
			state.selectedDay = null;
			state.listCount = listInitial;
			setChipState( root.querySelectorAll( isSport ? '[data-sport]' : '[data-type]' ), isSport ? 'sport' : 'type', state[ isSport ? 'sport' : 'type' ] );
			render();
		} );

		var searchTimer = null;
		if ( panelClose ) { panelClose.addEventListener( 'click', function () { var day = state.selectedDay; state.selectedDay = null; state.fullMonth = true; render(); var control = root.querySelector( '[data-day="' + day + '"]' ); if ( control ) { control.focus(); } } ); }
		if ( jumpInput ) { jumpInput.addEventListener( 'change', function () { if ( /^\d{4}-\d{2}-\d{2}$/.test( this.value ) ) { state.date = parseDay( this.value ); state.selectedDay = 'day' === state.view ? this.value : null; state.fullMonth = false; render(); } } ); }
		if ( searchInput ) {
			searchInput.addEventListener( 'input', function () {
				var value = this.value || '';
				window.clearTimeout( searchTimer );
				var applySearch = function () { state.search = value; state.selectedDay = null; state.listCount = listInitial; render(); };
				if ( config.rangeLoading ) { searchTimer = window.setTimeout( applySearch, 250 ); } else { applySearch(); }
			} );
		}

		if ( dialog ) {
			dialog.querySelectorAll( '[data-calendar-dialog-close]' ).forEach( function ( close ) {
				close.addEventListener( 'click', closeDialog );
			} );
		}

		var observedWidth = 0;
		var resizeObserver = window.ResizeObserver ? new window.ResizeObserver( function ( entries ) {
			var width = Math.round( entries[0].contentRect.width );
			if ( width !== observedWidth ) { observedWidth = width; render(); }
		} ) : null;
		if ( resizeObserver ) { resizeObserver.observe( root ); }
		if ( mobileQuery ) {
			if ( mobileQuery.addEventListener ) {
				mobileQuery.addEventListener( 'change', render );
			} else if ( mobileQuery.addListener ) {
				mobileQuery.addListener( render );
			}
		}

		function onKeydown( event ) {
			if ( 'Escape' === event.key ) {
				if ( posterViewer && dialog && ! dialog.hidden ) { posterViewer(); return; }
				if ( ( ! dialog || dialog.hidden ) && panelClose && ! panelClose.hidden && root.contains( document.activeElement ) ) { panelClose.click(); }
				closeDialog();
				return;
			}

			if ( 'Tab' === event.key && dialog && ! dialog.hidden ) {
				trapDialogFocus( event );
			}
		}
		document.addEventListener( 'keydown', onKeydown );
		root.leagueflowCalendarCleanup = function () {
			document.removeEventListener( 'keydown', onKeydown );
			if ( resizeObserver ) { resizeObserver.disconnect(); }
			if ( mobileQuery && mobileQuery.removeEventListener ) { mobileQuery.removeEventListener( 'change', render ); }
			else if ( mobileQuery && mobileQuery.removeListener ) { mobileQuery.removeListener( render ); }
			if ( pageController ) { pageController.abort(); }
			window.clearTimeout( searchTimer ); ++requestVersion; closeDialog(); delete root.dataset.calendarInitialized;
		};

		function trapDialogFocus( event ) {
			var card = dialog.querySelector( '[role="dialog"]' ) || dialog;
			var focusable = Array.prototype.filter.call(
				card.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])' ),
				function ( node ) {
					return node.offsetWidth > 0 || node.offsetHeight > 0 || node.getClientRects().length > 0;
				}
			);

			if ( ! focusable.length ) {
				event.preventDefault();
				return;
			}

			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];
			var active = document.activeElement;

			if ( event.shiftKey && ( active === first || ! card.contains( active ) ) ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && ( active === last || ! card.contains( active ) ) ) {
				event.preventDefault();
				first.focus();
			}
		}

		if ( 'day' === state.view ) {
			state.selectedDay = dayKeyFromDate( state.date );
		}

		setChipState( root.querySelectorAll( '[data-sport]' ), 'sport', state.sport );
		setChipState( root.querySelectorAll( '[data-type]' ), 'type', state.type );
		render();
	}

	function onReady( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	window.LeagueFlowCalendar = { init: init, destroy: function ( root ) { if ( root.leagueflowCalendarCleanup ) { root.leagueflowCalendarCleanup(); } } };
	onReady( function () {
		document.querySelectorAll( '[data-leagueflow-calendar]' ).forEach( init );
	} );
} )();
