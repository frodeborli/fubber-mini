<?php
// Inline handler: return query parameter or default to "pong"
return fn() => $_GET['say'] ?? 'pong';
