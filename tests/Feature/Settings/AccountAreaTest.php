<?php

use App\Enums\RoomStatus;
use App\Events\RoomClosed;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

dataset('locales', [['en', 'ltr'], ['ar', 'rtl']]);

/** The security page sits behind password.confirm: pretend the password was just confirmed. */
function confirmedSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

describe('the account pages', function () {
    it('render in English and Arabic with the right dir and the translated title', function (string $page, string $locale, string $dir) {
        $user = User::factory()->create(['preferred_locale' => $locale]);

        $html = $this->actingAs($user)->withSession([...confirmedSession(), 'locale' => $locale])->get(route($page))
            ->assertOk()
            ->assertSee("<html lang=\"{$locale}\" dir=\"{$dir}\"", false)
            ->assertSee($locale === 'ar' ? 'الإعدادات' : 'Settings')
            ->getContent();

        // Both tabs, the current one marked, and the shared header with the account menu.
        expect($html)->toContain('aria-current="page"')
            ->toContain('href="'.route('profile.edit').'"')->toContain('href="'.route('security.edit').'"')
            ->toContain('data-test="user-menu-button"')
            ->not->toContain('appearance');
        if ($locale === 'ar') {
            expect($html)->not->toContain('>Profile<')->not->toContain('>Security<')->not->toContain('>Save<');
        }
    })->with(['profile.edit', 'security.edit'])->with('locales');

    it('have no Appearance page and no dark mode', function () {
        $user = User::factory()->create();

        expect(Route::has('appearance.edit'))->toBeFalse();
        $this->actingAs($user)->get('/settings/appearance')->assertNotFound();
        $this->actingAs($user)->get(route('profile.edit'))->assertDontSee('Appearance')->assertDontSee('dark:', false);
    });

    it('use the Lamma shell, not the starter kit', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertSee('class="lamma ', false)->assertSee('brand/lamma-icon.svg', false)->assertDontSee('data-flux', false);
        expect(Route::has('dashboard'))->toBeFalse();
        $this->actingAs($user)->get('/dashboard')->assertNotFound();
    });

    it('keep the settings sections apart: profile has no password form, security has no profile form', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertSee('data-test="update-profile-button"', false)->assertSee('data-test="delete-user-button"', false)
            ->assertDontSee('data-test="update-password-button"', false);
        $this->actingAs($user)->withSession(confirmedSession())->get(route('security.edit'))->assertSee('data-test="update-password-button"', false)
            ->assertDontSee('data-test="update-profile-button"', false);
    });

    it('send guests to log in', function () {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->get(route('security.edit'))->assertRedirect(route('login'));
        $this->get('/settings')->assertRedirect(route('login'));
    });
});

describe('the profile', function () {
    it('saves the name and email and says so', function () {
        $user = User::factory()->create(['name' => 'Old', 'email' => 'old@example.com']);

        Livewire::actingAs($user)->test('pages::settings.profile')
            ->assertSet('name', 'Old')->assertSet('email', 'old@example.com')
            ->set('name', 'Sara Ahmad')->set('email', 'sara@example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors()->assertSet('saved', true)->assertSeeHtml('data-test="saved"')->assertSee('Profile updated.');

        expect($user->fresh())->name->toBe('Sara Ahmad')->email->toBe('sara@example.com');
    });

    it('validates the name and email', function () {
        $taken = User::factory()->create();

        Livewire::actingAs(User::factory()->create())->test('pages::settings.profile')
            ->set('name', '')->set('email', 'not-an-email')->call('updateProfileInformation')->assertHasErrors(['name', 'email'])
            ->set('name', 'Sara')->set('email', $taken->email)->call('updateProfileInformation')->assertHasErrors(['email']);
    });

    it('changes the preferred language, applies it to the session and reloads the page in that language', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['preferred_locale' => 'en']);

        Livewire::actingAs($user)->test('pages::settings.profile')
            ->assertSet('preferred_locale', 'en')
            ->set('preferred_locale', 'ar')->call('updateProfileInformation')
            ->assertHasNoErrors()->assertRedirect(route('profile.edit'));

        expect($user->fresh()->preferred_locale)->toBe('ar')->and(session('locale'))->toBe('ar');

        // The reload: the user's language is Arabic now, and the form confirms the save in it.
        $this->actingAs($user)->withSession(['locale' => 'ar', 'profile-saved' => true])->get(route('profile.edit'))
            ->assertSee('<html lang="ar" dir="rtl"', false)->assertSee('تم تحديث الملف الشخصي.');
    });

    it('keeps the page (no reload) when the language did not change', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['preferred_locale' => 'en']);

        Livewire::actingAs($user)->test('pages::settings.profile')->set('name', 'Sara')->call('updateProfileInformation')->assertNoRedirect()->assertSet('saved', true);
    });

    it('only accepts a supported language', function () {
        $user = User::factory()->create(['preferred_locale' => 'en']);

        Livewire::actingAs($user)->test('pages::settings.profile')->set('preferred_locale', 'fr')->call('updateProfileInformation')->assertHasErrors(['preferred_locale']);
        expect($user->fresh()->preferred_locale)->toBe('en');
    });

    it('offers both languages as a segmented control bound to the form', function () {
        $this->actingAs(User::factory()->create())->get(route('profile.edit'))
            ->assertSee('العربية')->assertSee('English')->assertSee('preferred_locale', false);
    });
});

