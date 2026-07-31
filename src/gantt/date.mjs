const DAY_IN_MS = 24 * 60 * 60 * 1000;

export const MAX_GANTT_TIMELINE_DAYS = 1096;

export const minGanttDate = ( values ) => {
	const dates = values.filter( Boolean );
	return dates.length ? new Date( Math.min( ...dates.map( Number ) ) ) : null;
};

export const maxGanttDate = ( values ) => {
	const dates = values.filter( Boolean );
	return dates.length ? new Date( Math.max( ...dates.map( Number ) ) ) : null;
};

export const parseGanttDate = ( value ) => {
	if ( ! value ) {
		return null;
	}

	if ( value instanceof Date ) {
		return Number.isNaN( value.getTime() )
			? null
			: new Date(
					Date.UTC(
						value.getUTCFullYear(),
						value.getUTCMonth(),
						value.getUTCDate()
					)
			  );
	}

	const match = String( value ).match( /^(\d{4})-(\d{2})-(\d{2})$/ );
	if ( ! match ) {
		return null;
	}

	const year = Number( match[ 1 ] );
	const month = Number( match[ 2 ] );
	const day = Number( match[ 3 ] );
	if ( year < 1 || month < 1 || month > 12 || day < 1 || day > 31 ) {
		return null;
	}

	const date = new Date( 0 );
	date.setUTCHours( 0, 0, 0, 0 );
	date.setUTCFullYear( year, month - 1, day );
	if (
		Number.isNaN( date.getTime() ) ||
		date.getUTCFullYear() !== year ||
		date.getUTCMonth() !== month - 1 ||
		date.getUTCDate() !== day
	) {
		return null;
	}

	return date;
};

export const formatGanttDate = ( date ) => {
	if ( ! date ) {
		return '';
	}
	return [
		date.getUTCFullYear(),
		String( date.getUTCMonth() + 1 ).padStart( 2, '0' ),
		String( date.getUTCDate() ).padStart( 2, '0' ),
	].join( '-' );
};

export const addGanttDays = ( date, days ) =>
	new Date( date.getTime() + days * DAY_IN_MS );

export const ganttDayDifference = ( start, end ) =>
	Math.round( ( end.getTime() - start.getTime() ) / DAY_IN_MS );
