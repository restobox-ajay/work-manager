<?php

declare(strict_types=1);

namespace App\Service\Text;

/**
 * Rich text from the in-page editor (ADR-118): an allowlist of formatting tags is kept, everything else is
 * dropped (scripts, styles, event attributes, iframes…) and links keep only an http(s)/mailto href. Used when a
 * task description is saved and again when it is shown, so older plain-text descriptions and anything that reached
 * the database another way are rendered safely too.
 */
final class RichText
{
    /** tag => allowed attributes */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'div' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [],
        'strike' => [], 'ul' => [], 'ol' => [], 'li' => [], 'h3' => [], 'h4' => [], 'blockquote' => [], 'pre' => [],
        'code' => [], 'a' => ['href'], 'hr' => [],
    ];
    /** Removed with their content (not just unwrapped). */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'head', 'title', 'meta', 'link', 'form', 'input', 'button', 'select', 'textarea'];

    /** Cleaned HTML for storage, or null when nothing visible is left (an empty editor posts "<p><br></p>"). */
    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        $out = self::sanitize(self::isPlain($html) ? self::plainToHtml($html) : $html);

        return trim(html_entity_decode(strip_tags($out), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{A0}") === '' ? null : $out;
    }

    /** Safe HTML to print: plain text keeps its line breaks, HTML goes through the allowlist. */
    public static function render(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return self::isPlain($value) ? self::plainToHtml($value) : self::sanitize($value);
    }

    public static function isPlain(string $value): bool
    {
        return !preg_match('#</?[a-z][a-z0-9]*(\s[^<>]*)?/?>#i', $value);
    }

    public static function plainToHtml(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
    }

    private static function sanitize(string $html): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="rt-root">'.$html.'</div></body></html>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('rt-root');
        if ($root === null) {
            return self::plainToHtml(strip_tags($html));
        }
        self::walk($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                continue;
            }
            if (!$child instanceof \DOMElement) { // comments, processing instructions
                $node->removeChild($child);
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child);
            if (in_array($tag, ['p', 'div', 'li', 'h3', 'h4', 'blockquote'], true) && trim($child->textContent, " \t\n\r\u{A0}") === '' && $child->getElementsByTagName('br')->length === 0) {
                $node->removeChild($child); // empty blocks the editor leaves behind
                continue;
            }
            if (!array_key_exists($tag, self::ALLOWED)) { // unknown tag: keep its content, lose the tag
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attribute) {
                if (!in_array(strtolower($attribute->name), self::ALLOWED[$tag], true)) {
                    $child->removeAttribute($attribute->name);
                }
            }
            if ($tag === 'a') {
                $href = trim($child->getAttribute('href'));
                if ($href === '' || !preg_match('#^(https?://|mailto:)#i', $href)) {
                    $child->removeAttribute('href');
                } else {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer nofollow');
                }
            }
        }
    }
}
