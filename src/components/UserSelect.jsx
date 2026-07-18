import React, { useState } from 'react';
import { useUsers } from '../hooks/useUsers';
import { useDebouncedValue } from '../hooks/useDebouncedValue';

const UserSelect = ({ selectedUserIds = [], onChange, overrideBoardName }) => {
    const [search, setSearch] = useState('');
    const debouncedSearch = useDebouncedValue(search.trim());
    const { data: users, isLoading } = useUsers(debouncedSearch, overrideBoardName, selectedUserIds);

    const handleSearch = (e) => {
        setSearch(e.target.value);
    };

    const toggleUser = (userId) => {
        const id = parseInt(userId, 10);
        let newSelection;
        if (selectedUserIds.includes(id)) {
            newSelection = selectedUserIds.filter(uid => uid !== id);
        } else {
            newSelection = [...selectedUserIds, id];
        }
        onChange(newSelection);
    };

    // Derived state for display
    const selectedUsersDisplay = users 
        ? users.filter(u => selectedUserIds.includes(parseInt(u.id, 10)))
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
                            &times;
                        </button>
                    </span>
                ))}
            </div>
            
            <input 
                type="text" 
                className="pandat69-input" 
                placeholder="Search users..." 
                value={search}
                onChange={handleSearch} 
            />
            
            {isLoading && <div className="pandat69-loading-small" aria-live="polite">Searching...</div>}
            
            {users && users.length > 0 && search.length > 0 && (
                <ul className="pandat69-user-suggestions" style={{ display: 'block', position: 'relative', maxHeight: '150px', overflowY: 'auto', border: '1px solid #ddd', marginTop: '-1px' }}>
                    {users.map(user => {
                        const isSelected = selectedUserIds.includes(parseInt(user.id, 10));
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
