<?php

namespace mini;

use mini\Database\DatabaseInterface;
use mini\Database\PDOService;
use PDO;

// Register services
Mini::$mini->addService(PDO::class, Lifetime::Scoped, fn() => Mini::$mini->loadServiceConfig(PDO::class));
Mini::$mini->addService(DatabaseInterface::class, Lifetime::Scoped, fn() => Mini::$mini->loadServiceConfig(DatabaseInterface::class));

// Declare the mini\db(): DatabaseInterface accessor function
function db(): DatabaseInterface {
    return Mini::$mini->get(DatabaseInterface::class);
}
