<?php

declare(strict_types=1);

namespace Arix\Views;

use Arix\Views\Contracts\View as ViewContract;
use InvalidArgumentException;

use function is_string;

class View implements ViewContract
{
    public function __construct(private readonly string $template, private readonly array $properties = [])
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $template)) {
            throw new InvalidArgumentException('Invalid template name.');
        }

        foreach ($properties as $name => $_) {
            if (! is_string($name) || ! preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $name)) {
                throw new InvalidArgumentException('Template data keys must be valid PHP variable names.');
            }

            if (str_starts_with($name, '__arix') || $name === 'this' || $name === 'GLOBALS') {
                throw new InvalidArgumentException("Reserved template variable: {$name}");
            }
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
