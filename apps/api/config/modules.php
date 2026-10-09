<?php

use App\Modules\Story\Manifest;

/*
| Installed modules, in sidebar order. The core reads this list through
| App\Core\Modules\ModuleRegistry and never imports module classes itself.
*/

return [
    'manifests' => [
        Manifest::class,
        App\Modules\Character\Manifest::class,
        App\Modules\Assets3d\Manifest::class,
        App\Modules\Voice\Manifest::class,
        App\Modules\Music\Manifest::class,
        App\Modules\Video\Manifest::class,
        App\Modules\Ads\Manifest::class,
        App\Modules\Player\Manifest::class,
        App\Modules\Io\Manifest::class,
    ],
];
