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
