<?php

namespace App\Services\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Generates codes like TED-2026-007. Must run inside a transaction: on
 * PostgreSQL an advisory lock serializes concurrent creations so two
 * projects never get the same sequence number.
 */
class ProjectCodeGenerator
{
    public function next(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = config()->string('projects.code_prefix').'-'.$year.'-';

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32($prefix)]);
        }

        $last = Project::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn (string $code): int => (int) substr($code, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), config()->integer('projects.code_sequence_digits'), '0', STR_PAD_LEFT);
    }
}
