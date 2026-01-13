# Contracts - Framework Interfaces

This namespace contains core interface contracts used throughout the Mini framework.

These contracts define common patterns used internally by the framework and are available for your application code when implementing framework-compatible classes. They're kept minimal and focused on specific use cases rather than being general-purpose abstractions.

## Purpose

Contracts provide type safety and clear expectations for components that need to interact with the framework. For example, `MapInterface` defines what a key-value store must support, and `CollectionInterface` defines traversable collections with functional operations like `map()` and `filter()`.

## When to Use

You typically implement these interfaces when:

- Creating custom key-value stores that need to work with framework internals
- Building collections with functional transformation support
- Extending framework functionality with custom implementations

Most application code won't need to directly implement these contracts—they're primarily for framework integration points.
