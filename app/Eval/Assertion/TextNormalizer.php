<?php

declare(strict_types=1);

namespace App\Eval\Assertion;

/**
 * Normalises text for tolerant string-match assertions.
 *
 * The LLM routinely wraps words in Markdown emphasis (**bold**, *em*)
 * and uses smart quotes (U+2019 ’ / U+201C “ / U+201D ”) where our
 * golden-case assertions use ASCII apostrophes. Raw stripos fails
 * those comparisons even though the content is identical.
 *
 * Normalisation applied:
 *   - smart quotes  → ASCII equivalents
 *   - em / en dash → "-"
 *   - non-breaking space and zero-width chars → regular space / dropped
 *   - Markdown emphasis markers around words (**word**, *word*) stripped
 *   - whitespace collapsed to single ASCII spaces
 */
final class TextNormalizer
{
    public static function normalize(string $input): string
    {
        // Smart quotes → ASCII.
        $replacements = [
            "\u{2018}" => "'",  // left single quotation mark
            "\u{2019}" => "'",  // right single quotation mark
            "\u{201A}" => "'",  // single low-9
            "\u{201B}" => "'",  // single high-reversed-9
            "\u{201C}" => '"',  // left double quotation mark
            "\u{201D}" => '"',  // right double quotation mark
            "\u{201E}" => '"',  // double low-9
            "\u{2013}" => '-',  // en dash
            "\u{2014}" => '-',  // em dash
            "\u{2026}" => '...',// ellipsis
            "\u{00A0}" => ' ',  // non-breaking space
            "\u{200B}" => '',   // zero-width space
            "\u{FEFF}" => '',   // BOM / zero-width no-break space
        ];

        $out = strtr($input, $replacements);

        // Strip simple Markdown emphasis around non-space runs.
        // Handles **word**, __word__, *word*, _word_ without removing
        // meaningful asterisks inside URLs.
        $out = preg_replace('/\*\*([^*\n]+?)\*\*/', '$1', $out) ?? $out;
        $out = preg_replace('/__([^_\n]+?)__/', '$1', $out) ?? $out;
        $out = preg_replace('/(?<![A-Za-z0-9])\*([^*\n]+?)\*(?![A-Za-z0-9])/', '$1', $out) ?? $out;
        $out = preg_replace('/(?<![A-Za-z0-9])_([^_\n]+?)_(?![A-Za-z0-9])/', '$1', $out) ?? $out;

        // Collapse whitespace.
        $out = preg_replace('/\s+/', ' ', $out) ?? $out;

        return trim($out);
    }
}
