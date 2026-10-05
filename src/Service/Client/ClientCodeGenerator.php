<?php

declare(strict_types=1);

namespace App\Service\Client;

use App\Repository\ClientRepository;

/**
 * Suggests a unique three-letter client code from a client's name. Copied unchanged from work-platform's
 * ClientService (itself a port of the Yii2 Client::generateClientCode()), so both apps derive the same code
 * for the same name.
 */
final class ClientCodeGenerator
{
    public function __construct(private readonly ClientRepository $clientRepository)
    {
    }

    /**
     * Direct port of Client::generateClientCode() and its four naming
     * strategies + fallback, in the same order, same 3-letter-code contract.
     *
     * Two things the Yii2 original did not do, added because it emitted
     * values its own validator then refused ("Client code cannot contain
     * digits") and died on ordinary non-Latin input:
     *
     * 1. Every strategy now reads the *normalized* name
     *    (normalizeForCode()) rather than the raw one, so none of them can
     *    pick a digit, a hyphen or a space as a code character -- each one
     *    used to re-implement "take a character" with its own idea of what
     *    counted, and `substr($name, -1)` / a space-is-a-consonant bug
     *    meant "7 acme trading" produced "7AT" and "acme trading" produced
     *    "CM ".
     * 2. Whatever a strategy returns is validated once, here, by
     *    sanitizeCode(). A candidate that isn't 1-3 ASCII letters is
     *    discarded and the next strategy runs -- one guard that makes the
     *    whole class of invalid codes impossible regardless of which
     *    strategy produced it.
     */
    public function generate(string $name): string
    {
        $normalized = $this->normalizeForCode($name);

        $strategies = [
            $this->codeByWordSpace(...),
            $this->codeByCapitalLetter(...),
            $this->codeByRuleOne(...),
            $this->codeByRuleTwo(...),
        ];

        foreach ($strategies as $strategy) {
            $code = $this->sanitizeCode($strategy($normalized));
            if ($code !== null && !$this->clientRepository->clientCodeExists($code)) {
                return $code;
            }
        }

        return $this->fallbackCode($normalized);
    }

    /**
     * Reduces a client name to the only thing a code may be built from:
     * ASCII letters, with single spaces marking word boundaries.
     *
     * Accented letters are folded to their base letter (É -> E) rather
     * than dropped, so a name written in French contributes the letters it
     * actually has -- `[A-Z]` used to miss them entirely and silently pick
     * a different strategy. Everything else -- digits, punctuation,
     * emoji, and scripts with no ASCII fold such as Cyrillic or Han --
     * becomes a word boundary, which is also what stops the raw bytes ever
     * reaching json_encode(): slicing a multi-byte character in half with
     * substr() is what turned a Cyrillic company name into an HTTP 500.
     *
     * A name with no ASCII-foldable letter at all normalizes to "", which
     * every strategy declines and fallbackCode() handles.
     */
    private function normalizeForCode(string $name): string
    {
        $ascii = $this->foldToAscii($name);

        // One rule for every non-letter, so no strategy has to know about
        // digits, hyphens or whitespace individually.
        $ascii = (string) preg_replace('/[^A-Za-z]+/', ' ', $ascii);

        return trim($ascii);
    }

