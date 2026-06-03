import React, { createContext, useContext } from 'react';

const ConfigContext = createContext(null);

export const ConfigProvider = ({ children, config }) => {
    return (
        <ConfigContext.Provider value={config}>
            {children}
        </ConfigContext.Provider>
    );
};

export const useConfig = () => {
    const context = useContext(ConfigContext);
    if (!context) {
        throw new Error('useConfig must be used within a ConfigProvider');
    }
    return context;
};
