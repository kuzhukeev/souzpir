<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders each isolated preview with anonymous props', function (string $path, string $component): void {
    $this->withoutVite();
    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component($component, false)
        ->where('auth.user', null)
        ->where('preview', true)
        ->where('organization', null));
    $this->assertGuest();
})->with([
    ['/preview/login', 'Preview/Login'],
    ['/preview/attention', 'Preview/Attention'],
]);

it('rejects preview routes in production', function (string $path): void {
    $this->app->instance('env', 'production');
    $this->get($path)->assertNotFound();
})->with(['/preview/login', '/preview/attention']);

it('does not expose an authentication mutation from the mockup', function (): void {
    $this->post('/preview/login', ['email' => 'demo@example.test', 'password' => 'dummy-only'])->assertStatus(405);
    $this->assertGuest();
});
