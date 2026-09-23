<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business Calendar
    |--------------------------------------------------------------------------
    |
    | Every schedule metric of the projects module (elapsed, remaining and
    | overdue days, expected progress) is counted in business days. Weekends
    | and Colombian public holidays (Ley 51 de 1983, "Ley Emiliani") are
    | excluded automatically. Days listed in "extra_non_working_days" are
    | also excluded, which covers one-off decrees or company-wide days off.
    |
    */

    'weekend_days' => [6, 7], // ISO-8601: 6 = Saturday, 7 = Sunday

    'extra_non_working_days' => [
        // '2026-12-24',
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule Health Thresholds
    |--------------------------------------------------------------------------
    |
    | "due_soon_business_days": an open item whose remaining business days are
    | at or below this value is flagged as due soon.
    |
    | "behind_schedule_tolerance": percentage points the real progress may
    | trail the expected progress before an item is flagged as behind.
    |
    | "stale_after_business_days": a project with no activity for this long is
    | reported as having no recent activity.
    |
    */

    'due_soon_business_days' => (int) env('PROJECTS_DUE_SOON_DAYS', 5),

    'behind_schedule_tolerance' => (int) env('PROJECTS_BEHIND_TOLERANCE', 15),

    'stale_after_business_days' => (int) env('PROJECTS_STALE_DAYS', 10),

];
