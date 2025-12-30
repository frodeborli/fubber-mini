# Mini Framework - Documentation

## Main Documentation

The primary documentation lives at the project root:

- **[README.md](../README.md)** - Getting started, philosophy, and quick examples
- **[REFERENCE.md](../REFERENCE.md)** - Complete API reference and function catalog
- **[PATTERNS.md](../PATTERNS.md)** - Common patterns (service overrides, hooks, output buffering)
- **[WRITING-DOCUMENTATION.md](../WRITING-DOCUMENTATION.md)** - Documentation standards and conventions
- **[CLAUDE.md](../CLAUDE.md)** - Development guide for Claude Code

## Tutorials

Step-by-step guides for building applications:

- **[cli-tools.md](cli-tools.md)** - Building command-line tools with argument parsing
- **[sub-apps.md](sub-apps.md)** - Building sub-applications (admin panels, documentation browsers)
- **[templates.md](templates.md)** - Template system with inheritance and blocks
- **[web-apps.md](web-apps.md)** - Web application patterns

## Feature Documentation

Each framework feature has comprehensive documentation in its source directory:

- **[src/Auth/README.md](../src/Auth/README.md)** - Authentication system
- **[src/Cache/README.md](../src/Cache/README.md)** - PSR-16 caching (APCu, SQLite, filesystem)
- **[src/CLI/README.md](../src/CLI/README.md)** - Command-line argument parsing
- **[src/Controller/README.md](../src/Controller/README.md)** - Attribute-based REST controllers
- **[src/Database/README.md](../src/Database/README.md)** - PDO database abstraction
- **[src/I18n/README.md](../src/I18n/README.md)** - Internationalization and formatting
- **[src/Logger/README.md](../src/Logger/README.md)** - PSR-3 logging
- **[src/Mailer/README.md](../src/Mailer/README.md)** - Email sending (Symfony Mailer)
- **[src/Router/README.md](../src/Router/README.md)** - File-based and pattern routing
- **[src/Tables/README.md](../src/Tables/README.md)** - Structured data tables
- **[src/UUID/README.md](../src/UUID/README.md)** - UUID v4 and v7 generation

## Internal Notes

The `notes/` directory contains internal design documents, performance analysis, and brainstorming sessions. These are preserved for historical reference but are not official framework documentation:

- `notes/CODE-TRANSLATION-APPROACH.md` - Swoole compatibility exploration
- `notes/SWOOLE-COMPATIBILITY.md` - Swoole integration design notes
- `notes/SWOOLE-PRACTICAL-APPROACH.md` - Practical Swoole implementation ideas
- `notes/PHP-RECOMPILE-APPROACH.md` - PHP recompilation brainstorming
- `notes/PERFORMANCE-ANALYSIS.md` - Benchmarking data and analysis
- `notes/REAL-WORLD-PERFORMANCE.md` - Production performance considerations
- `notes/OPTIMIZATION-OPPORTUNITIES.md` - Optimization strategy notes
- `notes/WHEN-TO-USE-MINI.md` - Marketing/positioning draft

**These notes are not linked from official documentation** and should not be treated as framework guidelines. They serve as source material for future documentation.

## Documentation Philosophy

Mini's documentation focuses on:

1. **Practical examples** - Real-world use cases with working code
2. **Complete coverage** - Every feature thoroughly documented in its namespace
3. **Self-contained** - Each README.md stands alone as complete reference
4. **No magic** - Explicit about how things work under the hood
5. **Common tasks first** - Most frequent use cases come first

## Contributing Documentation

See [WRITING-DOCUMENTATION.md](../WRITING-DOCUMENTATION.md) for documentation standards, structure, and best practices.
