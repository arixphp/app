<?php

declare(strict_types=1);

namespace Arix\Http;

use Throwable;

use function is_string;

class ErrorPageRenderer
{
    private readonly string $templatePath;

    public function __construct(string|null $templatePath = null)
    {
        $this->templatePath = $templatePath ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'error.html';
    }

    public function render(int $status, string $message): string|null
    {
        try {
            if (! @is_file($this->templatePath) || ! @is_readable($this->templatePath)) {

                return null;
            }

            // A disappearing resource must not turn the original HTTP error into another failure.
            $template = @file_get_contents($this->templatePath);

            if (! is_string($template) || $template === '' || ! mb_check_encoding($template, 'UTF-8')) {

                return null;
            }

            $values = ['{{status}}' => (string) $status, '{{message}}' => $message, '{{name}}' => 'ArixPHP', '{{locale}}' => 'en'];

            foreach ($values as $placeholder => $value) {
                if (! str_contains($template, $placeholder)) {

                    return null;
                }

                $values[$placeholder] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            return strtr($template, $values);
        } catch (Throwable) {
            // Includes applications that convert filesystem warnings to exceptions.

            return null;
        }
    }
}
