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
    case CommentUpdated = 'comment.updated';
    case CommentDeleted = 'comment.deleted';

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
            self::CommentUpdated => 'Comentario editado',
            self::CommentDeleted => 'Comentario eliminado',
        };
    }

    /**
     * Groups offered by the history filter.
     *
     * @return array<string, array{label: string, events: list<self>}>
     */
    public static function groups(): array
    {
        return [
            'project' => ['label' => 'Datos del proyecto', 'events' => [self::ProjectCreated, self::ProjectUpdated, self::ProjectArchived, self::ProjectRestored, self::ProjectDeleted]],
            'status' => ['label' => 'Estado', 'events' => [self::StatusChanged]],
            'owner' => ['label' => 'Responsable', 'events' => [self::OwnerChanged]],
            'dates' => ['label' => 'Fechas', 'events' => [self::DatesChanged]],
            'progress' => ['label' => 'Avance', 'events' => [self::ProgressChanged]],
            'tasks' => ['label' => 'Tareas', 'events' => [self::TaskCreated, self::TaskUpdated, self::TaskAssigned, self::TaskStatusChanged, self::TaskCompleted, self::TaskDeleted]],
            'team' => ['label' => 'Equipo', 'events' => [self::MemberAdded, self::MemberRemoved]],
            'comments' => ['label' => 'Bitácora', 'events' => [self::CommentAdded, self::CommentUpdated, self::CommentDeleted]],
        ];
    }

    /** Heroicon name for the timeline. */
    public function icon(): string
    {
        return match ($this) {
            self::ProjectCreated => 'sparkles',
            self::ProjectUpdated, self::TaskUpdated => 'pencil-square',
            self::StatusChanged, self::TaskStatusChanged => 'arrow-path',
            self::OwnerChanged, self::TaskAssigned => 'user',
            self::DatesChanged => 'calendar-days',
            self::ProgressChanged => 'chart-bar',
            self::ProjectArchived => 'archive-box',
            self::ProjectRestored => 'arrow-uturn-left',
            self::ProjectDeleted, self::TaskDeleted, self::CommentDeleted => 'trash',
            self::MemberAdded => 'user-plus',
            self::MemberRemoved => 'user-minus',
            self::TaskCreated => 'plus-circle',
            self::TaskCompleted => 'check-circle',
            self::CommentAdded, self::CommentUpdated => 'chat-bubble-left-ellipsis',
        };
    }
}
