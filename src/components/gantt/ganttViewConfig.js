import {
	addGanttDays,
	formatGanttDate,
	parseGanttDate,
} from '../../ganttModel.mjs';

export const GANTT_LABEL_WIDTH = 320;
export const GANTT_HEADER_HEIGHT = 52;
export const GANTT_ROW_HEIGHT = 46;

export const GANTT_ZOOM_LEVELS = {
	week: { label: 'Week', dayWidth: 30, padding: 7 },
	month: { label: 'Month', dayWidth: 14, padding: 14 },
	quarter: { label: 'Quarter', dayWidth: 6, padding: 31 },
};

const utcDateFormatter = ( options ) =>
	new Intl.DateTimeFormat( undefined, { ...options, timeZone: 'UTC' } );

export const compactGanttDate = utcDateFormatter( {
	month: 'short',
	day: 'numeric',
} );
const dayDate = utcDateFormatter( { day: 'numeric', weekday: 'short' } );
const monthDate = utcDateFormatter( { month: 'short', year: 'numeric' } );

export const getLocalGanttToday = () => {
	const now = new Date();
	return parseGanttDate(
		[
			now.getFullYear(),
			String( now.getMonth() + 1 ).padStart( 2, '0' ),
			String( now.getDate() ).padStart( 2, '0' ),
		].join( '-' )
	);
};

export const getGanttStatusLabel = ( status ) => {
	if ( status === 'in-progress' ) {
		return 'In progress';
	}
	if ( status === 'done' ) {
		return 'Done';
	}
	if ( status === 'restricted' ) {
		return 'Restricted';
	}
	return 'Pending';
};

export const buildGanttHeaderPeriods = ( start, dayCount, zoom ) => {
	const periods = [];

	if ( zoom === 'week' ) {
		for ( let index = 0; index < dayCount; index += 1 ) {
			const date = addGanttDays( start, index );
			periods.push( {
				key: formatGanttDate( date ),
				label: dayDate.format( date ),
				days: 1,
				isWeekend: date.getUTCDay() === 0 || date.getUTCDay() === 6,
			} );
		}
		return periods;
	}

	let index = 0;
	while ( index < dayCount ) {
		const date = addGanttDays( start, index );
		const month = date.getUTCMonth();
		const year = date.getUTCFullYear();
		let days = 1;
		while ( index + days < dayCount ) {
			const next = addGanttDays( start, index + days );
			if (
				next.getUTCMonth() !== month ||
				next.getUTCFullYear() !== year
			) {
				break;
			}
			days += 1;
		}
		periods.push( {
			key: `${ year }-${ month }`,
			label: monthDate.format( date ),
			days,
		} );
		index += days;
	}
	return periods;
};
