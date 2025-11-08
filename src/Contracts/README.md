# Contracts - Framework Interfaces

This namespace contains core interface contracts used throughout the Mini framework.

These contracts define common patterns used internally by the framework and are available for your application code when implementing framework-compatible classes. They're kept minimal and focused on specific use cases rather than being general-purpose abstractions.

## Purpose

Contracts provide type safety and clear expectations for components that need to interact with the framework. For example, `CollectionInterface` defines what a collection must support, allowing framework code and your code to work with collections in a consistent way.

## When to Use

You typically implement these interfaces when:

- Creating custom collections that need to work with framework internals
- Building services that match framework patterns
- Extending framework functionality with custom implementations

Most application code won't need to directly implement these contracts—they're primarily for framework integration points.
