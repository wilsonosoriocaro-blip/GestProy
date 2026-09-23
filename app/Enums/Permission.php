<?php

namespace App\Enums;

/**
 * Global permissions of the projects module. Access to a single project
 * that the user owns or belongs to is granted by the policies without a
 * global permission.
 */
enum Permission: string
{
    /** See every project, not only the ones the user owns or belongs to. */
    case ProjectsViewAll = 'projects.view_all';
    case ProjectsCreate = 'projects.create';
    /** Edit any project, not only owned ones. */
    case ProjectsUpdateAll = 'projects.update_all';
    case ProjectsArchive = 'projects.archive';
    case ProjectsDelete = 'projects.delete';
    /** Create, edit and reassign tasks in any project. */
    case TasksManageAll = 'tasks.manage_all';
    /** Categories, statuses and priorities. */
    case CatalogsManage = 'catalogs.manage';
    case DashboardView = 'dashboard.view';
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';
}
