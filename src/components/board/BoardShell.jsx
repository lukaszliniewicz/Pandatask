import React from 'react';
import Header from '../Header';
import ProjectSidebar from '../ProjectSidebar';
import BoardContent from './BoardContent';
import BoardDialogLayer from './BoardDialogLayer';
import BoardTabNavigation from './BoardTabNavigation';

const BoardShell = ({ controller }) => (
    <div
        ref={controller.containerRef}
        className={`pandat69-container ${controller.isFullscreen ? 'pandat69-viewport-shell' : ''} ${
            controller.isContainerNarrow ? 'is-container-narrow' : 'is-container-wide'
        }`}
        data-pandatask-viewport={controller.isFullscreen ? 'active' : 'inline'}
        data-pandatask-container-mode={controller.isContainerNarrow ? 'compact' : 'wide'}
    >
        <Header
            onAddTask={controller.openTaskDialog}
            onLogWork={controller.openWorkDialog}
            workLogEnabled={controller.workLogEnabled}
            onManageCategories={controller.openCategoryDialog}
            onFullscreen={controller.toggleFullscreen}
            isFullscreen={controller.isFullscreen}
            fullscreenToggleRef={controller.fullscreenToggleRef}
            currentView={controller.currentView}
            onViewChange={controller.setCurrentView}
			currentTab={controller.currentTab}
            toggleSidebar={controller.toggleSidebar}
            isSidebarOpen={controller.isSidebarOpen}
        />

        <div className="pandat69-layout-body">
            <ProjectSidebar
                isOpen={controller.isSidebarOpen}
                isMobile={controller.isContainerNarrow}
                selectedProjectId={controller.filters.project}
                onSelectProject={projectId => controller.setFilter('project', projectId)}
                onClose={() => controller.setIsSidebarOpen(false)}
                onAddProject={() => controller.openProjectDialog(null)}
                currentTab={controller.currentTab}
                onTabChange={controller.setCurrentTab}
                workLogEnabled={controller.workLogEnabled}
                privateOnly={controller.isUserBoard && controller.filters.onlyMyTasks}
            />

            <main className="pandat69-main-content">
                <BoardTabNavigation
                    currentTab={controller.currentTab}
                    onChange={controller.setCurrentTab}
                    isUserBoard={controller.isUserBoard}
                    workLogEnabled={controller.workLogEnabled}
                />
                <BoardContent controller={controller} />
            </main>
        </div>

        <BoardDialogLayer controller={controller} />
    </div>
);

export default BoardShell;
