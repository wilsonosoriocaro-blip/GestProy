<?php

namespace App\Enums;

/**
 * Role of a user inside one project's team. The project owner is not a
 * member row: it lives in projects.owner_id.
 */
enum ProjectMemberRole: string
{
    /** Works on the project and can update the tasks assigned to them. */
    case Member = 'member';

    /** Follows the project (sponsor, stakeholder) without editing it. */
    case Observer = 'observer';

    public function label(): string
    {
        return match ($this) {
            self::Member => 'Integrante',
            self::Observer => 'Observador',
        };
    }
}
