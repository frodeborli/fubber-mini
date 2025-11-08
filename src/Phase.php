<?php

namespace mini;

/**
 * Application lifecycle phases
 *
 * Represents the application's overall state, not individual request state.
 * The Ready phase can handle many concurrent requests while remaining in Ready state.
 *
 * Usage:
 * ```php
 * // Check current phase
 * echo Mini::$mini->phase->getCurrentState()->value;  // "bootstrap"
 *
 * // Transition to next phase
 * Mini::$mini->phase->trigger(Phase::Ready);
 *
 * // Subscribe to phase transitions
 * Mini::$mini->phase->onEnteringState(Phase::Ready, function($old, $new) {
 *     // Called when entering Ready phase
 * });
 * ```
 *
 * Typical flows:
 * - Traditional SAPI: Initializing → Bootstrap → Ready → Shutdown
 * - Long-running: Initializing → Bootstrap → Ready (handles requests) → Shutdown
 * - Failure: Any phase → Failed → Shutdown
 */
enum Phase: string
{
    /**
     * Initializing phase - before framework bootstrap begins
     *
     * In this phase:
     * - Mini singleton is being constructed
     * - Hooks can be registered to observe Bootstrap entry
     * - Very brief transitional state
     *
     * This is the initial phase when Mini::$mini is first created.
     */
    case Initializing = 'initializing';

    /**
     * Bootstrap phase - framework initialization
     *
     * In this phase:
     * - Services are being registered (addService, etc.)
     * - Configuration is being loaded
     * - Lifecycle hooks are being set up
     * - Request handling is NOT yet available
     *
     * After bootstrap completes, transitions to Ready.
     */
    case Bootstrap = 'bootstrap';

    /**
     * Ready phase - application is ready to handle requests
     *
     * In this phase:
     * - Framework is fully initialized and locked
     * - Services CANNOT be registered (container is locked)
     * - Application can handle requests (one or many, concurrent or sequential)
     * - This is the normal operating state
     *
     * Traditional SAPI: Stays in Ready while handling the single request, then → Shutdown
     * Long-running: Stays in Ready indefinitely, handling many requests, until → Shutdown
     *
     * Note: Individual request contexts are managed separately via getRequestScope(),
     * not via phase transitions. The application remains in Ready phase throughout.
     */
    case Ready = 'ready';

    /**
     * Failed phase - unrecoverable error occurred
     *
     * In this phase:
     * - An unrecoverable error has occurred during initialization
     * - Application cannot continue normal operation
     * - Will transition to Shutdown
     *
     * This is used when Bootstrap fails or other critical errors occur.
     */
    case Failed = 'failed';

    /**
     * Shutdown phase - application is shutting down
     *
     * In this phase:
     * - All resources are being released
     * - Connections are being closed
     * - Process will terminate
     *
     * This is the terminal phase before process death.
     */
    case Shutdown = 'shutdown';
}
