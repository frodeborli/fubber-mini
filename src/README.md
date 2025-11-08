# Mini Framework - Source Code

This directory contains the complete source code for the Mini PHP framework.

## Namespace Organization

The framework is organized into feature namespaces, each self-contained with its own documentation:

### Core Features
- **[Auth](Auth/)** - Authentication system with pluggable user providers
- **[Cache](Cache/)** - PSR-16 caching with auto-detection (APCu, SQLite, filesystem)
- **[Database](Database/)** - PDO database abstraction with helpers
- **[I18n](I18n/)** - Internationalization, translation, and formatting
- **[Logger](Logger/)** - PSR-3 logging to error_log
- **[Mailer](Mailer/)** - Email sending via Symfony Mailer
- **[Router](Router/)** - File-based and pattern routing
- **[Tables](Tables/)** - Structured data tables with ORM-like features
- **[UUID](UUID/)** - UUID v4 and v7 generation

### Framework Internals
- **[CLI](CLI/)** - Command-line argument parsing
- **[Http](Http/)** - PSR-7 helpers and error handling
- **[Hooks](Hooks/)** - Event system for lifecycle hooks
- **[Contracts](Contracts/)** - Core interface contracts
- **[Exceptions](Exceptions/)** - Framework exception classes
- **[Util](Util/)** - Internal utility classes

## Documentation

Each namespace has comprehensive documentation in its `README.md` file. The documentation focuses on:

1. **Purpose** - What the feature does and when to use it
2. **Common examples** - Real-world usage patterns
3. **Configuration** - How to customize behavior
4. **Best practices** - Recommended patterns

The framework's docblocks provide additional API-level documentation for all classes and functions.

## Philosophy

Mini embraces "PHP as the framework":

- Use native PHP (`$_GET`, `$_POST`, `header()`) instead of abstractions
- File-based routing instead of route configuration
- Minimal dependencies - only what's necessary
- Convention over configuration with sensible defaults
- Zero magic - explicit and transparent

See the main `README.md` for getting started and `REFERENCE.md` for complete API documentation.
