<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

uses(RefreshDatabase::class);

dataset('locales', [['en', 'ltr'], ['ar', 'rtl']]);

describe('layout', function () {
    it('renders login, register and forgot password in the brand shell, in both languages', function (string $route, string $locale, string $dir) {
        $this->withSession(['locale' => $locale])->get(route($route))
            ->assertOk()
            ->assertSee("<html lang=\"{$locale}\" dir=\"{$dir}\"", false)
            ->assertSee('bg-navy', false)                               // brand panel
            ->assertSee('data-test="locale-switch-'.($locale === 'en' ? 'ar' : 'en').'"', false)
            ->assertSee('brand/lamma-icon.svg', false);
    })->with(['login', 'register', 'password.request'])->with('locales');

    it('shows the tagline in the page language and the other language beside it', function () {
        $this->withSession(['locale' => 'en'])->get(route('login'))
            ->assertSee('The quiz night for everyone.')
            ->assertSee('lang="ar" dir="rtl"', false)->assertSee('سهرة الأسئلة للكل');

        $this->withSession(['locale' => 'ar'])->get(route('login'))
            ->assertSee('سهرة الأسئلة للكل.')
            ->assertSee('lang="en" dir="ltr"', false);
    });

    it('keeps the light-only look: no Flux dark-mode script', function () {
        $this->get(route('login'))->assertDontSee('flux.appearance', false);
        expect(Blade::render('<x-layouts::lamma>x</x-layouts::lamma>'))->not->toContain('flux.appearance');
    });
});

describe('login', function () {
    it('marks Log in as the current tab and links to Sign up', function () {
        $this->get(route('login'))
            ->assertSee('aria-current="page"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('Welcome back');

        $html = $this->get(route('login'))->getContent();
        expect($html)->toMatch('/aria-current="page"[^>]*>\s*Log in\s*</');
    });

    it('offers forgot password, remember me, a passkey button and the join-a-room link', function () {
        $this->get(route('login'))
            ->assertSee('href="'.route('password.request').'"', false)
            ->assertSee('name="remember"', false)
            ->assertSee('data-test="login-button"', false)
            ->assertSee('href="'.route('join').'"', false)
            ->assertSee("Joining a friend's game?")
            ->assertSee('Enter a room code');
    });

    it('is fully translated', function () {
        $this->withSession(['locale' => 'ar'])->get(route('login'))
            ->assertSee('أهلاً بعودتك')->assertSee('سجّل الآن')->assertSee('نسيت كلمة المرور؟')->assertSee('أدخل رمز الغرفة')
            ->assertDontSee('Welcome back')->assertDontSee('Forgot your password');
    });

    it('shows a failed login as an inline error with the email kept and the password cleared', function () {
        $user = User::factory()->create();

        $this->followingRedirects()->from(route('login'))
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertOk()
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('id="field-email-error"', false)
            ->assertSee('value="'.$user->email.'"', false)
            ->assertDontSee('wrong-password');
        $this->assertGuest();
    });

    it('still signs users in', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    });
});

describe('register', function () {
    it('marks Sign up as the current tab and has every field', function () {
        $html = $this->get(route('register'))->assertOk()->getContent();

        expect($html)->toMatch('/aria-current="page"[^>]*>\s*Sign up\s*</');
        foreach (['name', 'email', 'password', 'password_confirmation'] as $field) {
            expect($html)->toContain('name="'.$field.'"');
        }
        expect($html)->toContain('data-test="register-user-button"')->toContain('passwordrules=');
    });

    it('shows validation errors inline, keeps the typed values, never echoes passwords', function () {
        $this->followingRedirects()->from(route('register'))
            ->post(route('register.store'), ['name' => 'Sara', 'email' => 'not-an-email', 'password' => 'secret-pass-1', 'password_confirmation' => 'different'])
            ->assertOk()
            ->assertSee('value="Sara"', false)
            ->assertSee('value="not-an-email"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('secret-pass-1')->assertDontSee('different');
    });
});

describe('forgot password', function () {
    it('asks for an email and links back to log in', function () {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('name="email"', false)
            ->assertSee('data-test="email-password-reset-link-button"', false)
            ->assertSee('href="'.route('login').'"', false);
    });

    it('shows the status message after the link is sent', function () {
        $this->withSession(['status' => 'We have emailed your password reset link.'])->get(route('password.request'))
            ->assertSee('role="status"', false)->assertSee('We have emailed your password reset link.');
    });
});

describe('field component', function () {
    it('wires the label, the error and aria for a field with an error', function () {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['email' => ['That email is taken.']]));
        view()->share('errors', $errors);

        $html = Blade::render('<x-lamma.field name="email" type="email" label="Email" />');

        expect($html)->toContain('for="field-email"')->toContain('id="field-email"')
            ->toContain('aria-invalid="true"')->toContain('aria-describedby="field-email-error"')
            ->toContain('That email is taken.')->toContain('border-coral-700')->toContain('text-coral-700');
    });

    it('is ltr for emails and passwords, and offers a show/hide toggle when viewable', function () {
        $html = Blade::render('<x-lamma.field name="password" type="password" label="Password" viewable />');

        expect($html)->toContain('dir="ltr"')->toContain('x-bind:type="show ')->toContain('aria-label="Show password"')->toContain('pe-14');
        expect(Blade::render('<x-lamma.field name="name" label="Name" dir="auto" />'))->not->toContain('<div class="relative" dir="ltr"');
    });

    it('never prints a password value back', function () {
        expect(Blade::render('<x-lamma.field name="password" type="password" label="P" value="hunter2" />'))->not->toContain('hunter2');
        expect(Blade::render('<x-lamma.field name="name" label="N" value="Sara" />'))->toContain('value="Sara"');
    });
});

describe('segmented links', function () {
    it('renders options with an href as links and marks the current one', function () {
        $html = Blade::render('<x-lamma.segmented selected="b" :options="$o" />', ['o' => ['a' => ['label' => 'A', 'href' => '/a'], 'b' => ['label' => 'B', 'href' => '/b']]]);

        expect($html)->toContain('href="/a"')->toContain('href="/b"')->not->toContain('<button')
            ->and(substr_count($html, 'aria-current="page"'))->toBe(1)
            ->and($html)->toMatch('/aria-current="page"[^>]*>B</');
    });
});

describe('other auth pages', function () {
    it('renders confirm password in the shell', function () {
        $this->actingAs(User::factory()->create())->get(route('password.confirm'))
            ->assertOk()->assertSee('data-test="confirm-password-button"', false)->assertSee('bg-navy', false);
    });

    it('renders email verification in the shell', function () {
        Route::has('verification.notice') || $this->markTestSkipped('Email verification is not enabled.');

        $this->actingAs(User::factory()->unverified()->create())->get(route('verification.notice'))
            ->assertOk()->assertSee('data-test="logout-button"', false)->assertSee('bg-navy', false);
    });

    it('renders the reset password form with the token and email', function () {
        $this->get(route('password.reset', ['token' => 'abc123']).'?email=a@b.co')
            ->assertOk()->assertSee('name="token" value="abc123"', false)->assertSee('value="a@b.co"', false)
            ->assertSee('data-test="reset-password-button"', false);
    });
});
