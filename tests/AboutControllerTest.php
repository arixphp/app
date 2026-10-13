<?php

declare(strict_types=1);

use Arix\Constants\HttpStatusCode;

it('renders the about page with its translations', function (): void {
    $this->get('/about')
        ->assertStatus(HttpStatusCode::OK)
        ->assertSee('About ArixPHP')
        ->assertSee('A small framework for content-driven websites.');
});
