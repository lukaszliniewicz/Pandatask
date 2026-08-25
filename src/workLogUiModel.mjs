export const WORK_LOG_RANGE_PRESETS = [
	{ value: 'last_7_days', label: 'Last 7 days' },
	{ value: 'this_week', label: 'This week' },
	{ value: 'this_month', label: 'This month' },
	{ value: 'last_30_days', label: 'Last 30 days' },
	{ value: 'custom', label: 'Custom dates' },
];

export const toLocalIsoDate = ( value ) => {
	const date = value instanceof Date ? value : new Date( value );
	const year = date.getFullYear();
	const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( date.getDate() ).padStart( 2, '0' );
	return `${ year }-${ month }-${ day }`;
};

const shiftDays = ( value, days ) => {
	const date = new Date( value );
	date.setHours( 12, 0, 0, 0 );
	date.setDate( date.getDate() + days );
	return date;
};

export const workLogRangeForPreset = ( preset, now = new Date() ) => {
	const today = new Date( now );
	today.setHours( 12, 0, 0, 0 );

	if ( preset === 'last_7_days' ) {
		return {
			startDate: toLocalIsoDate( shiftDays( today, -6 ) ),
			endDate: toLocalIsoDate( today ),
		};
	}

	if ( preset === 'this_week' ) {
		const daysSinceMonday = ( today.getDay() + 6 ) % 7;
		return {
			startDate: toLocalIsoDate( shiftDays( today, -daysSinceMonday ) ),
			endDate: toLocalIsoDate( today ),
		};
	}

	if ( preset === 'this_month' ) {
		return {
			startDate: toLocalIsoDate(
				new Date( today.getFullYear(), today.getMonth(), 1, 12 )
			),
			endDate: toLocalIsoDate( today ),
		};
	}

	return {
		startDate: toLocalIsoDate( shiftDays( today, -29 ) ),
		endDate: toLocalIsoDate( today ),
	};
};

export const formatWorkLogRange = ( startDate, endDate, locale ) => {
	const formatter = new Intl.DateTimeFormat( locale, {
		day: 'numeric',
		month: 'short',
		year:
			startDate.slice( 0, 4 ) === endDate.slice( 0, 4 )
				? undefined
				: 'numeric',
	} );
	const start = formatter.format( new Date( `${ startDate }T12:00:00` ) );
	const endFormatter = new Intl.DateTimeFormat( locale, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	} );
	return `${ start } – ${ endFormatter.format(
		new Date( `${ endDate }T12:00:00` )
	) }`;
};

export const csvCell = ( value ) => {
	const text = String( value ?? '' );
	const spreadsheetSafe = /^[=+\-@]/.test( text ) ? `'${ text }` : text;
	return `"${ spreadsheetSafe.replaceAll( '"', '""' ) }"`;
};

export const workLogCsv = ( rows ) =>
	`\uFEFF${ rows
		.map( ( row ) => row.map( csvCell ).join( ',' ) )
		.join( '\n' ) }`;