    /**
     * Strips accents without needing a transliteration table: decompose to
     * base letter + combining mark, then drop the marks. ext-intl carries
     * Normalizer but composer.json only requires ext-ctype/ext-iconv, so
     * fall back to iconv's transliteration when it isn't loaded (its
     * output is locale-dependent and can spell é as "'e", which is
     * harmless here -- normalizeForCode() drops the apostrophe either
     * way).
     */
    private function foldToAscii(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if ($decomposed !== false) {
                return (string) preg_replace('/\p{Mn}/u', '', $decomposed);
            }
        }

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $transliterated === false ? $value : $transliterated;
    }

    /**
     * The single guard every candidate passes through: uppercase ASCII
     * letters only, 1-3 of them, or null so the caller moves on. Codes
     * were previously returned unchecked and only rejected later by the
     * edit form's validator -- which is how codes with a digit, a hyphen
     * or a trailing space reached the database.
     */
    private function sanitizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $code));

        return $clean === '' ? null : substr($clean, 0, 3);
    }

    private function codeByWordSpace(string $name): ?string
    {
        $words = array_values(array_filter(explode(' ', $name)));
        if (count($words) < 3) {
            return null;
        }

        return strtoupper($words[0][0].$words[1][0].$words[2][0]);
    }

    private function codeByCapitalLetter(string $name): ?string
    {
        preg_match_all('/[A-Z]/', $name, $matches);
        $letters = $matches[0];

        if (count($letters) >= 3) {
            return strtoupper(implode('', array_slice($letters, 0, 3)));
        }

        if (count($letters) === 2) {
            return strtoupper($letters[0].$letters[1].$this->lastLetter($name));
        }

        return null;
    }

    private function codeByRuleOne(string $name): ?string
    {
        $letters = $this->lettersOf($name);
        if ($letters === []) {
            return null;
        }

        $vowels = ['a', 'e', 'i', 'o', 'u'];

        $first = strtoupper($letters[0]);
        $middle = '';
        $last = strtoupper($this->lastLetter($name));

        foreach ($letters as $index => $char) {
            if ($index === 0) {
                continue;
            }
            if (!in_array($char, $vowels, true)) {
                $middle = strtoupper($char);
                break;
            }
        }

        return $middle === '' ? null : $first.$middle.$last;
    }

    private function codeByRuleTwo(string $name): ?string
    {
        $vowels = ['a', 'e', 'i', 'o', 'u'];
        $code = '';

        // Walks letters only. The original walked the raw string and had
        // no space in its vowel list, so a space counted as a consonant --
        // "acme trading" yielded the code "CM ", which is the exact shape
        // of the trailing-space codes already sitting in the client table.
        foreach ($this->lettersOf($name) as $char) {
            if (!in_array($char, $vowels, true)) {
                $code .= strtoupper($char);
            }
            if (strlen($code) === 3) {
                break;
            }
        }

        return strlen($code) === 3 ? $code : null;
    }

    /**
     * The normalized name's letters, lower-cased, with the word-boundary
     * spaces removed -- what the two character-walking strategies operate
     * on.
     *
     * @return list<string>
     */
    private function lettersOf(string $name): array
    {
        $letters = str_replace(' ', '', strtolower($name));

        return $letters === '' ? [] : str_split($letters);
    }

    /**
     * The last *letter*, never whatever character the name happens to end
     * with -- `substr($name, -1)` on a raw name is how a code ended up
     * holding a digit or a hyphen.
     */
    private function lastLetter(string $name): string
    {
        $letters = $this->lettersOf($name);

        return $letters === [] ? '' : (string) end($letters);
    }

    /**
     * Direct port of Client::fallbackCode() -- rotate the third letter
     * A-Z against the name's own first two letters -- plus a fix for a
     * latent bug shared with the Yii2 original: if all 26 of those ran out
     * (every one already taken), it returned the plain, unchecked $base
     * straight away, risking a silent duplicate. Widens the search to the
     * last two letters, then the full 3-letter space, before giving up --
     * still entirely alphabetic (no digits), since Client Code must never
     * contain one.
     *
     * $name arrives already normalized (see normalizeForCode()), so a name
     * with no usable letters at all -- "Компания", "北京贸易公司", an
     * emoji -- lands here with an empty base and gets a rotated code
     * instead of the malformed-UTF-8 500 the raw byte slicing produced.
     */
    private function fallbackCode(string $name): string
    {
        $base = strtoupper(substr(str_replace(' ', '', $name), 0, 3));
        if ($base === '') {
            $base = 'AAA';
        }
        $base = str_pad($base, 3, 'A');
        $letters = range('A', 'Z');

        foreach ($letters as $third) {
            $code = substr($base, 0, 2).$third;
            if (!$this->clientRepository->clientCodeExists($code)) {
                return $code;
            }
        }

        foreach ($letters as $second) {
            foreach ($letters as $third) {
                $code = substr($base, 0, 1).$second.$third;
                if (!$this->clientRepository->clientCodeExists($code)) {
                    return $code;
                }
            }
        }

        foreach ($letters as $first) {
            foreach ($letters as $second) {
                foreach ($letters as $third) {
                    $code = $first.$second.$third;
                    if (!$this->clientRepository->clientCodeExists($code)) {
                        return $code;
                    }
                }
            }
        }

        throw new \RuntimeException('Unable to generate a unique client code -- the entire 3-letter code space is exhausted.');
    }
}
