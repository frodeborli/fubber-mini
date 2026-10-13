<?php

namespace mini\Template;

/**
 * Per-render output sink used by compiled templates instead of PHP's
 * output buffer (ob_start()/ob_get_clean()).
 *
 * A compiled template never produces direct output - every translated
 * construct (inline HTML, `<?= ?>`, `echo`, `print`, `printf`, `vprintf`)
 * writes through TemplateContext to an instance of this class. Output
 * buffers belong to the whole process, which breaks the moment two requests
 * interleave inside one process (a long-running worker suspended on I/O
 * mid-template). A RenderSink is a plain object local to one render() call,
 * so two interleaved renders - even across Fibers - never share state and
 * can never write into each other's output.
 *
 * Nested capture (TemplateContext::block()/end(), and the top-level capture
 * in Renderer::render()) is a stack of string buffers: push() opens a new
 * buffer, write() appends to whichever buffer is on top, and pop() closes
 * the top buffer and returns its content.
 */
final class RenderSink
{
    /** @var list<string> Stack of buffers; writes always go to the last one. */
    private array $buffers = [''];

    /**
     * Append a value to the active buffer, applying the same string
     * conversion PHP's `echo` applies to each of its arguments.
     *
     * @return int Always 1, so this doubles as the value of a compiled `print`.
     */
    public function write(mixed $value): int
    {
        $this->buffers[array_key_last($this->buffers)] .= (string) $value;
        return 1;
    }

    /**
     * Open a new, empty buffer on top of the stack.
     */
    public function push(): void
    {
        $this->buffers[] = '';
    }

    /**
     * Close the top buffer and return everything written to it since the
     * matching push().
     *
     * @throws \LogicException If called without a matching push()
     */
    public function pop(): string
    {
        if (count($this->buffers) <= 1) {
            throw new \LogicException('RenderSink::pop() called without a matching push()');
        }
        return array_pop($this->buffers);
    }
}
