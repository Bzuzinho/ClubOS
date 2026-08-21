<?php

declare(strict_types=1);

// Compatibility front controller for isolated pre-cutover health checks.
// Production Nginx serves public/ directly; this file is not part of the public web root.
require __DIR__.'/public/index.php';
