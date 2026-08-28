export const flattenInboxPages = ( pages = [] ) =>
	pages.flatMap( ( page ) => page?.tasks || [] );

export const getInboxNextPageParam = ( lastPage ) => {
	const pagination = lastPage?.pagination;
	if (
		! pagination?.has_more ||
		pagination.next_offset === null ||
		pagination.next_offset === undefined
	) {
		return undefined;
	}

	const nextOffset = Number( pagination.next_offset );
	return Number.isFinite( nextOffset ) && nextOffset >= 0
		? nextOffset
		: undefined;
};
