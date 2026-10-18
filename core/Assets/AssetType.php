<?php

declare(strict_types=1);

namespace Arix\Assets;

enum AssetType: string
{
    case Style = 'styles';

    case Script = 'scripts';

    case Font = 'fonts';

    case Media = 'media';
}
