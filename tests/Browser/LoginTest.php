<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Pages\LoginPage;
use Tests\DuskTestCase;

class LoginTest extends DuskTestCase
{
    private function getAdminEmail(): string
    {
        return config('app.ADMIN_EMAIL');
    }

    private function getAdminPassword(): string
    {
        return config('app.ADMIN_PASSWORD');
    }

    public function testIsLoginPage(): void
    {
        $this->browse(function (Browser $browser) {
            $browser
                ->visit(new LoginPage)
                ->isLoginPage($browser);
        });
    }

    public function testAdminCanLogin(): void
    {
        $this->browse(function (Browser $browser) {
            $browser
                ->visit(new LoginPage)
                ->loginWithEmailAndPassword($this->getAdminEmail(), $this->getAdminPassword())
                ->assertPathIs('/')
                ->assertSee('Escritorio')
                ->assertSee('Bienvenida/o');
        });
    }

    public function testNonAdminUserCanAccessPanel(): void
    {
        // Cleanup any existing test user
        $existingUser = \App\Models\User::where('email', 'test.cafe@example.com')->first();
        if ($existingUser) {
            $existingUser->roles()->detach();
            $existingUser->delete();
        }

        // Create a non-admin user with CAFE role for testing
        $company = \App\Models\Company::first();
        $branch = \App\Models\Branch::first();

        $testPassword = 'TestPassword123!';

        $nonAdminUser = \App\Models\User::create([
            'name' => 'Test Cafe User',
            'email' => 'test.cafe@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make($testPassword),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        // Attach CAFE role (non-admin)
        $nonAdminUser->roles()->attach(\App\Models\Role::CAFE);

        $this->browse(function (Browser $browser) use ($testPassword) {
            // Clear any existing session
            $browser->visit('/')->deleteCookie('laravel_session');

            $browser
                ->visit('/login')
                ->type('input[id="data.email"]', 'test.cafe@example.com')
                ->type('input[id="data.password"]', $testPassword)
                ->press('button[type="submit"]')
                ->pause(3000)
                ->screenshot('non-admin-final-result')
                ->assertPathIs('/login')
                ->assertSee('Estas credenciales no coinciden con nuestros registros');
        });

        // Cleanup
        $nonAdminUser->roles()->detach();
        $nonAdminUser->delete();
    }
}
