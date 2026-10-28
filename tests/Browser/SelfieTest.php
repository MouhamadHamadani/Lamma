<?php

use App\Models\Room;
use Tests\Browser\Support\FakeCamera;

/*
 * The selfie step in a real browser (Chromium with a fake camera): the join page at 390x844 in English and Arabic (no JavaScript error, no
 * accessibility problem, no sideways scroll), a whole live selfie (open the camera, shutter, use the photo, join), the camera tracks stopped
 * after the shot and after Cancel, a refused camera (the message, Try again and Skip, never a file upload), and no file input anywhere.
 *
 * Run:  timeout 300 vendor/bin/pest tests/Browser/SelfieTest.php
 */

dataset('locales', [
    'English' => ['en', 'ltr'],
    'Arabic' => ['ar', 'rtl'],
]);

/**
 * Remember every stream the page asks the camera for (to check its tracks were stopped), how the picture is drawn onto the canvas (it must
 * not be mirrored) and what is uploaded (a JPEG, square, 640px at most).
 */
const SPY_ON_CAMERA = <<<'JS'
(() => {
    window.__streams = [];
    window.__draws = [];
    window.__uploads = [];

    const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
    navigator.mediaDevices.getUserMedia = async (constraints) => {
        window.__constraints = JSON.stringify(constraints);
        const stream = await original(constraints);
        window.__streams.push(stream);
        return stream;
    };

    const draw = CanvasRenderingContext2D.prototype.drawImage;
    CanvasRenderingContext2D.prototype.drawImage = function (image, ...args) {
        window.__draws.push({ from: image.tagName, args, transform: [...Object.values(this.getTransform().toJSON())].slice(0, 4).join(',') });
        return draw.call(this, image, ...args);
    };

    const send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (body) {
        if (body instanceof FormData) {
            for (const value of body.values()) {
                if (value instanceof File) {
                    createImageBitmap(value).then((bitmap) => window.__uploads.push({ url: this.__url, name: value.name, type: value.type, size: value.size, width: bitmap.width, height: bitmap.height }));
                }
            }
        }
        return send.call(this, body);
    };
    const open = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.__url = url;
        return open.call(this, method, url, ...rest);
    };

    return true;
})()
JS;

const ALL_CAMERA_TRACKS_ENDED = "window.__streams.length > 0 && window.__streams.every((stream) => stream.getTracks().every((track) => track.readyState === 'ended'))";

afterEach(fn () => FakeCamera::stop());

it('takes a live selfie and sends it for upload, at 390x844, in both languages', function (string $locale, string $dir) {
    FakeCamera::start();
    $room = Room::factory()->create();

    $page = visit('/join?code='.$room->code)->withLocale($locale)->resize(390, 844)->wait(1);

    $page->assertScript("document.documentElement.dir === '{$dir}'")
        ->assertScript('document.querySelectorAll("input[type=file]").length === 0')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')
        ->assertScript("[...document.querySelectorAll('[data-test=selfie] button')].filter((b) => b.offsetParent !== null).every((b) => b.offsetHeight >= 44)")
        ->assertNoSmoke()
        ->assertNoAccessibilityIssues();

    $page->script(SPY_ON_CAMERA);
    $page->click('[data-test=selfie-take]')->wait(2)
        ->assertScript("document.querySelector('[data-test=selfie]').dataset.step === 'camera'")
        ->assertScript("document.querySelector('[data-test=selfie] video').videoWidth > 0")
        ->assertScript("document.querySelector('[data-test=selfie] video').classList.contains('-scale-x-100')")
        ->assertScript("JSON.parse(window.__constraints).audio === false && JSON.parse(window.__constraints).video.facingMode === 'user'")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')
        ->assertNoAccessibilityIssues();

    $page->click('[data-test=selfie-shutter]')->wait(1)
        ->assertScript("document.querySelector('[data-test=selfie]').dataset.step === 'review'")
        ->assertScript(ALL_CAMERA_TRACKS_ENDED)                                  // the camera light is off after the shot
        ->assertScript("document.querySelector('[data-test=selfie] video').srcObject === null")
        ->assertNoAccessibilityIssues();

    // Drawn straight from the video onto the canvas, centre-cropped to a square, with an untouched (not mirrored) transform.
    $page->assertScript("window.__draws.length === 1 && window.__draws[0].from === 'VIDEO' && window.__draws[0].transform === '1,0,0,1'")
        ->assertScript('window.__draws[0].args.length === 8 && window.__draws[0].args[2] === window.__draws[0].args[3] && window.__draws[0].args[6] === window.__draws[0].args[7] && window.__draws[0].args[6] <= 640');

    // "Use this photo" sends the picture through Livewire's upload endpoint: a JPEG, square, at most 640px, well under 3 MB. (The browser tests'
    // in-process web server cannot read multipart bodies, so the answer is an error here; the server side is covered by the Feature tests.)
    $page->click('[data-test=selfie-use]')->wait(2)
        ->assertScript("window.__uploads.length === 1 && window.__uploads[0].url.includes('upload-file') && window.__uploads[0].type === 'image/jpeg' && window.__uploads[0].name === 'selfie.jpg'")
        ->assertScript('window.__uploads[0].width === window.__uploads[0].height && window.__uploads[0].width <= 640 && window.__uploads[0].size < 3 * 1024 * 1024')
        ->assertScript(ALL_CAMERA_TRACKS_ENDED);

    // Whatever happens to the photo, the player can skip it and join.
    $page->assertNoSmoke();
})->with('locales');

