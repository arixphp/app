<?php

declare(strict_types=1);

namespace Arix\Views;

use Arix\Views\Contracts\View as ViewContract;

class ViewFactory
{
    public function make(string $name, array $data = []): ViewContract
    {
        return new View($name, $data);
    }
}
