import React from 'react';
import {
	formatWorkLogRange,
	WORK_LOG_RANGE_PRESETS,
} from '../../workLogUiModel.mjs';
import Icon from '../Icon';
import WorkLogMenu from './WorkLogMenu';

const WorkLogToolbar = ( {
	startDate,
	endDate,
	preset,
	onPresetChange,
	onStartDateChange,
	onEndDateChange,
	onExportCsv,
	onPrint,
	canExport,
	exportBusy = false,
} ) => {
	const presetLabel =
		WORK_LOG_RANGE_PRESETS.find( ( option ) => option.value === preset )
			?.label || 'Date range';

	return (
		<div className="pandat69-work-toolbar" aria-label="Work log controls">
			<div className="pandat69-work-toolbar-summary">
				<span className="pandat69-work-toolbar-icon" aria-hidden="true">
					<Icon name="calendar" size={ 17 } />
				</span>
				<span>
					<small>Showing</small>
					<strong>{ formatWorkLogRange( startDate, endDate ) }</strong>
				</span>
			</div>

			<div className="pandat69-work-toolbar-actions">
				<WorkLogMenu label={ presetLabel } icon="calendar">
					{ ( choose ) => (
						<>
							<span className="pandat69-work-menu-label">Date range</span>
							{ WORK_LOG_RANGE_PRESETS.map( ( option ) => (
								<button
									type="button"
									role="menuitem"
									key={ option.value }
									className={
										option.value === preset ? 'is-selected' : ''
									}
									onClick={ () =>
										choose( () => onPresetChange( option.value ) )
									}
								>
									<span>{ option.label }</span>
									{ option.value === preset && (
										<Icon name="check" size={ 15 } />
									) }
								</button>
							) ) }
						</>
					) }
				</WorkLogMenu>

				<WorkLogMenu
					label={ exportBusy ? 'Preparing…' : 'Export' }
					icon="download"
					disabled={ exportBusy }
					align="end"
				>
					{ ( choose ) => (
						<>
							<span className="pandat69-work-menu-label">Export this period</span>
							<button
								type="button"
								role="menuitem"
								disabled={ ! canExport }
								onClick={ () => choose( onExportCsv ) }
							>
								<Icon name="download" size={ 17 } />
								<span>
									<strong>Download CSV</strong>
									<small>All matching entries, not just loaded ones</small>
								</span>
							</button>
							<button
								type="button"
								role="menuitem"
								onClick={ () => choose( onPrint ) }
							>
								<Icon name="printer" size={ 17 } />
								<span>
									<strong>Print current view</strong>
									<small>Summary and entries currently on screen</small>
								</span>
							</button>
						</>
					) }
				</WorkLogMenu>
			</div>

			{ preset === 'custom' && (
				<div className="pandat69-work-custom-range">
					<label>
						<span>From</span>
						<input
							type="date"
							value={ startDate }
							max={ endDate }
							onChange={ ( event ) =>
								onStartDateChange( event.target.value )
							}
						/>
					</label>
					<label>
						<span>To</span>
						<input
							type="date"
							value={ endDate }
							min={ startDate }
							onChange={ ( event ) =>
								onEndDateChange( event.target.value )
							}
						/>
					</label>
				</div>
			) }
		</div>
	);
};

export default WorkLogToolbar;
