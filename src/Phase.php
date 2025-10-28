<?php

namespace mini;

/**
 * Application lifecycle phases
 *
 * Bootstrap: Services can be registered, Scoped services cannot be accessed
 * Request: Services cannot be registered, Scoped services can be accessed
 */
enum Phase
{
    /**
     * Bootstrap phase - application is being configured
     * 
     * In this phase:
     * - Services can be registered (setupAuth, addService, etc.)
     * - Scoped services CANNOT be accessed (no request context yet)
     * 
     * This phase lasts until mini\bootstrap() is called.
     */
    case Bootstrap;

    /**
     * Request phase - handling an HTTP request
     * 
     * In this phase:
     * - Services CANNOT be registered (container is locked)
     * - Scoped services CAN be accessed (request context available)
     * 
     * This phase begins when mini\bootstrap() is called and lasts
     * until the request completes.
     */
    case Request;
}
