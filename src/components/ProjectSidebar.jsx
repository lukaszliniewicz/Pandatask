import React from 'react';
import { createPortal } from 'react-dom';
import { useProjects } from '../hooks/useProjects';
import Icon from './Icon';

const TABS = [
    { id: 'tasks', label: 'All Tasks', icon: 'list-todo' },
    { id: 'projects', label: 'Projects', icon: 'folder' },
    { id: 'overview', label: 'Overview', icon: 'bar-chart' },
    { id: 'archive', label: 'Archive', icon: 'archive' },
    { id: 'report', label: 'Report', icon: 'bar-chart' },
];

const ProjectSidebar = ({ 
    selectedProjectId, onSelectProject, onAddProject, 
    isOpen, isMobile, onClose,
    currentTab, onTabChange,
    privateOnly = false
}) => {
    const { data: projects, isLoading } = useProjects(undefined, { privateOnly });
    
    // On mobile, if not open, don't render at all
    if (!isOpen && isMobile) return null;

    const sidebarContent = (
        <div className={`pandat69-project-sidebar ${isOpen ? 'expanded' : 'collapsed'} ${isMobile ? 'is-mobile' : ''}`}>
            <div className="pandat69-sidebar-header">
                {/* No hamburger here anymore. It lives in Header.jsx */}
                
                <div className="pandat69-sidebar-title-wrapper">
                    <h3 className="pandat69-sidebar-title">PROJECTS</h3>
                    <button 
                        type="button"
                        className="pandat69-add-project-mini-btn"
                        onClick={(e) => { e.stopPropagation(); onAddProject(); }}
                        title="Add New Project"
                        aria-label="Add new project"
                    >
                        <Icon name="plus" />
                    </button>
                </div>
                
                {isMobile && (
                    <button type="button" className="pandat69-icon-button close-mobile" onClick={onClose} aria-label="Close Menu">
                        <Icon name="x" />
                    </button>
                )}
            </div>

            <div className="pandat69-sidebar-content">
                <ul className="pandat69-sidebar-list">
                    {isMobile && (
                        <>
                            <li className="pandat69-compact-group-heading">Navigation</li>
                            {TABS.map(tab => (
                                <li key={tab.id}>
                                    <button
                                        type="button"
                                        className={`pandat69-sidebar-item ${currentTab === tab.id ? 'active' : ''}`}
                                        onClick={() => { onTabChange(tab.id); onClose(); }}
                                        aria-current={currentTab === tab.id ? 'page' : undefined}
                                    >
                                        <Icon name={tab.icon} />
                                        <span className="pandat69-sidebar-label">{tab.label}</span>
                                    </button>
                                </li>
                            ))}
                            <li className="pandat69-sidebar-divider"></li>
                        </>
                    )}

                    {isMobile && <li className="pandat69-compact-group-heading">Projects</li>}

                    <li>
                        <button
                            type="button"
                            className={`pandat69-sidebar-item ${selectedProjectId === 'all' ? 'active' : ''}`}
                            onClick={() => { onSelectProject('all'); if(isMobile) onClose(); }}
                            aria-pressed={selectedProjectId === 'all'}
                        >
                            <Icon name="list-todo" />
                            <span className="pandat69-sidebar-label">All Project Tasks</span>
                        </button>
                    </li>
                    
                    <li>
                        <button
                            type="button"
                            className={`pandat69-sidebar-item ${selectedProjectId === 'none' ? 'active' : ''}`}
                            onClick={() => { onSelectProject('none'); if(isMobile) onClose(); }}
                            aria-pressed={selectedProjectId === 'none'}
                        >
                            <Icon name="flag" />
                            <span className="pandat69-sidebar-label">Unassigned</span>
                        </button>
                    </li>

                    <li className="pandat69-sidebar-divider"></li>

                    {isLoading && (isOpen || isMobile) && <li className="pandat69-sidebar-loading" style={{padding:'10px'}}>Loading...</li>}

                    {projects?.map(project => (
                        <li key={project.id}>
                            <button
                                type="button"
                                className={`pandat69-sidebar-item ${selectedProjectId == project.id ? 'active' : ''}`}
                                onClick={() => { onSelectProject(project.id); if(isMobile) onClose(); }}
                                aria-pressed={selectedProjectId == project.id}
                            >
                                <Icon name="folder" />
                                <span className="pandat69-sidebar-item-content">
                                    <span className="pandat69-sidebar-label">{project.name}</span>
                                    {(isOpen || isMobile) && project.board_scope === 'group' && (
                                        <span className="pandat69-sidebar-project-source">{project.board_display_name}</span>
                                    )}
                                    {(isOpen || isMobile) && project.deadline && (
                                        <span className="pandat69-sidebar-project-deadline">Due: {project.deadline}</span>
                                    )}
                                </span>
                                {(isOpen || isMobile) && project.tasks && project.tasks.length > 0 && (
                                    <span className="pandat69-sidebar-count">{project.tasks.length}</span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );

    if (isMobile) {
        return createPortal(
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
