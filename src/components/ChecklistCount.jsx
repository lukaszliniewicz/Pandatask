import React from 'react';
import Icon from './Icon';

const ChecklistCount = ( { task } ) => {
	const total = task.checklist_total ?? task.checklist?.length ?? 0;
	const checked =
		task.checklist_checked ??
		task.checklist?.filter( ( item ) => item.checked ).length ??
		0;
	if ( ! total ) {
		return null;
	}

	return (
		<span
			className="pandat69-checklist-count"
			title="Checklist items checked"
		>
			<Icon name="list-todo" size={ 15 } />
			{ checked }/{ total } checked
		</span>
	);
};

export default ChecklistCount;
