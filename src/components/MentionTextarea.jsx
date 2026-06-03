import React from 'react';
import { MentionsInput, Mention } from 'react-mentions';
import { useConfig } from '../context/ConfigContext';

const MentionTextarea = ({ value, onChange, placeholder, minHeight = 80 }) => {
    const { apiClient, boardName } = useConfig();

    const fetchUsers = async (query, callback) => {
        if (!query) return;
        try {
            const params = new URLSearchParams();
            params.append('search', query);
            params.append('board_name', boardName);
            const response = await apiClient.get(`users`, { params });
            const suggestions = response.users.map(u => ({ id: u.id, display: u.name }));
            callback(suggestions);
        } catch (e) { callback([]); }
    };

    // Strict styling to prevent "garbled/double text"
    const style = {
        control: {
            fontSize: 14,
            fontWeight: 'normal',
            lineHeight: '1.5', // Strict line height
            minHeight: minHeight,
        },
        '&multiLine': {
            control: {
                fontFamily: 'inherit',
                border: '1px solid #ccc',
                borderRadius: '4px',
            },
            highlighter: {
                padding: 10,
                border: '1px solid transparent',
                boxSizing: 'border-box', // Critical
                overflow: 'hidden',
            },
            input: {
                padding: 10,
                border: '1px solid transparent', // Match highlighter border width
                boxSizing: 'border-box', // Critical
                overflow: 'auto',
                outline: 'none',
            },
        },
        suggestions: {
            list: {
                backgroundColor: 'white',
                border: '1px solid rgba(0,0,0,0.15)',
                fontSize: 14,
                boxShadow: '0 4px 12px rgba(0,0,0,0.1)',
                zIndex: 99999, // Ensure it's on top of modals
            },
            item: {
                padding: '8px 10px',
                borderBottom: '1px solid #eee',
                '&focused': { backgroundColor: '#f0f0f5' },
            },
        },
    };

    return (
        <MentionsInput
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder={placeholder}
            style={style}
            a11ySuggestionsListLabel={"Suggested users"}
        >
            <Mention
                trigger="@"
                data={fetchUsers}
                markup="@[__display__](__id__) "
                style={{ backgroundColor: '#e6f0ff', color: '#005b99', fontWeight: '600', position: 'relative', zIndex: 1 }}
            />
        </MentionsInput>
    );
};

export default MentionTextarea;
