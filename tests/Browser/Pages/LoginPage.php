<?php

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class LoginPage extends Page
{
    /**
     * Get the URL for the page.
     */
    public function url(): string
    {
        return '/login';
    }

    /**
     * Assert that the browser is on the page.
     */
    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url());
    }

    /**
     * Get the element shortcuts for the page.
     *
     * @return array<string, string>
     */
    public function elements(): array
    {
        return [
            '@email' => 'input[id="data.email"]',
            '@password' => 'input[id="data.password"]',
            '@submit' => 'button[type="submit"]',
        ];
    }

    /**
     * Verify login page elements are visible.
     */
    public function isLoginPage(Browser $browser): void
    {
        $browser
            ->visit($this->url())
            ->assertSee('Entre a su cuenta')
            ->assertVisible('@email')
            ->assertVisible('@password')
            ->assertVisible('@submit');
    }

    /**
     * Login with email and password.
     */
    public function loginWithEmailAndPassword(Browser $browser, string $email, string $password): void
    {
        $browser
            ->visit($this->url())
            ->type('@email', $email)
            ->type('@password', $password)
            ->screenshot('before-submit')
            ->press('@submit')
            ->pause(3000) // Wait for Livewire to process
            ->screenshot('after-submit');
    }
}
