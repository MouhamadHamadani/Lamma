<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR codes as inline SVG (no GD or Imagick needed). simple-qrcode cannot be installed next to Fortify's BaconQrCode 3,
 * so this uses BaconQrCode directly, which is what simple-qrcode wraps.
 */
final class QrCode
{
    /**
     * Modules drawn in currentColor on a transparent background, sized by the parent (no width/height attributes):
     * colour it with a text-* class and give it a light backdrop with a margin for the quiet zone.
     */
    public static function svg(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(256, 0), new SvgImageBackEnd)))->writeString($text);

        return (string) preg_replace(
            ['/<\?xml[^>]*\?>\s*/', '/<rect [^>]*fill="#ffffff"[^>]*\/>/', '/ width="\d+" height="\d+"/', '/fill="#000000"/'],
            ['', '', '', 'fill="currentColor"'],
            $svg,
        );
    }
}
