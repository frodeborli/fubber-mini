# Util - Internal Utilities

This namespace contains internal utility classes used by the Mini framework.

## Purpose

These classes handle framework internals like service instance management, path resolution, and query string parsing. They're not typically used directly in application code.

## Internal Components

- **InstanceStore** - Manages singleton and scoped service instances
- **IdentityMap** - Ensures single instance per identifier
- **PathsRegistry** - Tracks and resolves file paths
- **MachineSalt** - Generates machine-specific cryptographic salt
- **QueryParser** - Parses URL query strings into structured data

## Usage Note

You generally won't interact with these classes directly—they're implementation details that support higher-level framework features like the service container and routing system.

If you find yourself needing to use these classes, consider whether there's a higher-level framework API that accomplishes the same goal.
