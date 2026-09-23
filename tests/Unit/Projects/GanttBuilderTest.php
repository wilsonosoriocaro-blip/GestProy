<?php

namespace Tests\Unit\Projects;

use App\Enums\ScheduleHealth;
use App\Services\Projects\Gantt\GanttBuilder;
use App\Services\Projects\Gantt\GanttItem;
use App\Services\Projects\Gantt\GanttZoom;
use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Reference "today": Wednesday 2026-09-23.
 */
class GanttBuilderTest extends TestCase
{
    private GanttBuilder $builder;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new GanttBuilder(new BusinessCalendar);
        $this->today = CarbonImmutable::parse('2026-09-23');
    }

    private function item(string $key, ?string $start, ?string $end, array $dependsOn = [], bool $open = true, int $progress = 50): GanttItem
    {
        return new GanttItem($key, ucfirst($key), null, null,
            $start ? CarbonImmutable::parse($start) : null,
            $end ? CarbonImmutable::parse($end) : null,
            $progress, ScheduleHealth::OnTrack, $open, $dependsOn);
    }

    public function test_week_zoom_snaps_to_mondays_and_draws_inclusive_bars(): void
    {
        $chart = $this->builder->build([$this->item('a', '2026-09-28', '2026-10-02')], GanttZoom::Week, $this->today);

        // Range: today (Sep 23) - 3 days = Sep 20 → Monday Sep 14; Oct 2 + 3 = Oct 5 → Sunday Oct 11.
        $this->assertSame('2026-09-14', $chart['start']);
        $this->assertSame('2026-10-11', $chart['end']);
        $this->assertSame(28 * 28, $chart['width']);

        $row = $chart['rows'][0];
        $this->assertSame(14 * 28, $row['x']);  // Sep 28 is 14 days after Sep 14
        $this->assertSame(5 * 28, $row['w']);   // Mon–Fri, both ends included
        $this->assertSame(9 * 28, $chart['today']);
        $this->assertNull($row['overdue_w']);
    }

    public function test_month_zoom_snaps_to_whole_months(): void
    {
        $chart = $this->builder->build([$this->item('a', '2026-10-05', '2026-12-20')], GanttZoom::Month, $this->today);

        $this->assertSame('2026-09-01', $chart['start']);
        $this->assertSame('2026-12-31', $chart['end']);
        $this->assertCount(4, $chart['months']);
        $this->assertSame([], $chart['weeks']);
        $this->assertSame([], $chart['non_working']);
    }

    public function test_open_items_past_due_get_an_overdue_stretch_until_today(): void
    {
        $chart = $this->builder->build([
            $this->item('late', '2026-09-14', '2026-09-18'),
            $this->item('done', '2026-09-14', '2026-09-18', open: false),
        ], GanttZoom::Week, $this->today);

        [$late, $done] = $chart['rows'];

        $this->assertSame($late['x'] + $late['w'], $late['overdue_x']);
        $this->assertSame(5 * 28, $late['overdue_w']); // Sep 19 → Sep 23
        $this->assertNull($done['overdue_w']);
    }

    public function test_single_date_items_are_milestones_and_undated_items_have_no_bar(): void
    {
        $chart = $this->builder->build([
            $this->item('milestone', null, '2026-09-30'),
            $this->item('undated', null, null),
        ], GanttZoom::Week, $this->today);

        [$milestone, $undated] = $chart['rows'];

        $this->assertTrue($milestone['milestone']);
        $this->assertTrue($milestone['has_bar']);
        $this->assertFalse($undated['has_bar']);
        $this->assertSame('Sin fechas', $undated['dates']);
    }

    public function test_dependencies_link_the_end_of_the_predecessor_to_the_start_of_the_successor(): void
    {
        $chart = $this->builder->build([
            $this->item('design', '2026-09-14', '2026-09-18'),
            $this->item('build', '2026-09-21', '2026-10-02', ['design']),
            $this->item('undated', null, null),
            $this->item('test', '2026-10-05', '2026-10-09', ['build', 'undated', 'missing']),
        ], GanttZoom::Week, $this->today);

        $this->assertSame([
            ['from' => 0, 'to' => 1, 'x1' => $chart['rows'][0]['x'] + $chart['rows'][0]['w'], 'x2' => $chart['rows'][1]['x']],
            ['from' => 1, 'to' => 3, 'x1' => $chart['rows'][1]['x'] + $chart['rows'][1]['w'], 'x2' => $chart['rows'][3]['x']],
        ], $chart['links']);
    }

    public function test_week_zoom_shades_weekends_and_holidays(): void
    {
        $chart = $this->builder->build([$this->item('a', '2026-10-05', '2026-10-16')], GanttZoom::Week, $this->today);

        $start = CarbonImmutable::parse($chart['start']);
        $x = fn (string $date) => (int) $start->diffInDays(CarbonImmutable::parse($date)) * 28;

        // Saturday Oct 10, Sunday Oct 11 and Monday Oct 12 (holiday) form one run.
        $this->assertContains(['x' => $x('2026-10-10'), 'w' => 3 * 28], $chart['non_working']);
    }

    public function test_no_dated_items_returns_an_empty_chart_with_rows(): void
    {
        $chart = $this->builder->build([$this->item('a', null, null)], GanttZoom::Month, $this->today);

        $this->assertTrue($chart['empty']);
        $this->assertCount(1, $chart['rows']);
        $this->assertSame([], $chart['links']);
    }
}
