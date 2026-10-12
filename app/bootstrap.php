<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/autoload.php';

return Arix\Application::create(dirname(__DIR__))->kernel();
