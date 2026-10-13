<?php

declare(strict_types=1);

it('renders the home page with its translations', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Simple sites. Native PHP.')
        ->assertSee('Fast multilingual pages, without Composer or frontend builds.');
});
