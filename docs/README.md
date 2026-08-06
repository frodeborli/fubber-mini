# Mini Framework — Documentation Index

## Main Documentation

The primary documentation lives at the project root:

- **[README.md](../README.md)** — Getting started, aspects overview, module catalogue, quick examples
- **[CLAUDE.md](../CLAUDE.md)** — Reference for Claude Code and other coding agents working in Mini projects
- **[MINI-STYLE.md](../MINI-STYLE.md)** — Code style and idiom guide
- **[REFERENCE.md](../REFERENCE.md)** — Complete API reference and function catalogue
- **[PATTERNS.md](../PATTERNS.md)** — Common patterns (service overrides, hooks, output buffering)
- **[DESIGN-PRINCIPLES.md](../DESIGN-PRINCIPLES.md)** — The principles that shape Mini's design decisions
- **[CHANGE-LOG.md](../CHANGE-LOG.md)** — Notable changes per version

## Deeper Discussions

- **[WHY-MINI.md](WHY-MINI.md)** — Design rationale: what motivates each major design choice and what each one trades off
- **[philosophy.md](philosophy.md)** — Longer-form discussion of Mini's philosophical position in the PHP framework landscape

## Tutorials

Step-by-step guides for building applications:

- **[web-apps.md](web-apps.md)** — Web application patterns: routing, response converters, error pages
- **[sub-apps.md](sub-apps.md)** — Mounting sub-applications (admin panels, API versioning, multi-tenant sections)
- **[templates.md](templates.md)** — Template system with multi-level inheritance and blocks
- **[cli-tools.md](cli-tools.md)** — Building command-line tools with argument parsing
- **[dispatchers.md](dispatchers.md)** — How the HTTP dispatcher works and how to customize it
- **[auth.md](auth.md)** — Authentication and authorization patterns

## Aspects

Aspects are Mini's unit of feature organisation — Composer-packaged feature containers under `aspects/<name>/`. See the [Aspects section in the main README](../README.md#aspects-organizing-your-application) and run `vendor/bin/mini aspects --help` for the CLI.

## Module Documentation

Each module has its own `README.md` in its source directory. These are the authoritative reference for each module's API.

### Application infrastructure

- **[src/Mini/README.md](../src/Mini/README.md)** — The `Mini` class, service container, lifecycle phases
- **[src/Dispatcher/README.md](../src/Dispatcher/README.md)** — HTTP dispatcher with streaming + Range support
- **[src/Router/README.md](../src/Router/README.md)** — File-based routing
- **[src/Controller/README.md](../src/Controller/README.md)** — Attribute-based controllers
- **[src/Http/README.md](../src/Http/README.md)** — PSR-7 implementation and HTTP helpers
- **[src/Http/Client/README.md](../src/Http/Client/README.md)** — PSR-18 HTTP client
- **[src/Session/README.md](../src/Session/README.md)** — Fiber-safe session proxy
- **[src/Static/README.md](../src/Static/README.md)** — Static file middleware

### Data

- **[src/Database/README.md](../src/Database/README.md)** — PDO-backed database, `PartialQuery`, `Model`
- **[src/Database/Virtual/README.md](../src/Database/Virtual/README.md)** — Federated SQL engine (`VirtualDatabase`)
- **[src/Database/Attributes/README.md](../src/Database/Attributes/README.md)** — Entity attributes (`#[Table]`, `#[PrimaryKey]`, etc.)
- **[src/Cache/README.md](../src/Cache/README.md)** — PSR-16 cache (APCu → SQLite → filesystem)
- **[src/UUID/README.md](../src/UUID/README.md)** — UUID v4 and v7 generation

### Validation and metadata

- **[src/Validator/README.md](../src/Validator/README.md)** — JSON Schema-compatible validation
- **[src/Metadata/README.md](../src/Metadata/README.md)** — JSON Schema annotation vocabulary
- **[src/Converter/README.md](../src/Converter/README.md)** — Type conversion registry

### Security

- **[src/Auth/README.md](../src/Auth/README.md)** — Authentication facade
- **[src/Authorizer/README.md](../src/Authorizer/README.md)** — Capability-based authorization with handlers

### Communication and templating

- **[src/Mail/README.md](../src/Mail/README.md)** — RFC 5322 mail with streaming MIME
- **[src/Template/README.md](../src/Template/README.md)** — Pure-PHP templates with multi-level inheritance
- **[src/I18n/README.md](../src/I18n/README.md)** — i18n with ICU MessageFormat
- **[src/Logger/README.md](../src/Logger/README.md)** — PSR-3 logging

### Events and extensibility

- **[src/Hooks/README.md](../src/Hooks/README.md)** — Typed event dispatchers (`Event`, `Trigger`, `Handler`, `Filter`, `PerItemTriggers`, `StateMachine`)

### Utilities

- **[src/CLI/README.md](../src/CLI/README.md)** — Command-line argument parsing
- **[src/Util/README.md](../src/Util/README.md)** — Foundation utilities (paths, identity maps, math, query parser, etc.)
- **[src/Contracts/README.md](../src/Contracts/README.md)** — Cross-module interfaces
- **[src/Exceptions/README.md](../src/Exceptions/README.md)** — Exception hierarchy

### Integration seams

Interface-only modules. Mini defines the contract; the implementation is configured per application.

- **[src/Async/README.md](../src/Async/README.md)** — Event loop seam (`AsyncInterface`, `async()`) for Fiber-based runtimes
- **[src/Inference/README.md](../src/Inference/README.md)** — LLM seam (`InferenceServiceInterface`, `inference()`) for schema-constrained evaluation

## Internal Notes

The `notes/` directory contains internal design documents, performance analysis, and brainstorming sessions. These are preserved for historical reference but are **not** official framework documentation — they may contain outdated terminology or rejected approaches.

## Documentation Philosophy

Mini's documentation focuses on:

1. **Practical examples** — Real-world use cases with working code
2. **Complete coverage** — Every module thoroughly documented in its source directory
3. **Self-contained** — Each `README.md` stands alone as complete reference
4. **No magic** — Explicit about how things work under the hood
5. **Common tasks first** — Most frequent use cases come first

## Contributing Documentation

The conventions are the five points above, plus:

- **Documentation lives next to the code it describes.** A new module gets `src/<Module>/README.md`; link it from the module list here and from the catalogue in the [main README](../README.md).
- **Open with the module's role in a forkable core** — what it is, and what it deliberately leaves to the layer above.
- **Every example must be copy-pasteable**: real imports, real symbol names, verified against source. Prefer a short working example over prose.
- **Docblocks are the API reference.** `vendor/bin/mini docs` renders them, so document classes and functions at the source, not only in Markdown.
- **`notes/` is not documentation.** Design explorations go there and are exempt from these rules.

See [MINI-STYLE.md](../MINI-STYLE.md) for code style and framework idioms.
