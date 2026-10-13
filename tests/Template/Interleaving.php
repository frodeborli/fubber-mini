<?php
/**
 * Proves the bug this whole feature fixes: two renders interleaved inside
 * one process - one suspending mid-template, exactly like a request
 * suspended on a database call inside a long-running worker - never share
 * output. This only needs plain Fibers (Mini has no dependency on
 * phasync/swerve): any runtime that can suspend execution mid-function has
 * the same hazard with ob_start()/ob_get_clean(), because output buffers
 * belong to the whole process, not to the Fiber/coroutine that opened them.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini;
use mini\Template\Renderer;
use mini\Test;

$test = new class extends Test {
    private Renderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new Renderer();
        Mini::$mini->paths->views->addPath(dirname(__DIR__) . '/_templates');
        $_ENV['MINI_CACHE_ROOT'] = sys_get_temp_dir() . '/mini-template-cache-test-' . getmypid();
    }

    private function startRender(string $label): \Fiber
    {
        $fiber = new \Fiber(function () use ($label) {
            return $this->renderer->render('interleaved.php', [
                'label' => $label,
                // Called from inside the template, right between its two
                // $this->echo() writes - this is where the two renders interleave.
                'suspend' => fn() => \Fiber::suspend(),
            ]);
        });
        $fiber->start();
        return $fiber;
    }

    public function testTwoRendersSuspendedMidTemplateDoNotShareOutput(): void
    {
        // Start A - it runs up to the suspend() call inside the template
        // and pauses there, with "Start-A-\n" captured only on its own
        // RenderSink, which is a plain object local to this Fiber's call
        // stack - nothing process-global.
        $fiberA = $this->startRender('A');
        self::assertFalse($fiberA->isTerminated());

        // Start B while A is still paused mid-render. If output still went
        // through ob_start()/ob_get_clean(), B's ob_start() would push onto
        // the one process-wide buffer stack sitting on top of A's, and the
        // two renders' content would mix.
        $fiberB = $this->startRender('B');
        self::assertFalse($fiberB->isTerminated());

        // Finish B first, completely, while A is still paused.
        $fiberB->resume();
        self::assertTrue($fiberB->isTerminated());

        // Now finish A.
        $fiberA->resume();
        self::assertTrue($fiberA->isTerminated());

        $outputA = $fiberA->getReturn();
        $outputB = $fiberB->getReturn();

        self::assertEquals("Start-A-\nEnd-A-\n", $outputA);
        self::assertEquals("Start-B-\nEnd-B-\n", $outputB);
    }

    public function testManyInterleavedRendersEachGetTheirOwnOutput(): void
    {
        $labels = ['A', 'B', 'C', 'D', 'E'];
        $fibers = [];
        foreach ($labels as $label) {
            $fibers[$label] = $this->startRender($label);
        }
        // Resume in a different order than they were started, to rule out
        // any accidental reliance on stack/start ordering.
        foreach (array_reverse($labels) as $label) {
            $fibers[$label]->resume();
        }

        foreach ($labels as $label) {
            self::assertTrue($fibers[$label]->isTerminated());
            self::assertEquals("Start-$label-\nEnd-$label-\n", $fibers[$label]->getReturn());
        }
    }
};

return $test->run();
