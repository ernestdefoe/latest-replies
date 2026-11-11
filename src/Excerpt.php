<?php

namespace ErnestDefoe\LatestReplies;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Plain text for an excerpt, by ALLOW-LIST.
 *
 * Built from the post's contentHtml exactly as the API gave it to this viewer
 * (other extensions' redactions already applied), and only the words of the
 * elements below survive: spoilers, code, quotes, embeds, details, hidden
 * content and anything an extension invents later are dropped whole, text and
 * all. Unknown means hidden.
 */
final class Excerpt
{
    /** Elements whose own text is the reply's words. */
    private const KEEP = [
        'body', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins', 'mark',
        'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /** Block elements: a space between them so words don't run together. */
    private const BLOCK = ['p', 'br', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public static function make(?string $html, int $length): ?string
    {
        if ($length <= 0 || $html === null || trim($html) === '') {
            return null;
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // No network, no entities: the meta tag only tells libxml the bytes are UTF-8.
        $doc->loadHTML('<meta charset="utf-8"><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);
        $text = $body ? self::text($body) : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $length * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t,.;:-–—").'…';
    }

    private static function text(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= $child->textContent;
            } elseif ($child instanceof DOMElement) {
                $name = strtolower($child->tagName);

                // Emoji pictures carry their character as alt text.
                if ($name === 'img' && str_contains(' '.$child->getAttribute('class').' ', ' emoji ')) {
                    $out .= $child->getAttribute('alt');
                    continue;
                }

                if (! in_array($name, self::KEEP, true) || self::concealed($child)) {
                    continue;
                }

                $inner = self::text($child);
                $out .= in_array($name, self::BLOCK, true) ? ' '.$inner.' ' : $inner;
            }
        }

        return $out;
    }

    /**
     * An allowed element that is itself hidden or marked as a spoiler: dropped like any other.
     */
    private static function concealed(DOMElement $el): bool
    {
        if ($el->hasAttribute('hidden') || strtolower($el->getAttribute('aria-hidden')) === 'true') {
            return true;
        }

        $style = strtolower(str_replace(' ', '', $el->getAttribute('style')));
        if (str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden')) {
            return true;
        }

        return str_contains(strtolower($el->getAttribute('class')), 'spoiler');
    }
}
