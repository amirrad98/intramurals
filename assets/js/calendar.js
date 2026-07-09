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
		return event.day >= todayKey;
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
		var events = ( data.events || [] ).map( function ( event ) {
			event.startDate = new Date( event.start );
			event.endDate = new Date( event.end );
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
		var sportChips = root.querySelectorAll( '[data-sport]' );
		var typeChips = root.querySelectorAll( '[data-type]' );
		var dialog = root.querySelector( '[data-calendar-dialog]' );
		var dialogContent = root.querySelector( '[data-calendar-dialog-content]' );
		var mobileQuery = window.matchMedia ? window.matchMedia( '(max-width: 760px)' ) : null;

		if ( ! stage || ! panelList || ! rangeLabel ) {
			return;
		}

		var sportColors = {};

		sports.forEach( function ( sport ) {
			sportColors[ sport.slug ] = sport.color;
		} );

		var today = parseDay( config.today || dayKeyFromDate( new Date() ) );
		var initialDate = today;
		var initialView = config.initialView || 'month';
		var listInitial = parseInt( config.listInitial, 10 ) || 30;
		var listMore = parseInt( config.listMore, 10 ) || 15;

		if ( events.length && ! events.some( function ( event ) {
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
			return source.filter( function ( event ) {
				return event.day === key;
			} );
		}

		function eventsForMonth( date, source ) {
			var key = monthKey( date );
			return source.filter( function ( event ) {
				return monthKey( event.startDate ) === key;
			} );
		}

		function isMobileCalendar() {
			return !! ( mobileQuery && mobileQuery.matches );
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

			if ( mobile && 'week' === state.view ) {
				state.view = 'month';
				root.classList.remove( 'is-view-week' );
				root.classList.add( 'is-view-month' );
			}

			if ( mobile && 'month' === state.view && ! state.selectedDay ) {
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

			if ( event.cost ) {
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
				teams.appendChild( el( 'strong', 'leagueflow-calendar__event-score', event.scoreline ? event.scoreline : ( strings.vs || 'vs' ) ) );
				teams.appendChild( el( 'span', null, event.away ) );
				card.appendChild( teams );
			} else {
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

		function renderPanel( source ) {
			panelList.textContent = '';

			if ( state.selectedDay ) {
				panelTitle.textContent = sprintf( strings.eventsOn || 'Schedule on %s', formatDateKey( state.selectedDay, monthNames, weekdays ) );
				clearBtn.hidden = false;

				var selectedItems = eventsForDay( state.selectedDay, source );

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
					state.selectedDay = state.selectedDay === this.dataset.day ? null : this.dataset.day;
					state.date = parseDay( this.dataset.day );
					render();
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
			var wrap = el( 'div', 'leagueflow-calendar__list-view' );

			if ( ! keys.length ) {
				wrap.appendChild( el( 'p', 'leagueflow-calendar__empty leagueflow-calendar__empty--large', strings.noEventsList || 'No upcoming events match the current filters.' ) );
				stage.appendChild( wrap );
				return;
			}

			keys.forEach( function ( key ) {
				var group = el( 'section', 'leagueflow-calendar__list-group' );
				var header = el( 'div', 'leagueflow-calendar__list-date' );
				header.appendChild( el( 'h4', null, formatDateKey( key, monthNames, weekdays ) ) );
				header.appendChild( el( 'span', null, grouped[ key ].length + ' ' + ( 1 === grouped[ key ].length ? ( strings.event || 'event' ) : ( strings.eventsPlural || 'events' ) ) ) );
				group.appendChild( header );

				grouped[ key ].forEach( function ( event ) {
					group.appendChild( buildEventCard( event, { showDate: false } ) );
				} );

				wrap.appendChild( group );
			} );

			if ( upcoming.length > shown.length ) {
				var more = button( 'leagueflow-calendar__load-more', strings.loadMore || 'Load more events' );
				more.addEventListener( 'click', function () {
					state.listCount += listMore;
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
				min = Math.min( min, event.startDate.getHours() );
				max = Math.max( max, event.endDate.getHours() + ( event.endDate.getMinutes() > 0 ? 1 : 0 ) );
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
			var start = event.startDate.getHours() + event.startDate.getMinutes() / 60;
			var end = event.endDate.getHours() + event.endDate.getMinutes() / 60;
			var duration = Math.max( 0.5, end - start );

			return {
				top: ( start - hourStart ) * 72,
				height: Math.max( 42, duration * 72 )
			};
		}

		function buildTimedEventBlock( event, hourStart ) {
			var pos = eventBlockPosition( event, hourStart );
			var block = button( 'leagueflow-calendar__time-event' );
			block.style.setProperty( '--lf-cal-accent', eventAccent( event ) );
			block.style.top = pos.top + 'px';
			block.style.height = pos.height + 'px';
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

			weekItems = source.filter( function ( event ) {
				return event.startDate >= weekStart && event.startDate < addDays( weekStart, 7 );
			} );

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

				eventsForDay( dayKeyFromDate( date ), source ).forEach( function ( event ) {
					dayCol.appendChild( buildTimedEventBlock( event, hours.start ) );
				} );

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

			items.forEach( function ( event ) {
				dayCol.appendChild( buildTimedEventBlock( event, hours.start ) );
			} );

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
			root.classList.add( 'is-ready' );
		}

		function shiftRange( amount ) {
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

		function openDialog( event ) {
			if ( ! dialog || ! dialogContent ) {
				return;
			}

			dialogContent.textContent = '';

			var title = el( 'h3', 'leagueflow-calendar__dialog-title', 'match' === event.source ? event.home + ' vs ' + event.away : event.title );
			title.id = 'leagueflow-calendar-dialog-title';
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

			var details = el( 'dl', 'leagueflow-calendar__dialog-details' );

			function addDetail( label, value ) {
				if ( ! value ) {
					return;
				}

				details.appendChild( el( 'dt', null, label ) );
				details.appendChild( el( 'dd', null, value ) );
			}

			addDetail( strings.today || 'Date', formatDateKey( event.day, monthNames, weekdays ) );
			addDetail( 'Time', event.time + ' - ' + event.endTime );
			addDetail( 'Venue', event.venue );
			addDetail( strings.level || 'Level', event.leagueLevelLabel );
			addDetail( 'Competition', event.competition );
			addDetail( 'Season', event.season );
			addDetail( strings.cost || 'Cost', event.cost );

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

			var apple = el( 'a', 'leagueflow-calendar__dialog-action', strings.addApple || strings.downloadIcs || 'Add to your Apple Calendar' );
			apple.href = icsUrl( event );
			apple.download = cleanFilename( event.title ) + '.ics';
			actions.appendChild( apple );

			dialogContent.appendChild( actions );

			dialog.hidden = false;
			document.documentElement.classList.add( 'leagueflow-calendar-dialog-open' );
		}

		function closeDialog() {
			if ( ! dialog ) {
				return;
			}

			dialog.hidden = true;
			document.documentElement.classList.remove( 'leagueflow-calendar-dialog-open' );
		}

		function googleCalendarUrl( event ) {
			var url = new URL( 'https://calendar.google.com/calendar/render' );
			url.searchParams.set( 'action', 'TEMPLATE' );
			url.searchParams.set( 'text', 'match' === event.source ? event.home + ' vs ' + event.away : event.title );
			url.searchParams.set( 'dates', formatIcsDate( event.startDate ) + '/' + formatIcsDate( event.endDate ) );
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
				'DTSTART:' + formatIcsDate( event.startDate ),
				'DTEND:' + formatIcsDate( event.endDate ),
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
			render();
		} );

		viewButtons.forEach( function ( control ) {
			control.addEventListener( 'click', function () {
				state.view = this.dataset.calendarView || 'month';
				state.selectedDay = 'day' === state.view ? dayKeyFromDate( state.date ) : null;
				state.listCount = listInitial;
				render();
			} );
		} );

		sportChips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				state.sport = this.dataset.sport || '';
				state.selectedDay = null;
				state.listCount = listInitial;
				setChipState( sportChips, 'sport', state.sport );
				render();
			} );
		} );

		typeChips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				state.type = this.dataset.type || '';
				state.selectedDay = null;
				state.listCount = listInitial;
				setChipState( typeChips, 'type', state.type );
				render();
			} );
		} );

		if ( searchInput ) {
			searchInput.addEventListener( 'input', function () {
				state.search = this.value || '';
				state.selectedDay = null;
				state.listCount = listInitial;
				render();
			} );
		}

		if ( dialog ) {
			dialog.querySelectorAll( '[data-calendar-dialog-close]' ).forEach( function ( close ) {
				close.addEventListener( 'click', closeDialog );
			} );
		}

		if ( mobileQuery ) {
			if ( mobileQuery.addEventListener ) {
				mobileQuery.addEventListener( 'change', render );
			} else if ( mobileQuery.addListener ) {
				mobileQuery.addListener( render );
			}
		}

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				closeDialog();
			}
		} );

		if ( 'day' === state.view ) {
			state.selectedDay = dayKeyFromDate( state.date );
		}

		setChipState( sportChips, 'sport', state.sport );
		setChipState( typeChips, 'type', state.type );
		render();
	}

	function onReady( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	onReady( function () {
		document.querySelectorAll( '[data-leagueflow-calendar]' ).forEach( init );
	} );
} )();
