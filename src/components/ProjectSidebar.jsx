import React from 'react';
import ReactDOM from 'react-dom';
import { useProjects } from '../hooks/useProjects';

const TABS = [
    { id: 'tasks', label: 'All Tasks', icon: 'dashicons-list-view' },
    { id: 'projects', label: 'Projects', icon: 'dashicons-portfolio' },
    { id: 'overview', label: 'Overview', icon: 'dashicons-chart-bar' },
    { id: 'archive', label: 'Archive', icon: 'dashicons-archive' },
    { id: 'report', label: 'Report', icon: 'dashicons-analytics' },
];

const VIEWS = [
    { id: 'compact', label: 'Compact' },
    { id: 'list', label: 'List' },
    { id: 'kanban', label: 'Kanban' },
    { id: 'calendar', label: 'Calendar' },
];

const ProjectSidebar = ({ 
    selectedProjectId, onSelectProject, onAddProject, 
    isOpen, isMobile, onClose,
    currentTab, onTabChange
}) => {
    const { data: projects, isLoading } = useProjects();
    
    // On mobile, if not open, don't render at all
    if (!isOpen && isMobile) return null;

    const sidebarContent = (
        <div className={`pandat69-project-sidebar ${isOpen ? 'expanded' : 'collapsed'} ${isMobile ? 'is-mobile' : ''}`}>
            <div className="pandat69-sidebar-header">
                {/* No hamburger here anymore. It lives in Header.jsx */}
                
                <div className="pandat69-sidebar-title-wrapper">
                    <h3 className="pandat69-sidebar-title">PROJECTS</h3>
                    <button 
                        className="pandat69-add-project-mini-btn"
                        onClick={(e) => { e.stopPropagation(); onAddProject(); }}
                        title="Add New Project"
                    >
                        <span className="dashicons dashicons-plus"></span>
                    </button>
                </div>
                
                {isMobile && (
                    <button className="pandat69-icon-button close-mobile" onClick={onClose} aria-label="Close Menu">
                        <span className="dashicons dashicons-no-alt"></span>
                    </button>
                )}
            </div>

            <div className="pandat69-sidebar-content">
                <ul className="pandat69-sidebar-list">
                    {isMobile && (
                        <>
                            <li className="pandat69-compact-group-heading">Navigation</li>
                            {TABS.map(tab => (
                                <li 
                                    key={tab.id}
                                    className={`pandat69-sidebar-item ${currentTab === tab.id ? 'active' : ''}`}
                                    onClick={() => { onTabChange(tab.id); onClose(); }}
                                >
                                    <span className={`dashicons ${tab.icon}`}></span>
                                    <span className="pandat69-sidebar-label">{tab.label}</span>
                                </li>
                            ))}
                            <li className="pandat69-sidebar-divider"></li>
                        </>
                    )}

                    {isMobile && <li className="pandat69-compact-group-heading">Projects</li>}

                    <li 
                        className={`pandat69-sidebar-item ${selectedProjectId === 'all' ? 'active' : ''}`}
                        onClick={() => { onSelectProject('all'); if(isMobile) onClose(); }}
                    >
                        <span className="dashicons dashicons-menu-alt"></span>
                        <span className="pandat69-sidebar-label">All Project Tasks</span>
                    </li>
                    
                    <li 
                        className={`pandat69-sidebar-item ${selectedProjectId === 'none' ? 'active' : ''}`}
                        onClick={() => { onSelectProject('none'); if(isMobile) onClose(); }}
                    >
                        <span className="dashicons dashicons-flag"></span>
                        <span className="pandat69-sidebar-label">Unassigned</span>
                    </li>

                    <li className="pandat69-sidebar-divider"></li>

                    {isLoading && (isOpen || isMobile) && <li className="pandat69-sidebar-loading" style={{padding:'10px'}}>Loading...</li>}

                    {projects?.map(project => (
                        <li 
                            key={project.id}
                            className={`pandat69-sidebar-item ${selectedProjectId == project.id ? 'active' : ''}`}
                            onClick={() => { onSelectProject(project.id); if(isMobile) onClose(); }}
                        >
                            <span className="dashicons dashicons-portfolio"></span>
                            <div className="pandat69-sidebar-item-content">
                                <span className="pandat69-sidebar-label">{project.name}</span>
                                {(isOpen || isMobile) && project.deadline && (
                                    <span className="pandat69-sidebar-project-deadline">Due: {project.deadline}</span>
                                )}
                            </div>
                            {(isOpen || isMobile) && project.tasks && project.tasks.length > 0 && (
                                <span className="pandat69-sidebar-count">{project.tasks.length}</span>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );

    if (isMobile) {
        return ReactDOM.createPortal(
            <>
                {isOpen && <div className="pandat69-sidebar-overlay" onClick={onClose}></div>}
                {sidebarContent}
            </>,
            document.body
        );
    }

    return sidebarContent;
};

export default ProjectSidebar;
