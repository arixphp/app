<?php

declare(strict_types=1);

namespace Arix\Views;

use Arix\Views\Contracts\View as ViewContract;
use InvalidArgumentException;

class View implements ViewContract
{
    public function __construct(private readonly string $template, private readonly array $properties = [])
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $template)) {
            throw new InvalidArgumentException('Invalid template name.');
        }
    }

    public function name(): string
    {
        return $this->template;
    }

    public function data(): array
    {
        return $this->properties;
    }
}
