<?php

use App\Support\QrCode;

it('draws a scalable SVG in currentColor with no background, so a text colour and a backdrop style it', function () {
    $svg = QrCode::svg('http://localhost:8000/join?code=K7MP');

    expect($svg)->toStartWith('<svg')
        ->toContain('viewBox="0 0 256 256"')
        ->toContain('fill="currentColor"')
        ->not->toContain('<?xml')
        ->not->toContain('#ffffff')
        ->not->toContain('#000000')
        ->not->toMatch('/<svg[^>]* width="/');
});

it('encodes the text it is given', function () {
    expect(QrCode::svg('http://a.test/join?code=AAAAAA'))->not->toBe(QrCode::svg('http://a.test/join?code=BBBBBB'))
        ->and(QrCode::svg('http://a.test/join?code=AAAAAA'))->toBe(QrCode::svg('http://a.test/join?code=AAAAAA'));
});
