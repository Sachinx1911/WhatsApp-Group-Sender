<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['email' => 'admin@educationhub.local', 'password' => 'correct-password']);
    }

    public function test_login_page_renders_for_guests(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSeeLivewire(Login::class)
            ->assertSee('Welcome back')
            ->assertSee('Sign in');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_signed_in_admin_visiting_login_is_sent_to_dashboard(): void
    {
        $this->actingAs($this->admin())->get('/login')->assertRedirect(route('dashboard'));
    }

    public function test_admin_can_sign_in(): void
    {
        $admin = $this->admin();

        Livewire::test(Login::class)
            ->set('email', 'admin@educationhub.local')
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->admin();

        Livewire::test(Login::class)
            ->set('email', 'admin@educationhub.local')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email')
            ->assertSet('password', '');

        $this->assertGuest();
    }

    public function test_unknown_email_is_rejected_with_the_same_message(): void
    {
        $this->admin();

        Livewire::test(Login::class)
            ->set('email', 'someone@else.local')
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasErrors(['email'])
            ->assertSee(__('auth.failed'));

        $this->assertGuest();
    }

    public function test_input_is_validated(): void
    {
        Livewire::test(Login::class)
            ->call('login')
            ->assertHasErrors(['email' => 'required', 'password' => 'required']);

        Livewire::test(Login::class)
            ->set('email', 'not-an-email')
            ->set('password', 'x')
            ->call('login')
            ->assertHasErrors(['email' => 'email']);
    }

    public function test_login_is_locked_after_too_many_failed_attempts(): void
    {
        $this->admin();

        for ($i = 0; $i < Login::MAX_ATTEMPTS; $i++) {
            Livewire::test(Login::class)
                ->set('email', 'admin@educationhub.local')
                ->set('password', 'wrong-password')
                ->call('login');
        }

        // Even the correct password is refused while locked out.
        Livewire::test(Login::class)
            ->set('email', 'admin@educationhub.local')
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasErrors('email')
            ->assertSee('Too many login attempts');

        $this->assertGuest();
    }

    public function test_remember_me_sets_a_remember_token(): void
    {
        $admin = $this->admin();

        Livewire::test(Login::class)
            ->set('email', 'admin@educationhub.local')
            ->set('password', 'correct-password')
            ->set('remember', true)
            ->call('login');

        $this->assertNotNull($admin->fresh()->remember_token);
    }

    public function test_user_is_sent_to_the_page_they_originally_requested(): void
    {
        $this->admin();
        $this->get('/'); // stores the intended URL

        Livewire::test(Login::class)
            ->set('email', 'admin@educationhub.local')
            ->set('password', 'correct-password')
            ->call('login')
            ->assertRedirect(url('/'));
    }

    public function test_admin_can_log_out(): void
    {
        $this->actingAs($this->admin())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_logout_requires_post(): void
    {
        $this->actingAs($this->admin())->get('/logout')->assertMethodNotAllowed();

        $this->assertAuthenticated();
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
