<?php

declare(strict_types=1);

namespace Arix\Pages\Contracts;

use Arix\Views\Contracts\View;

interface Page
{
    public function render(): View;
}
