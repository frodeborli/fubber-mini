<?php
namespace mini\Util;

/**
 * @template T of object
 */
final class IdentityMap
{
    /** @var array<string|int, \WeakReference<T>> */
    private array $byId = [];

    /** @var \WeakMap<T, string|int> */
    private \WeakMap $byObj;

    private int $ops = 0;
    private int $sweepEvery;

    public function __construct(int $sweepEvery = 200)
    {
        $this->byObj = new \WeakMap();
        $this->sweepEvery = max(10, $sweepEvery);
    }

    /**
     * @param string|int $id 
     * @return null|T 
     */
    public function tryGet(string|int $id): ?object
    {
        $ref = $this->byId[$id] ?? null;
        if (!$ref) return null;

        $obj = $ref->get();
        if ($obj === null) { // dead, clean lazily
            unset($this->byId[$id]);
            return null;
        }
        $this->tick();
        return $obj;
    }

    /**
     * @param T $obj 
     * @param string|int $id 
     */
    public function remember(object $obj, string|int $id): void
    {
        $this->byId[$id] = \WeakReference::create($obj);
        $this->byObj[$obj] = $id;   // auto-removed when $obj is GC’d
        $this->tick();
    }

    /**
     * @param string|int $id 
     */
    public function forgetById(string|int $id): void
    {
        unset($this->byId[$id]);
    }

    /**
     * @param T $obj 
     */
    public function forgetObject(object $obj): void
    {
        $id = $this->byObj[$obj] ?? null;
        if ($id !== null) unset($this->byId[$id], $this->byObj[$obj]);
    }

    private function tick(): void
    {
        if ((++$this->ops % $this->sweepEvery) === 0) $this->sweep();
    }

    private function sweep(): void
    {
        foreach ($this->byId as $id => $ref) {
            if ($ref->get() === null) unset($this->byId[$id]);
        }
    }
}
