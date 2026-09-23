<?php

namespace App\Enums;

/**
 * Application roles (spatie/laravel-permission). Row-level rules, such as
 * "an owner may edit their own project", live in the policies.
 */
enum Role: string
{
    case Admin = 'admin';
    case Leader = 'leader';
    case ProjectManager = 'project_manager';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Leader => 'Líder de Tecnología y Estrategias Digitales',
            self::ProjectManager => 'Responsable de proyecto',
            self::Member => 'Integrante',
            self::Viewer => 'Consulta',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Leader => [
                Permission::ProjectsViewAll,
                Permission::ProjectsCreate,
                Permission::ProjectsUpdateAll,
                Permission::ProjectsArchive,
                Permission::ProjectsDelete,
                Permission::TasksManageAll,
                Permission::CatalogsManage,
                Permission::DashboardView,
                Permission::AuditView,
            ],
            self::ProjectManager => [
                Permission::ProjectsCreate,
                Permission::DashboardView,
            ],
            self::Member => [
                Permission::DashboardView,
            ],
            self::Viewer => [
                Permission::ProjectsViewAll,
                Permission::DashboardView,
            ],
        };
    }
}
