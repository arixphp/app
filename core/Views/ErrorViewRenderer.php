<?php

declare(strict_types=1);

namespace Arix\Views;

class ErrorViewRenderer
{
    public function __construct(
        private readonly TemplateEngine $engine,
        private readonly string $name,
    ) {}

    public function render(int $status, string $message): string|null
    {
        $html = $this->engine->render("errors.{$status}", [
            'status' => $status,
            'message' => $message,
            'name' => $this->name,
        ]);

        return trim($html) !== '' && mb_check_encoding($html, 'UTF-8') ? $html : null;
    }
}
