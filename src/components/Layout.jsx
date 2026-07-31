import React from 'react';
import { createPortal } from 'react-dom';
import { useBoardController } from '../hooks/useBoardController';
import BoardShell from './board/BoardShell';

const Layout = () => {
    const controller = useBoardController();
    const board = <BoardShell controller={controller} />;
    return controller.isFullscreen ? createPortal(board, document.body) : board;
};

export default Layout;
