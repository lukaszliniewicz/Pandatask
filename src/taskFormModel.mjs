const toIntegerIds = ( values = [] ) =>
	values.map( ( id ) => Number.parseInt( id, 10 ) );

export const createTaskFormDefaults = ( {
	task = null,
	defaultTaskType = 'task',
	defaultValues = {},
	boardName = '',
	currentUser = null,
} ) => {
	const isUserBoard = boardName.startsWith( 'user_' );
	let assignedPeople = [];

	if ( task?.assigned_user_ids ) {
		assignedPeople = toIntegerIds( task.assigned_user_ids );
	} else if ( defaultValues.assigned_persons ) {
		assignedPeople = defaultValues.assigned_persons;
	} else if ( isUserBoard && ! task && currentUser?.id ) {
		assignedPeople = [ Number.parseInt( currentUser.id, 10 ) ];
	}

	return {
		name: task?.name || '',
		description: task?.description || defaultValues.description || '',
		status: task?.status || 'pending',
		priority: task?.priority || 5,
		schedule_mode: task?.deadline_days_after_start ? 'dynamic' : 'fixed',
		start_date: task?.start_date || '',
		deadline: task?.deadline || '',
		deadline_days_after_start: task?.deadline_days_after_start || '',
		project_id: task?.project_id || defaultValues.project_id || '',
		category_id: task?.category_id || '',
		assigned_persons: assignedPeople,
		supervisor_persons: toIntegerIds( task?.supervisor_user_ids ),
		predecessors: toIntegerIds( task?.predecessor_ids ),
		task_type: task?.task_type || defaultTaskType,
		bug_url: task?.bug_url || defaultValues.bug_url || '',
		notify_deadline: Number( task?.notify_deadline ) === 1,
		notify_days_before: task?.notify_days_before || 3,
		parent_task_id:
			task?.parent_task_id || defaultValues.parent_task_id || '',
		is_recurring: Number( task?.is_recurring ) === 1,
		recurrence_frequency:
			task?.recurrence_frequency === 'weekly' &&
			Number( task?.recurrence_interval ) === 2
				? 'bi-weekly'
				: task?.recurrence_frequency || 'weekly',
		recurrence_interval: task?.recurrence_interval || 1,
		recurrence_days: task?.recurrence_days
			? task.recurrence_days.split( ',' )
			: [],
		recurrence_ends_on: task?.recurrence_ends_on || '',
		attachment: {
			type: task?.attachment_type || '',
			url: task?.attachment_url || '',
			id: task?.attachment_post_id || '',
			filename: task?.attachment_filename || '',
			publicSourceRetained: Boolean(
				task?.attachment_public_source_retained
			),
		},
		target_board:
			task?.board_name || defaultValues.target_board || boardName,
	};
};

export const buildTaskPayload = (
	data,
	{ boardName, isUserBoard, isEdit, task = null, changeComment = '' }
) => {
	const payload = { ...data };
	payload.board_name =
		isUserBoard && data.target_board
			? data.target_board
			: task?.board_name || boardName;
	delete payload.target_board;

	if ( data.attachment ) {
		payload.attachment_type = data.attachment.type;
		payload.attachment_url = data.attachment.url;
		payload.attachment_post_id = data.attachment.id;
		payload.attachment_filename = data.attachment.filename;
		delete payload.attachment;
	}

	if ( data.schedule_mode === 'dynamic' ) {
		payload.deadline = '';
		if ( payload.predecessors?.length > 0 ) {
			payload.start_date = '';
			if ( payload.status === 'in-progress' && ! isEdit ) {
				payload.status = 'pending';
			}
		}
	} else {
		payload.deadline_days_after_start = '';
	}
	delete payload.schedule_mode;

	payload.is_recurring = data.is_recurring ? 1 : 0;
	payload.notify_deadline = data.notify_deadline ? 1 : 0;
	if ( Array.isArray( data.recurrence_days ) ) {
		payload.recurrence_days = data.recurrence_days.join( ',' );
	}
	if ( data.parent_task_id ) {
		payload.parent_task_id = Number.parseInt( data.parent_task_id, 10 );
	}
	if ( changeComment ) {
		payload.change_comment = changeComment;
	}

	return payload;
};

export const requiresTaskChangeReason = ( task, data ) =>
	Boolean(
		task &&
			( task.status !== data.status ||
				( data.schedule_mode === 'fixed' &&
					( task.deadline || '' ) !== ( data.deadline || '' ) ) )
	);

export const validationErrorTab = ( errors ) => {
	if ( errors.name || errors.task_type ) {
		return 'general';
	}
	if ( errors.deadline_days_after_start ) {
		return 'schedule';
	}
	return null;
};
