import React, { useId, useMemo, useState } from 'react';
import { useUsers } from '../hooks/useUsers';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import Icon from './Icon';

const EMPTY_SELECTED_USER_IDS = [];

const UserSelect = ({ selectedUserIds = EMPTY_SELECTED_USER_IDS, onChange, overrideBoardName, inputLabel = 'Search users' }) => {
    const [search, setSearch] = useState('');
    const searchId = useId();
    const debouncedSearch = useDebouncedValue(search.trim());
    const { data: users, isLoading } = useUsers(debouncedSearch, overrideBoardName, selectedUserIds);
    const selectedUserIdSet = useMemo(
        () => new Set(selectedUserIds.map((id) => Number(id))),
        [selectedUserIds]
    );

    const handleSearch = (e) => {
        setSearch(e.target.value);
    };

    const toggleUser = (userId) => {
        const id = parseInt(userId, 10);
        let newSelection;
        if (selectedUserIdSet.has(id)) {
            newSelection = selectedUserIds.filter(uid => uid !== id);
        } else {
            newSelection = [...selectedUserIds, id];
        }
        onChange(newSelection);
    };

    // Derived state for display
    const selectedUsersDisplay = users 
        ? users.filter(u => selectedUserIdSet.has(parseInt(u.id, 10)))
        : [];

    return (
        <div className="pandat69-user-select-component">
            <div className="pandat69-selected-users-container">
                {selectedUsersDisplay.map(user => (
                    <span key={user.id} className="pandat69-selected-user">
                        {user.name} 
                        <button
                            type="button"
                            className="pandat69-remove-user" 
                            onClick={() => toggleUser(user.id)}
                            aria-label={`Remove ${user.name}`}
                            style={{ cursor: 'pointer', marginLeft: '5px' }}
                        >
                            <Icon name="x" size={14} />
                        </button>
                    </span>
                ))}
            </div>
            
            <label className="pandat69-visually-hidden" htmlFor={searchId}>{inputLabel}</label>
            <input
                id={searchId}
                type="text" 
                className="pandat69-input" 
                placeholder="Search users..." 
                aria-autocomplete="list"
                aria-controls={`${searchId}-suggestions`}
                value={search}
                onChange={handleSearch} 
            />
            
            {isLoading && <div className="pandat69-loading-small" aria-live="polite">Searching...</div>}
            
            {users && users.length > 0 && search.length > 0 && (
                <ul id={`${searchId}-suggestions`} className="pandat69-user-suggestions" aria-label="User suggestions" style={{ display: 'block', position: 'relative', maxHeight: '150px', overflowY: 'auto', border: '1px solid #ddd', marginTop: '-1px' }}>
                    {users.map(user => {
                        const isSelected = selectedUserIdSet.has(parseInt(user.id, 10));
                        if (isSelected) return null; // Hide already selected from suggestions
                        
                        return (
                            <li key={user.id} className="pandat69-user-suggestion-item">
                                <button
                                    type="button"
                                    onClick={() => {
                                        toggleUser(user.id);
                                        setSearch('');
                                    }}
                                    style={{ padding: '8px', cursor: 'pointer', borderBottom: '1px solid #eee', width: '100%', textAlign: 'left' }}
                                >
                                    {user.name}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
};

export default UserSelect;
