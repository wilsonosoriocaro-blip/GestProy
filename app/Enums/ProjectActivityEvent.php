<?php

namespace App\Enums;

/**
 * Events recorded in project_activity_logs. The same table feeds the
 * project timeline and the audit trail (old and new values).
 */
enum ProjectActivityEvent: string
{
    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case StatusChanged = 'project.status_changed';
    case OwnerChanged = 'project.owner_changed';
    case DatesChanged = 'project.dates_changed';
    case ProgressChanged = 'project.progress_changed';
    case ProjectArchived = 'project.archived';
    case ProjectRestored = 'project.restored';
    case ProjectDeleted = 'project.deleted';
    case MemberAdded = 'member.added';
    case MemberRemoved = 'member.removed';
    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskAssigned = 'task.assigned';
    case TaskStatusChanged = 'task.status_changed';
    case TaskCompleted = 'task.completed';
    case TaskDeleted = 'task.deleted';
    case CommentAdded = 'comment.added';

    public function label(): string
    {
        return match ($this) {
            self::ProjectCreated => 'Proyecto creado',
            self::ProjectUpdated => 'Proyecto actualizado',
            self::StatusChanged => 'Cambio de estado',
            self::OwnerChanged => 'Cambio de responsable',
            self::DatesChanged => 'Cambio de fechas',
            self::ProgressChanged => 'Cambio de avance',
            self::ProjectArchived => 'Proyecto archivado',
            self::ProjectRestored => 'Proyecto restaurado',
            self::ProjectDeleted => 'Proyecto eliminado',
            self::MemberAdded => 'Integrante agregado',
            self::MemberRemoved => 'Integrante retirado',
            self::TaskCreated => 'Tarea creada',
            self::TaskUpdated => 'Tarea actualizada',
            self::TaskAssigned => 'Tarea asignada',
            self::TaskStatusChanged => 'Cambio de estado de tarea',
            self::TaskCompleted => 'Tarea finalizada',
            self::TaskDeleted => 'Tarea eliminada',
            self::CommentAdded => 'Comentario',
        };
    }
}
