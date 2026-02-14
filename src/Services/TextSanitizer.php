<?php
declare(strict_types=1);

namespace App\Services;

final class TextSanitizer
{
    public static function htmlToText(?string $html): ?string
    {
        $html = trim((string)($html ?? ''));
        if ($html === '') return null;

        // Normalise les sauts de ligne HTML en vrais retours
        $html = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $html);
        $html = preg_replace('~</\s*p\s*>~i', "\n\n", $html);
        $html = preg_replace('~<\s*p[^>]*>~i', '', $html);

        // Supprime toutes les balises restantes
        $text = strip_tags($html);

        // Decode entities (&amp; etc.)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Nettoyage : espaces + lignes
        $text = preg_replace("/[ \t]+/", " ", $text);     // espaces multiples
        $text = preg_replace("/\n{3,}/", "\n\n", $text);  // max 2 sauts de ligne
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
