# Mini Framework Design Principles

## Core Philosophy: Stable Forever Through Boring Choices

**Goal:** Build a framework that remains at version 1.x forever by making only decisions that will still make sense in 20+ years.

## The Lindy Effect Strategy
- If something has survived 10+ years, it will likely survive another 10+ years
- If we invent something new, it might only last months
- Therefore: Always prefer battle-tested patterns over novel approaches
- Trust boring technology - if it's boring, it's probably survived the Lindy test

## String Interpolation
- Primary: ICU's `{key}` syntax (translations, messages, etc.)
- Alternative: `sprintf()` with `%s` when needed (also battle-tested, decades old)
- Rationale: Both have decades of real-world use
- Consistent across translations, logs, everywhere applicable
- Don't invent custom syntax like `:key` or similar

## Database & SQL
- **Expose SQL directly** - minimize query builder DSL abstraction
- SQL abstractions like `$db->query('table')->where(new AndClause(...))` are just another DSL
- DSLs are limited by the host language's syntax capabilities
- SQL has:
  - Decades of global documentation
  - Massive corpus of expertise and Stack Overflow answers
  - Standardized syntax (with known dialect variations)
  - Universal recognition across all platforms
- The only reasons IDEs don't support SQL in PHP strings:
  1. PHP community created an "island" with Eloquent/Doctrine
  2. PHP lacks tagged template literals (unlike JavaScript's `sql\`SELECT...\``)
- Developers already expect SQL dialect differences (MySQL vs PostgreSQL vs SQLite)
- When we built SQL parser for virtual tables:
  - Did the hard work rather than create leaky abstractions
  - Limited dialect is honest about capabilities (like SQLite's limitations)
  - Aggregations in PHP are faster than trying to implement SQL functions in userland
- DatabaseInterface does "chunk up" SQL (where clauses, `PartialQuery::order('col DESC, other ASC')`)
- **Pragmatic compromise:** Reduce boilerplate while requiring SQL knowledge
- Developers who don't know SQL must learn SQL - this prepares them for other languages where Eloquent/Doctrine doesn't exist
- Teaching juniors to become seniors, not hiding complexity forever

## ORM Patterns
- Follow Entity Framework's POCO (Plain Old CLR Objects) model
- Not Laravel's Active Record as core design
- Microsoft invested billions in these architectural decisions
- Active Record offered as opt-in `ActiveRecordTrait`, not core
- Similarly `RepositoryTrait` for repository pattern
- Both built on shared foundation

## Validation
- Use JSON Schema (standardized, cross-platform)
- Not `filter_var()` (PHP-specific)
- Rationale:
  - Client + server validation with same schema
  - Decades of specification work
  - OpenAPI integration
  - Validation is metadata about entities

## Schema Definition
- Copy .NET Entity Framework's attributes
- Proven at massive scale
- Well-documented edge cases already solved
- Don't reinvent attribute design

## Development Philosophy
- **No semantic versioning churn** - breaking changes are rare, considered failures
- **Less opinionated** - provide building blocks (traits) for multiple patterns
- **Security patches only** - features are "complete" not "evolving"
- **Hard work over clever abstractions** - do the difficult implementation to maintain simple, stable APIs
- If writing an application with Mini in 2025, expect it to run the same way in 2035 and 2045
- Only constraint: PHP doesn't remove essential functionality we rely on

## What We Try NOT To Do (With Nuance)
- **Avoid inventing syntax/DSLs when proven alternatives exist** - but pragmatic compromises for boilerplate reduction are acceptable
- **Don't chase "developer experience" trends** - DX improvements often cannibalize other developers' preferences
  - Senior developers often hate abstractions that benefit juniors, and vice versa
  - Middle ground: Teach juniors to become seniors rather than hide complexity
- **Don't create abstractions that hide underlying technology** - only abstract away wiring and boilerplate
  - Example: `mini\fmt()` wraps ICU formatting, but doesn't invent new formatting syntax
- **Try not to make decisions we might regret in 10+ years**
- **Question decisions by billion-dollar companies** - but give them benefit of doubt after studying their patterns
  - Usually they got it right, but understand *why* before copying

## Routing Philosophy
- **File-based routing:** Direct inspiration from Apache/nginx routing
- Pattern-based routing when needed
- **Placeholder syntax:** `{varName}` and `{varName:regex}`
  - Spring MVC has used this since 2010 (boring/battle-tested)
  - `{varName}` arguably follows ICU MessageFormat pattern (even more boring)
  - FastRoute (2014) uses similar syntax but is too new to be authoritative
- **Type-driven constraints:** Controller function signature determines capture patterns
  - `function getUser(int $id)` → `{id}` automatically becomes `\d+`
  - `function getPost(string $slug)` → `{slug}` becomes `[^/]+`
  - Falls back to treating forward slash as uncapturable (universal path separator)
- **Converter registry integration:** Use type hints for automatic conversion
  - `function show(User $user)` where `{user}` is int → converter registry converts int to User instance
  - Enable rich, type-safe routing without manual conversion boilerplate

## PHP Version & Language Feature Selection
- **Don't target specific PHP versions** - target "sane PHP" subset
- Avoid PHP features considered "weird" by other languages:
  - `$$varVariable` syntax forbidden (likely to be deprecated someday)
  - Variable variables, `eval()`, etc.
- **Code like it's C#** - use strong typing everywhere
  - C# is clear inspiration for modern PHP anyway
  - Strongly typed code is more portable conceptually
- Use features that would make sense in any strongly-typed language

## Email/Mail System & MIME
- **Direct MIME structure mapping** - no mail-specific DSL
- **Pure PSR-7 MessageInterface** - any MessageInterface works as a MIME part
  - Accept Guzzle responses, any PSR-7 message - no wrapper classes needed
  - `MultipartMessage` accepts any `MessageInterface` as parts
- **Single `MultipartMessage` class for all multipart types**
  - Per RFC 2046: "All present and future subtypes of the 'multipart' type must use an identical syntax"
  - No separate classes for mixed/alternative/related - structural behavior is identical
  - Type differentiation via `MultipartType` enum and Content-Type header
  - `getBody()` returns streaming `StreamInterface` - no buffering
- **Recursive composition via streaming**
  - Nested multipart messages stream through children automatically
  - Each level inserts its own boundary markers
  - Infinite nesting supported without memory overhead
- **Parts API modeled after PSR-7 headers API**
  - `getParts()`, `getPart(int)`, `hasPart(int)`
  - `withPart(int, MessageInterface)`, `withAddedPart()`, `withoutPart(int)`
  - `findPart(callable)`, `findParts(callable)`, `withParts(callable)`
  - ArrayAccess + Countable + IteratorAggregate for convenient part access
- **Interface-based mailer backend** (future)
  - Framework provides MIME structure, not transport
  - No required dependencies - compose messages then use any transport
- **Lindy credentials:**
  - MIME multipart (RFC 2046, 1996): 28+ years old
  - Email message format (RFC 5322/822, 1982): 42+ years old
  - ArrayAccess/Countable/Iterator (SPL, PHP 5.0, 2004): 20+ years old
  - Learning Mini MIME = learning MIME standard (transferable knowledge)
  - PSR-7 foundation (2015): 9 years, proven standard

## Notes for Further Elaboration
- [TODO: HTTP handling - native superglobals vs PSR-7?]
- [TODO: Templating philosophy]
- [TODO: Dependency injection - singleton vs containers?]
- [TODO: Error handling approach]
- [TODO: Stability guarantee document?]
TODO: Rename src/CLI to src/Cli for namespace consistency (mini\Cli not mini\CLI)
