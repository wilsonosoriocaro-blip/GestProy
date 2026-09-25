<?php

namespace Tests\Unit\Projects;

use App\Services\Projects\Gantt\GanttColor;
use PHPUnit\Framework\TestCase;

class GanttColorTest extends TestCase
{
    public function test_cycle_wraps_around_the_palette(): void
    {
        $cases = GanttColor::cases();

        $this->assertSame($cases[0], GanttColor::cycle(0));
        $this->assertSame($cases[0], GanttColor::cycle(count($cases)));
        $this->assertSame($cases[1], GanttColor::cycle(count($cases) + 1));
    }

    public function test_blue_is_never_in_the_cycle(): void
    {
        $this->assertNotContains('blue', array_map(fn (GanttColor $c) => $c->value, GanttColor::cases()));
    }
}
