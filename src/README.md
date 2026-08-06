# Mini Framework - Source Code

This directory contains the complete source code for the Mini PHP framework — a forkable core: generic building blocks a business can own and maintain for a decade, with opinionated conveniences left to layers built on top.

## Namespace Organization

The framework is organized into self-contained feature namespaces, most with their own documentation:

### Core Features
- **[Auth](Auth/)** - Authentication facade over an application-provided `AuthInterface`
- **[Authorizer](Authorizer/)** - Capability-based authorization (`can()`) with chain-of-responsibility handlers
- **[Cache](Cache/)** - PSR-16 caching with auto-detection (APCu, SQLite, filesystem)
- **[Database](Database/)** - SQL-first database layer with the immutable `Query`/`PartialQuery` builder and `VirtualDatabase` federated SQL engine
- **[I18n](I18n/)** - Internationalization, translation, and formatting (ICU MessageFormat)
- **[Logger](Logger/)** - PSR-3 logging
- **[Mail](Mail/)** - From-scratch RFC 5322 e-mail with streaming MIME, sent via `mailer()`
- **[Router](Router/)** - Filesystem-based routing under `_routes/`
- **[Session](Session/)** - Fiber-safe, cache-backed `$_SESSION`
- **[Template](Template/)** - Pure-PHP templates with multi-level inheritance
- **[Validator](Validator/)** - JSON Schema-compatible validation via attributes
- **[Metadata](Metadata/)** - JSON Schema annotation vocabulary (titles, descriptions, examples)
- **[UUID](UUID/)** - UUID v4 and v7 generation

### Framework Internals
- **[CLI](CLI/)** - Command-line argument parsing
- **[Controller](Controller/)** - Attribute-based controllers and `AbstractController`
- **[Converter](Converter/)** - Type conversion registry (drives hydration and response conversion)
- **[Dispatcher](Dispatcher/)** - HTTP dispatch pipeline, middleware, exception converters
- **[Http](Http/)** - PSR-7/PSR-17 messages, HTTP client, error handling
- **[Hooks](Hooks/)** - Typed event dispatchers (`Event`, `Trigger`, `Handler`, `Filter`, `StateMachine`)
- **[Static](Static/)** - Static file serving
- **[Contracts](Contracts/)** - Core interface contracts
- **[Exceptions](Exceptions/)** - Framework exception classes
- **[Mini](Mini/)** - Kernel, service container, path registry
- **[Util](Util/)** - Internal utility classes

### Undocumented / Internal Namespaces

These namespaces exist in `src/` but do not yet have their own `README.md`; consult the source docblocks:

- **Async** - Fiber-oriented async primitives (`AsyncInterface`, I/O wait helpers)
- **Form** - Form base class describing fields, validation rules, and actions (HTML forms and REST APIs)
- **Html** - Lightweight HTML node/element model with a CSS selector parser
- **Inference** - `InferenceServiceInterface` for LLM-based structured evaluation against a JSON Schema
- **Parsing** - Generic parser infrastructure, including the SQL parser used by `VirtualDatabase`
- **Table** - `TableInterface` implementations and combinators (`ArrayTable`, `CSVTable`, `Contracts/`, `Wrappers/`, indexes) that back `VirtualDatabase` virtual tables
- **Tables** - Reserved helper-function namespace for tables (currently empty)
- **Test** - Internal test harness classes (e.g. `SqlLogicTest`)

## Documentation

Most namespaces have comprehensive documentation in a `README.md` file (the exceptions are listed under "Undocumented / Internal Namespaces" above). The documentation focuses on:

1. **Purpose** - What the feature does and when to use it
2. **Common examples** - Real-world usage patterns
3. **Configuration** - How to customize behavior
4. **Best practices** - Recommended patterns

The framework's docblocks provide additional API-level documentation for all classes and functions.

## Philosophy

Mini embraces "PHP as the framework":

- Familiar superglobals (`$_GET`, `$_POST`, `$_SESSION`) work as expected — implemented as fiber-safe, request-scoped proxies over PSR-7
- File-based routing instead of route configuration; route files return handlers or responses (never `echo`/`header()`)
- Zero non-PSR dependencies - Mini *provides* implementations for five PSR contracts
- Convention over configuration with sensible defaults
- Zero magic - explicit and transparent

See the main `README.md` for getting started and `REFERENCE.md` for complete API documentation.
