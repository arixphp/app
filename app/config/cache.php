<?php

declare(strict_types=1);

return [
    'enabled' => true,
    // Null uses a private, installation-specific directory in sys_get_temp_dir().
    // If open_basedir forbids it, set an absolute writable path outside the web root.
    'path' => null,
    'version' => '1',
    // Explicit opt-in only: evaluate trusted .arix.php templates if cache is unavailable.
    'allow_eval' => false,
];
