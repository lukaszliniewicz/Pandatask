import React from 'react';
import {
    AlignLeft,
    Archive,
    ArrowDownUp,
    ArrowRight,
    ArrowUp,
    BarChart3,
    Bug,
    CalendarDays,
    CalendarPlus,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    CircleCheck,
    CirclePlus,
    Columns3,
    CornerDownRight,
    Eye,
    File,
    Flag,
    FolderKanban,
    GripVertical,
    History,
    Layers3,
    Link,
    List,
    ListFilter,
    ListPlus,
    ListTodo,
    ListTree,
    Maximize2,
    Menu,
    MessageSquare,
    Minimize2,
    MoreHorizontal,
    Paperclip,
    Pencil,
    Plus,
    Quote,
    RefreshCw,
    Search,
    Star,
    Tags,
    Trash2,
    Undo2,
    Users,
    X,
} from 'lucide-react';

const ICONS = {
    'align-left': AlignLeft,
    archive: Archive,
    'arrow-down-up': ArrowDownUp,
    'arrow-right': ArrowRight,
    'arrow-up': ArrowUp,
    'bar-chart': BarChart3,
    bug: Bug,
    calendar: CalendarDays,
    'calendar-plus': CalendarPlus,
    check: Check,
    'chevron-down': ChevronDown,
    'chevron-left': ChevronLeft,
    'chevron-right': ChevronRight,
    'circle-alert': CircleAlert,
    'circle-check': CircleCheck,
    'circle-plus': CirclePlus,
    columns: Columns3,
    'corner-down-right': CornerDownRight,
    eye: Eye,
    file: File,
    flag: Flag,
    folder: FolderKanban,
    grip: GripVertical,
    history: History,
    layers: Layers3,
    link: Link,
    list: List,
    'list-filter': ListFilter,
    'list-plus': ListPlus,
    'list-todo': ListTodo,
    'list-tree': ListTree,
    maximize: Maximize2,
    menu: Menu,
    message: MessageSquare,
    minimize: Minimize2,
    more: MoreHorizontal,
    paperclip: Paperclip,
    pencil: Pencil,
    plus: Plus,
    quote: Quote,
    refresh: RefreshCw,
    search: Search,
    star: Star,
    tags: Tags,
    trash: Trash2,
    undo: Undo2,
    users: Users,
    x: X,
};

const Icon = ({ name, className = '', size = 18, strokeWidth = 2, ...props }) => {
    const LucideIcon = ICONS[name] || CircleAlert;
    const classes = ['pandat69-icon', className].filter(Boolean).join(' ');

    return (
        <LucideIcon
            aria-hidden="true"
            className={classes}
            data-pandatask-icon={name}
            focusable="false"
            size={size}
            strokeWidth={strokeWidth}
            {...props}
        />
    );
};

export default Icon;
