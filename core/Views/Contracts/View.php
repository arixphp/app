<?php

declare(strict_types=1);

namespace Arix\Views\Contracts;

interface View
{
    public function name(): string;

    public function data(): array;
}
