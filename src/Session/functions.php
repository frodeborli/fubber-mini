<?php

namespace mini;

use mini\Dispatcher\HttpDispatcher;
use mini\Session\Session;
use mini\Session\SessionInterface;

/**
 * Session Feature - Global Helper Functions
 *
 * Provides the session() helper function and registers the Session service.
 */

// Register Session service with Lifetime::Scoped for per-request instances
Mini::$mini->addService(
    SessionInterface::class,
    Lifetime::Scoped,
    fn() => new Session()
);

// Register session auto-save on request completion
Mini::$mini->get(HttpDispatcher::class)->onAfterRequest->listen(
    function() {
        try {
            $session = Mini::$mini->get(SessionInterface::class);
            if ($session->isStarted()) {
                $session->save();
            }
        } catch (\Throwable) {
            // Session not available or save failed - ignore
        }
    }
);

/**
 * Get the session instance for the current request
 *
 * The session auto-starts on first access (get, set, etc.), so you don't
 * need to call session_start() manually.
 *
 * Usage:
 * ```php
 * // Set values
 * session()->set('user_id', 123);
 * session()['user_id'] = 123;      // ArrayAccess also works
 *
 * // Get values
 * $userId = session()->get('user_id');
 * $userId = session()['user_id'];  // ArrayAccess also works
 *
 * // Check existence
 * if (session()->has('user_id')) { ... }
 * if (isset(session()['user_id'])) { ... }
 *
 * // Remove values
 * session()->remove('user_id');
 * unset(session()['user_id']);
 *
 * // Get all data
 * $allData = session()->all();
 *
 * // Clear session
 * session()->clear();
 *
 * // Regenerate ID (e.g., after login)
 * session()->regenerate(deleteOldSession: true);
 *
 * // Destroy session completely
 * session()->destroy();
 * ```
 *
 * The session automatically saves at request end. You can save early
 * to release the session lock:
 * ```php
 * session()->save();  // Save and release lock
 * // Long-running operation that doesn't need session...
 * ```
 *
 * @return SessionInterface Session instance for current request
 */
function session(): SessionInterface
{
    return Mini::$mini->get(SessionInterface::class);
}
