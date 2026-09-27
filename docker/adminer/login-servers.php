<?php

// Adminer login form shows only the local PayGate PostgreSQL server,
// so the driver can't accidentally be left on the default "MySQL".
require_once 'plugins/login-servers.php';

return new AdminerLoginServers([
    'PayGate PostgreSQL (local)' => ['server' => 'postgres', 'driver' => 'pgsql'],
]);
