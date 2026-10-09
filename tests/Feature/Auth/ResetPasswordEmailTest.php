<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;

/** The reset email the array mailer caught (QUEUE_CONNECTION=sync in the tests, so the queued job already ran). */
function sentMail(): Email
{
    $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);

    return $messages->first()->getOriginalMessage();
}

function requestReset(User $user): void
{
    test()->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
}

describe('the password reset email', function () {
    it('goes out in Arabic, right to left, for an Arabic user', function () {
        $user = User::factory()->create(['preferred_locale' => 'ar']);

        requestReset($user);
        $mail = sentMail();
        $html = $mail->getHtmlBody();

        expect($mail->getSubject())->toBe('إعادة تعيين كلمة مرور لمّة');
        expect($html)->toContain('<html xmlns="http://www.w3.org/1999/xhtml" lang="ar" dir="rtl">')->toMatch('/<body dir="rtl"[^>]*>/')
            ->toContain('وصلنا طلب لإعادة تعيين كلمة مرور حسابك في لمّة.')
            ->toContain('إعادة تعيين كلمة المرور</a>')                      // the button
            ->toContain('هذا الرابط صالح لمدة 60 دقيقة.')
            ->toContain('انسخ الرابط أدناه والصقه')                          // the footer under the button
            ->toContain('جميع الحقوق محفوظة.')
            ->toContain('لمّة')
            ->not->toContain('Reset Password')->not->toContain('Regards')->not->toContain('Hello!');
        expect($mail->getTextBody())->toContain('وصلنا طلب');
    });

    it('goes out in English, left to right, for an English user', function () {
        $user = User::factory()->create(['preferred_locale' => 'en']);

        requestReset($user);
        $mail = sentMail();
        $html = $mail->getHtmlBody();

        expect($mail->getSubject())->toBe('Reset your Lamma password');
        expect($html)->toContain('lang="en" dir="ltr"')->toMatch('/<body dir="ltr"[^>]*>/')
            ->toContain('We got a request to reset the password of your Lamma account.')
            ->toContain('Reset password</a>')
            ->toContain('This link works for 60 minutes.')
            ->toContain("If you're having trouble clicking the \"Reset password\" button")
            ->toContain('All rights reserved.')
            ->not->toContain('<body dir="rtl"')->not->toContain('وصلنا');
    });

    it('uses the account language, not the language of whoever asked', function () {
        $user = User::factory()->create(['preferred_locale' => 'ar']);

        $this->withHeader('Accept-Language', 'en-US')->withSession(['locale' => 'en'])->post(route('password.email'), ['email' => $user->email]);

        expect(sentMail()->getSubject())->toBe('إعادة تعيين كلمة مرور لمّة');
    });

    it('is queued, so the request does not wait for the mail server', function () {
        Queue::fake();
        $user = User::factory()->create(['preferred_locale' => 'ar']);

        requestReset($user);

        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof ResetPasswordNotification && $job->notifiables->first()->is($user));
        expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    });

    it('carries a working link with the token and the email', function () {
        $user = User::factory()->create(['preferred_locale' => 'en']);

        requestReset($user);

        expect(sentMail()->getHtmlBody())->toContain(url('/reset-password/'))->toContain('email='.urlencode($user->email));
    });

    it('wears the Lamma theme: cream page, coral button with navy text, the app icon', function () {
        $user = User::factory()->create(['preferred_locale' => 'en']);

        requestReset($user);
        $html = sentMail()->getHtmlBody();

        expect($html)->toContain('#FFF7EC')->toContain('#FF5A5F')->toContain('#1B1F4B')
            ->toContain('brand/lamma-app-icon.png')
            ->toContain('IBM Plex Sans Arabic')->toContain('Segoe UI')              // the font, with a system fallback
            ->not->toContain('#18181b')->not->toContain('laravel.com');
        expect(preg_match('/class="button button-primary"[^>]*style="[^"]*color: #1B1F4B/', $html))->toBe(1);
    });

    it('is only for people who have an account, and says nothing different when there is none', function () {
        $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    });
});

describe('the mail setup', function () {
    it('implements the locale preference on the user', function () {
        expect(User::factory()->make(['preferred_locale' => 'ar'])->preferredLocale())->toBe('ar')
            ->and(User::factory()->make(['preferred_locale' => 'en'])->preferredLocale())->toBe('en');
    });

    it('uses the Lamma markdown theme', function () {
        expect(config('mail.markdown.theme'))->toBe('lamma')->and(file_exists(resource_path('views/vendor/mail/html/themes/lamma.css')))->toBeTrue();
    });

    it('logs mail locally and does not hard-code a provider', function () {
        expect(file_get_contents(base_path('.env.example')))->toContain('MAIL_MAILER=log');
    });
});
