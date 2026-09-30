<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * WhatsApp text formatting: *bold*, _italic_, ~strikethrough~, ```monospace``` and `inline code`.
 * Mirrors window.formatWhatsApp() in resources/js/app.js, which renders the live preview.
 */
class WhatsAppFormatter
{
    /** Longest message a template or campaign may hold. */
    public const MAX_LENGTH = 4096;

    /** Escaped HTML with WhatsApp formatting and line breaks applied. Safe to output unescaped. */
    public static function toHtml(?string $text): string
    {
        $html = e((string) $text);

        $html = preg_replace('/```([\s\S]+?)```/u', '<code class="wa-mono">$1</code>', $html);
        $html = preg_replace('/`([^`\n]+)`/u', '<code class="wa-code">$1</code>', $html);

        foreach (['\*' => 'strong', '_' => 'em', '~' => 'del'] as $marker => $tag) {
            $html = preg_replace(
                '/(^|[\s(>\[.,!?:;"\'])'.$marker.'(?=\S)(.+?)(?<=\S)'.$marker.'(?=$|[\s)<\].,!?:;"\'])/mu',
                '$1<'.$tag.'>$2</'.$tag.'>',
                $html,
            );
        }

        return nl2br($html, false);
    }

    /** Text without formatting markers, collapsed to one line (for table excerpts). */
    public static function plain(?string $text, int $limit = 0): string
    {
        $plain = Str::squish(preg_replace('/[*_~`]/u', '', (string) $text));

        return $limit > 0 ? Str::limit($plain, $limit) : $plain;
    }

    /** Characters as a person counts them (an emoji counts once), matching the JS counter. */
    public static function length(?string $text): int
    {
        return (int) (grapheme_strlen((string) $text) ?: mb_strlen((string) $text));
    }
}
