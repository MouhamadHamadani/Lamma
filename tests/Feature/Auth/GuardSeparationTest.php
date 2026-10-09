<?php

use App\Models\Admin;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('sends guests of /admin to the admin login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('does not let a logged-in user reach /admin', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertRedirect('/admin/login');
    $this->actingAs($user)->get('/admin/questions')->assertRedirect('/admin/login');
});

it('lets an admin into /admin', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
});

it('does not log an admin in on the user side', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin');
    Auth::shouldUse('web'); // actingAs() switches the default guard; a real request uses web

    $this->get('/me/games')->assertRedirect(route('login'));

    $this->assertAuthenticatedAs($admin, 'admin');
    $this->assertGuest('web');
});

it('does not log a user in on the admin side', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->assertAuthenticatedAs($user, 'web');
    $this->assertGuest('admin');
});

it('logs an admin in through the admin login page without touching the user guard', function () {
    $admin = Admin::factory()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($admin, 'admin');
    $this->assertGuest('web');
});

it('rejects user credentials on the admin login page', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest('admin');
});