describe('the password', function () {
    it('changes with the current one and says so', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::settings.security')
            ->set('current_password', 'password')->set('password', 'new-password')->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasNoErrors()->assertSet('passwordSaved', true)->assertSee('Password updated.');

        expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
    });

    it('is refused with the wrong current password, and the fields are cleared', function () {
        Livewire::actingAs(User::factory()->create())->test('pages::settings.security')
            ->set('current_password', 'wrong')->set('password', 'new-password')->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasErrors(['current_password'])->assertSet('password', '')->assertSet('passwordSaved', false);
    });
});

describe('two-factor authentication', function () {
    it('is enabled, shown as a QR code and a setup key, and confirmed with a six-digit code', function () {
        $user = User::factory()->create();

        $setup = Livewire::actingAs($user)->test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
            ->dispatch('start-two-factor-setup')
            ->assertSeeHtml('<svg')->assertSeeHtml('data-test="setup-key"')->assertSee('Enable two-factor authentication');

        $secret = decrypt($user->fresh()->two_factor_secret);
        expect($setup->get('manualSetupKey'))->toBe($secret);

        $setup->call('showVerificationIfNecessary')
            ->assertSet('showVerificationStep', true)->assertSee('Verify authentication code')
            ->assertSeeHtml('inputmode="numeric"')->assertSeeHtml('autocomplete="one-time-code"')->assertSeeHtml('maxlength="6"')->assertSeeHtml('dir="ltr"')
            ->set('code', '000')->call('confirmTwoFactor')->assertHasErrors(['code'])
            ->set('code', app(Google2FA::class)->getCurrentOtp($secret))->call('confirmTwoFactor')
            ->assertHasNoErrors()->assertDispatched('two-factor-enabled');

        expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
    });

    it('rejects a wrong code', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
            ->dispatch('start-two-factor-setup')->call('showVerificationIfNecessary')
            ->set('code', '123456')->call('confirmTwoFactor')->assertHasErrors();

        expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
    });

    it('shows the recovery codes, regenerates them, and is disabled again', function () {
        $user = User::factory()->withTwoFactor()->create();

        $codes = Livewire::actingAs($user)->test('pages::settings.two-factor.recovery-codes')
            ->assertSee('recovery-code-1')->assertSee('2FA recovery codes')->assertSeeHtml('aria-expanded');
        $before = $codes->get('recoveryCodes');

        $codes->call('regenerateRecoveryCodes');
        expect($codes->get('recoveryCodes'))->not->toBe($before)->and($codes->get('recoveryCodes'))->not->toBeEmpty();

        Livewire::actingAs($user)->test('pages::settings.security')->assertSet('twoFactorEnabled', true)->assertSee('Disable 2FA')
            ->call('disable')->assertSet('twoFactorEnabled', false)->assertSee('Enable 2FA');
        expect($user->fresh())->two_factor_secret->toBeNull()->two_factor_confirmed_at->toBeNull();
    });
});

