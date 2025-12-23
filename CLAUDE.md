# Mini Framework - Claude Code Quick Reference

Important: Read MINI-STYLE.md before using Mini framework.

## Design Principles

### Fail Fast, Be Strict
Prefer strictness everywhere. Fail fast and expose potential errors early. Don't be "smart" by guessing what the user meant - require explicit, correct input.

**Example:** AliasTable only accepts aliased column names (`users.id`), not original names (`id`). If someone passes `id` to an aliased table, it throws immediately rather than guessing they meant `users.id`.

**Rationale:**
- Errors surface immediately, not as subtle bugs later
- Forces conscious decisions about API design
- The "smart" behavior can always be added later if truly needed
- Often the error reveals a bug in our own code that should be fixed

When encountering a fail-fast exception, the correct response is usually to fix the calling code, not to relax the validation.