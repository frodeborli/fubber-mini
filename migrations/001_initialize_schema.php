<?php

/**
 * Initialize basic database schema
 *
 * Creates the core tables needed by the WCAG application.
 * This migration replaces the automatic schema initialization.
 */

return function ($db) {
    // Users table
    if (!$db->tableExists('users')) {
        $db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "Created users table\n";
    }

    // Projects table
    if (!$db->tableExists('projects')) {
        $db->exec("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name VARCHAR(100) NOT NULL,
                description TEXT,
                url VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            )
        ");
        echo "Created projects table\n";
    }

    // WCAG criteria table
    if (!$db->tableExists('wcag_criteria')) {
        $db->exec("
            CREATE TABLE wcag_criteria (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                guideline VARCHAR(10) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                level VARCHAR(3) NOT NULL,
                principle VARCHAR(20) NOT NULL
            )
        ");
        echo "Created wcag_criteria table\n";
    }

    // Project checks table
    if (!$db->tableExists('project_checks')) {
        $db->exec("
            CREATE TABLE project_checks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                criteria_id INTEGER NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                notes TEXT,
                checked_at DATETIME,
                FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                FOREIGN KEY (criteria_id) REFERENCES wcag_criteria (id),
                UNIQUE(project_id, criteria_id)
            )
        ");
        echo "Created project_checks table\n";
    }

    echo "Schema initialization complete\n";
};