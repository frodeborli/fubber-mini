<?php

/**
 * UUID v7 factory configuration example.
 *
 * UUID v7 provides time-ordered identifiers that are naturally sortable
 * and database-friendly.
 *
 * To use UUID v7 as the default, copy this file to:
 * `_config/mini/UUID/FactoryInterface.php`
 *
 * Or create a symlink:
 * ```bash
 * ln -s config/mini/UUID/UUID7Factory.php _config/mini/UUID/FactoryInterface.php
 * ```
 */
return new \mini\UUID\UUID7Factory();