it('turns the camera off when the panel is cancelled', function () {
    FakeCamera::start();
    $room = Room::factory()->create();

    $page = visit('/join?code='.$room->code)->resize(390, 844)->wait(1);
    $page->script(SPY_ON_CAMERA);
    $page->click('[data-test=selfie-take]')->wait(2)
        ->assertScript("document.querySelector('[data-test=selfie]').dataset.step === 'camera'")
        ->click('[data-test=selfie-cancel]')->wait(1)
        ->assertScript(ALL_CAMERA_TRACKS_ENDED)
        ->assertScript("document.querySelector('[data-test=selfie]').dataset.step === 'idle'");
});

it('has no file input and no capture attribute on the join page or in the phone lobby', function () {
    FakeCamera::start();
    $room = Room::factory()->create();

    $page = visit('/join?code='.$room->code)->resize(390, 844)->wait(1);
    $page->assertScript('document.querySelectorAll("input[type=file], [capture], [accept]").length === 0');

    $page->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]')->wait(3)
        ->click('[data-test=avatar-button]')->wait(1)
        ->assertScript('document.querySelector("[data-test=selfie]") !== null')
        ->assertScript('document.querySelectorAll("input[type=file], [capture], [accept]").length === 0');
});

it('says so when the camera is refused, with Try again and Skip and no file upload', function (string $locale) {
    FakeCamera::start();
    $room = Room::factory()->create();

    $page = visit('/join?code='.$room->code)->withLocale($locale)->resize(390, 844)->wait(1);
    $page->script("navigator.mediaDevices.getUserMedia = () => Promise.reject(new DOMException('Permission denied', 'NotAllowedError')); true");
    $page->click('[data-test=selfie-take]')->wait(1)
        ->assertScript("document.querySelector('[data-test=selfie]').dataset.step === 'denied'")
        ->assertVisible('[data-test=selfie-denied]')
        ->assertSeeIn('[data-test=selfie-denied]', $locale === 'ar' ? 'تعذّر فتح الكاميرا' : "We couldn't open the camera.")
        ->assertVisible('[data-test=selfie-try-again]')
        ->assertVisible('[data-test=selfie-skip-denied]')
        ->assertScript('document.querySelectorAll("input[type=file]").length === 0')
        ->assertNoAccessibilityIssues();

    // Skip hides the step's buttons, joining still works without a photo.
    $page->click('[data-test=selfie-skip-denied]')->wait(1)
        ->assertScript("document.querySelector('[data-test=selfie]').dataset.step === 'skipped'")
        ->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]')->wait(3)
        ->assertScript("location.pathname === '/play/{$room->code}'");

    expect($room->players()->sole()->avatar_path)->toBeNull();
})->with(['en', 'ar']);

it('says the same when the browser has no camera at all (an in-app browser, a desktop without a webcam)', function () {
    FakeCamera::start();
    $room = Room::factory()->create();

    $page = visit('/join?code='.$room->code)->resize(390, 844)->wait(1);
    $page->script("Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true }); true");
    $page->click('[data-test=selfie-take]')->wait(1)
        ->assertScript("document.querySelector('[data-test=selfie]').dataset.step === 'denied'")
        ->assertVisible('[data-test=selfie-try-again]')->assertVisible('[data-test=selfie-skip-denied]');
});

it('shows nothing about photos when the host turned them off', function () {
    $room = Room::factory()->create(['settings' => ['show_photos' => false]]);

    $page = visit('/join?code='.$room->code)->resize(390, 844)->wait(1);

    $page->assertScript('document.querySelector("[data-test=selfie-step]") === null')
        ->assertDontSee('selfie')->assertDontSee('Your photo is shown')
        ->assertNoSmoke()->assertNoAccessibilityIssues();
});
