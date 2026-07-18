/**
 * Escapes HTML characters to prevent XSS.
 * @param {string} text
 * @return {string} The escaped string.
 */
export function escapeHtml( text ) {
	if ( ! text ) {
		return '';
	}
	const div = document.createElement( 'div' );
	div.textContent = text;
	return div.innerHTML;
}

/**
 * Checks if a date string is within a start and end range.
 * @param {string} dateStr  YYYY-MM-DD
 * @param {string} startStr YYYY-MM-DD
 * @param {string} endStr   YYYY-MM-DD
 * @return {boolean} Whether the date is inside the range.
 */
export function isDateInRange( dateStr, startStr, endStr ) {
	if ( ! dateStr ) {
		return false;
	}

	const date = parseDate( dateStr );
	const start = parseDate( startStr );
	const end = parseDate( endStr );

	return date >= start && date <= end;
}

export function parseDate( dateStr ) {
	if ( ! dateStr ) {
		return null;
	}
	// Handle YYYY-MM-DD to avoid UTC issues
	if (
		typeof dateStr === 'string' &&
		/^\d{4}-\d{2}-\d{2}$/.test( dateStr )
	) {
		const [ year, month, day ] = dateStr.split( '-' ).map( Number );
		return new Date( year, month - 1, day );
	}
	return new Date( dateStr );
}

export function wouldCreateTaskCycle( tasks, taskId, proposedParentId ) {
	const normalizedTaskId = Number( taskId );
	let currentId = Number( proposedParentId );
	const parents = new Map(
		( tasks || [] ).map( ( task ) => [
			Number( task.id ),
			Number( task.parent_task_id ) || 0,
		] )
	);
	const visited = new Set();

	while ( currentId > 0 && ! visited.has( currentId ) ) {
		if ( currentId === normalizedTaskId ) {
			return true;
		}
		visited.add( currentId );
		currentId = parents.get( currentId ) || 0;
	}

	return false;
}

export function parseUtcDateTime( value ) {
	if ( ! value ) {
		return null;
	}
	if ( value instanceof Date ) {
		return value;
	}
	if (
		typeof value === 'string' &&
		/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test( value )
	) {
		return new Date( `${ value.replace( ' ', 'T' ) }Z` );
	}
	return new Date( value );
}