describe('passkeys', function () {
    it('keep their routes', function () {
        foreach (['passkey.login-options', 'passkey.login', 'passkey.registration-options', 'passkey.store', 'passkey.destroy', 'passkey.confirm', 'well-known.passkeys'] as $name) {
            expect(Route::has($name))->toBeTrue($name);
        }
        $this->getJson('/.well-known/passkey-endpoints')->assertOk()->assertExactJson(['enroll' => route('security.edit'), 'manage' => route('security.edit')]);
        $this->getJson(route('passkey.login-options'))->assertOk();
    });

    it('are listed, and removed after confirming', function () {
        $user = User::factory()->create();
        $mine = $user->passkeys()->create(['name' => 'Sara iPhone', 'credential_id' => 'cred-1', 'credential' => ['id' => 'cred-1']]);
        $user->passkeys()->create(['name' => 'Old laptop', 'credential_id' => 'cred-2', 'credential' => ['id' => 'cred-2']]);

        $page = Livewire::actingAs($user)->test('pages::settings.security')
            ->assertSee('Sara iPhone')->assertSee('Old laptop')->assertSeeHtml('data-test="remove-passkey"')->assertDontSee('No passkeys yet');

        $page->call('confirmDelete', $mine->id)->assertSet('deletingPasskeyName', 'Sara iPhone')->assertSee('Are you sure you want to remove the passkey "Sara iPhone"?')
            ->call('deletePasskey')->assertDispatched('close-dialog');

        expect($user->passkeys()->pluck('name')->all())->toBe(['Old laptop']);
        $page->assertDontSee('Sara iPhone')->assertSet('deletingPasskeyId', null);
    });

    it('cannot be removed by someone else', function () {
        $owner = User::factory()->create();
        $passkey = $owner->passkeys()->create(['name' => 'Mine', 'credential_id' => 'cred-9', 'credential' => ['id' => 'cred-9']]);

        Livewire::actingAs(User::factory()->create())->test('pages::settings.security')->call('confirmDelete', $passkey->id)->assertStatus(404)->assertSet('deletingPasskeyId', null);
        expect($owner->passkeys()->count())->toBe(1);
    });
});

describe('deleting the account', function () {
    beforeEach(function () {
        Event::fake();
        Queue::fake();
    });

    it('needs the right password', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::settings.delete-user-modal')->set('password', 'wrong')->call('deleteUser')->assertHasErrors(['password']);
        expect($user->fresh())->not->toBeNull();
    });

    it('asks for the password in a dialog', function () {
        $this->actingAs(User::factory()->create())->get(route('profile.edit'))
            ->assertSeeHtml('<dialog')->assertSeeHtml('aria-labelledby="dialog-delete-account-title"')->assertSee('Are you sure you want to delete your account?')
            ->assertSeeHtml('name="password"')->assertSeeHtml('data-test="confirm-delete-user-button"');
    });

    it('keeps a finished game intact for the other players when its host deletes the account', function () {
        $host = User::factory()->create();
        $room = finishedGame(['Ann' => 300, 'Bob' => 200, 'Cy' => 100], host: $host);
        $answers = $room->roomQuestions()->count();

        Livewire::actingAs($host)->test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');

        expect(User::find($host->id))->toBeNull();
        $room = Room::findOrFail($room->id);
        expect($room->host_id)->toBeNull()->and($room->status)->toBe(RoomStatus::Finished)->and($room->isCompleted())->toBeTrue()
            ->and($room->roomQuestions()->count())->toBe($answers)
            ->and($room->players()->orderByDesc('score')->pluck('score', 'nickname')->all())->toBe(['Ann' => 300, 'Bob' => 200, 'Cy' => 100]);
    });

    it('keeps the games a player played: the row stays, only the account link goes', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$room, $mine] = savedGame($user, score: 500, rivals: ['Rival' => 100]);
        $rival = $room->players()->where('nickname', 'Rival')->firstOrFail();
        $rival->update(['user_id' => $other->id]);

        Livewire::actingAs($user)->test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertHasNoErrors();

        expect(RoomPlayer::find($mine->id))->user_id->toBeNull()->score->toBe(500)->nickname->toBe('Me'.$user->id);
        expect(RoomPlayer::find($rival->id))->user_id->toBe($other->id)->score->toBe(100);
        expect(Room::find($room->id)->isCompleted())->toBeTrue();
        // The other player still sees the game in their history.
        $this->actingAs($other)->get(route('me.games'))->assertOk()->assertSee('data-rank', false);
    });

    it('closes the lobby or game the user is hosting, through RoomManager, so phones are told', function () {
        $host = User::factory()->create();
        $lobby = gameRoom(host: $host);

        Livewire::actingAs($host)->test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertHasNoErrors();

        $lobby = Room::findOrFail($lobby->id);
        expect($lobby->status)->toBe(RoomStatus::Finished)->and($lobby->host_id)->toBeNull()->and($lobby->players()->count())->toBe(2);
        Event::assertDispatched(RoomClosed::class);
    });

    it('closes a game in progress too', function () {
        $host = User::factory()->create();
        $room = gameRoom(host: $host);
        startGame($room);

        Livewire::actingAs($host)->test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser');

        expect(Room::findOrFail($room->id)->status)->toBe(RoomStatus::Finished);
    });
});

