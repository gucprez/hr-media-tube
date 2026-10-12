<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/administracion/usuarios')->assertRedirect('/login');
    }

    public function test_login_with_username_and_with_email(): void
    {
        $user = $this->makeUser($this->makeCompany());

        $this->post('/login', ['username' => $user->username, 'password' => 'Password123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        $this->post('/login', ['username' => $user->email, 'password' => 'Password123'])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $user->id]);
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        $user = $this->makeUser($this->makeCompany());

        $this->from('/login')->post('/login', ['username' => $user->username, 'password' => 'nope'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'login.failed']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->makeUser($this->makeCompany(), 'operator', ['is_active' => false]);

        $this->post('/login', ['username' => $user->username, 'password' => 'Password123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_user_of_inactive_company_is_kicked_out(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $company->update(['is_active' => false]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->makeUser($this->makeCompany());

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => $user->username, 'password' => 'bad']);
        }
        $this->post('/login', ['username' => $user->username, 'password' => 'Password123'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_password_is_hashed(): void
    {
        $user = $this->makeUser($this->makeCompany());
        $this->assertNotSame('Password123', $user->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Password123', $user->password));
    }
}