export function formatDate( date ) {
	if ( ! date ) {
		return '';
	}
	const d = new Date( date );
	const month = String( d.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( d.getDate() ).padStart( 2, '0' );
	return `${ d.getFullYear() }-${ month }-${ day }`;
}

export function formatDisplayDate( date ) {
	const d = new Date( date );
	const options = {
		weekday: 'long',
		year: 'numeric',
		month: 'long',
		day: 'numeric',
	};
	return d.toLocaleDateString( undefined, options );
}

export function getMonday( date ) {
	const d = new Date( date );
	const day = d.getDay();
	const diff = d.getDate() - day + ( day === 0 ? -6 : 1 ); // Adjust for Sunday
	return new Date( d.setDate( diff ) );
}

export function calculateNextRecurrenceDate( currentDate, template ) {
	try {
		const tempDate = new Date( currentDate );
		const interval = parseInt( template.recurrence_interval, 10 ) || 1;
		let nextOccurrenceDate;

		if (
			template.recurrence_frequency === 'weekly' ||
			template.recurrence_frequency === 'bi-weekly'
		) {
			tempDate.setDate( tempDate.getDate() + 7 * interval );
			nextOccurrenceDate = tempDate;
		} else if ( template.recurrence_frequency === 'monthly' ) {
			tempDate.setMonth( tempDate.getMonth() + interval );
			nextOccurrenceDate = tempDate;
		} else if ( template.recurrence_frequency === 'custom_weekly' ) {
			if ( ! template.recurrence_days ) {
				return null;
			}
			const daysOfWeek = template.recurrence_days
				.split( ',' )
				.map( Number )
				.sort();
			const currentDayOfWeek =
				tempDate.getDay() === 0 ? 7 : tempDate.getDay(); // 1=Mon..7=Sun

			let nextDayFound = false;
			// Find next day in same week
			for ( const day of daysOfWeek ) {
				if ( day > currentDayOfWeek ) {
					tempDate.setDate(
						tempDate.getDate() + ( day - currentDayOfWeek )
					);
					nextDayFound = true;
					break;
				}
			}
			// If not found, find first day in next week
			if ( ! nextDayFound ) {
				const firstDay = daysOfWeek[ 0 ];
				const daysToAdd = 7 - currentDayOfWeek + firstDay;
				tempDate.setDate( tempDate.getDate() + daysToAdd );
			}
			nextOccurrenceDate = tempDate;
		} else {
			return null; // Unknown frequency
		}
		return nextOccurrenceDate;
	} catch {
		return null;
	}
}

export function generateFutureOccurrences(
	templates,
	viewStartDate,
	viewEndDate
) {
	const virtualTasks = [];
	const limitDate = new Date( viewStartDate );
	limitDate.setFullYear( limitDate.getFullYear() + 1 ); // 1-year limit for indefinite tasks

	templates.forEach( ( template ) => {
		if ( ! template.start_date ) {
			return;
		}

		const recurrenceEndDate = template.recurrence_ends_on
			? parseDate( template.recurrence_ends_on )
			: limitDate;
		let currentDate = parseDate( template.start_date );

		if ( ! currentDate ) {
			return;
		}
		currentDate.setHours( 0, 0, 0, 0 );

		// Loop to generate occurrences until we are past the view or recurrence end date
		while (
			currentDate <= viewEndDate &&
			currentDate <= recurrenceEndDate
		) {
			// If the current date is within the view, add it as a virtual task
			if ( currentDate >= viewStartDate ) {
				const deadlineDate = new Date( currentDate );
				const deadlineDays = parseInt(
					template.deadline_days_after_start,
					10
				);
				if ( ! isNaN( deadlineDays ) ) {
					deadlineDate.setDate(
						deadlineDate.getDate() + deadlineDays
					);
				}

				virtualTasks.push( {
					...template, // Copy properties from template
					id: `virtual-${ template.id }-${ formatDate(
						currentDate
					) }`,
					start_date: formatDate( currentDate ),
					deadline: formatDate( deadlineDate ),
					is_recurring: false, // It's an instance, not a template itself in this view
					is_virtual: true,
					parentTemplateId: template.id,
				} );
			}

			const nextOccurrenceDate = calculateNextRecurrenceDate(
				currentDate,
				template
			);

			if ( ! nextOccurrenceDate ) {
				break; // Stop if calculation fails
			}

			currentDate = nextOccurrenceDate; // Move to the next calculated date for the loop
		}
	} );

	return virtualTasks;
}

export function generateGCalUrl( task ) {
	if ( ! task.deadline ) {
		return null;
	}

	// Dates must be in YYYYMMDD format for all-day events.
	const startObj = new Date( task.deadline + 'T00:00:00' );
	const endObj = new Date( startObj );
	endObj.setDate( startObj.getDate() + 1 );

	const formatDateForGCal = ( date ) => {
		const year = date.getFullYear();
		const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
		const day = String( date.getDate() ).padStart( 2, '0' );
		return `${ year }${ month }${ day }`;
	};

	const gcalStartDate = formatDateForGCal( startObj );
	const gcalEndDate = formatDateForGCal( endObj );

	// Simple strip tags for description
	const descriptionText = task.description
		? task.description.replace( /<[^>]*>?/gm, '' )
		: '';

	const gcalUrl = new URL( 'https://www.google.com/calendar/render' );
	gcalUrl.searchParams.set( 'action', 'TEMPLATE' );
	gcalUrl.searchParams.set( 'text', task.name );
	gcalUrl.searchParams.set( 'dates', `${ gcalStartDate }/${ gcalEndDate }` );
	gcalUrl.searchParams.set( 'details', descriptionText );

	return gcalUrl.toString();
}