describe('no Flux', function () {
    it('has no <flux: component in any view', function () {
        $offenders = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_contains($file->getContents(), '<flux:') || str_contains($file->getContents(), '</flux:') || str_contains($file->getContents(), '@flux'))
            ->map(fn ($file) => $file->getRelativePathname())->values()->all();

        expect($offenders)->toBe([]);
    });

    it('is gone from composer and the assets', function () {
        expect(file_get_contents(base_path('composer.json')))->not->toContain('livewire/flux');
        expect(file_get_contents(resource_path('css/app.css')))->not->toContain('flux');
        expect(File::exists(resource_path('views/flux')))->toBeFalse();
        expect(class_exists(Flux\Flux::class))->toBeFalse();
    });
});

describe('the user menu', function () {
    it('is the same header on the landing page, My games and both settings pages', function (string $url) {
        $user = User::factory()->create(['name' => 'Sara Ahmad']);

        $html = $this->actingAs($user)->withSession(confirmedSession())->get($url)->assertOk()->getContent();

        expect($html)->toContain('data-test="user-menu-button"')
            ->toContain('aria-expanded="false"')->toContain('x-bind:aria-expanded="open"')
            ->toContain('aria-label="Account menu: Sara Ahmad"')
            ->toContain('x-on:keydown.escape.window')        // Escape closes it and gives focus back to the button
            ->toContain('href="'.route('me.games').'"')->toContain('href="'.route('rooms.create').'"')->toContain('href="'.route('profile.edit').'"')
            ->toContain('action="'.route('logout').'"');
    })->with(['/', '/me/games', '/settings/profile', '/settings/security']);

    it('is not shown to guests', function () {
        $this->get('/')->assertDontSee('data-test="user-menu-button"', false);
    });

    it('is translated and mirrored in Arabic', function () {
        $this->actingAs(User::factory()->create(['preferred_locale' => 'ar']))->withSession(['locale' => 'ar'])->get('/me/games')
            ->assertSee('قائمة الحساب')->assertSee('ألعابي')->assertSee('استضف لعبة')->assertSee('الإعدادات')->assertSee('تسجيل الخروج');
    });
});

describe('logging in and registering', function () {
    it('lands on My games, or on the page the visitor was after', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('me.games', absolute: false));
        $this->post(route('logout'));

        $this->get(route('rooms.create'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('rooms.create'));
    });

    it('sends a new account to My games', function () {
        $this->post(route('register.store'), ['name' => 'Sara', 'email' => 'sara@example.com', 'password' => 'a-Long-Passw0rd!', 'password_confirmation' => 'a-Long-Passw0rd!'])
            ->assertRedirect(route('me.games', absolute: false));
    });

    it('has no dashboard to fall back to', function () {
        expect(config('fortify.home'))->toBe('/me/games');
    });
});

describe('the two-factor challenge', function () {
    it('has a Lamma code field with a recovery code switch, in both languages', function (string $locale) {
        $user = User::factory()->withTwoFactor()->create();

        $html = $this->withSession(['locale' => $locale, 'login.id' => $user->id, 'login.remember' => false])->get(route('two-factor.login'))->assertOk()->getContent();

        expect($html)->toContain('inputmode="numeric"')->toContain('autocomplete="one-time-code"')->toContain('maxlength="6"')->toContain('dir="ltr"')
            ->toContain('name="code"')->toContain('name="recovery_code"')->toContain('toggleInput()')
            ->not->toContain('flux');
        expect($html)->toContain($locale === 'ar' ? 'سجّل الدخول باستخدام رمز استرداد' : 'login using a recovery code');
    })->with(['en', 'ar']);
});
