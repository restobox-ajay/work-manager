<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Text;

use App\Service\Text\RichText;
use PHPUnit\Framework\TestCase;

/** ADR-118: task descriptions come from a rich-text editor; only formatting survives, never script. */
final class RichTextTest extends TestCase
{
    public function testFormattingIsKept(): void
    {
        $html = '<p><strong>Bold</strong> and <em>italic</em></p><ul><li>one</li><li>two</li></ul><h3>Head</h3>';

        self::assertSame($html, RichText::clean($html));
    }

    public function testScriptsStylesAndEventAttributesAreRemoved(): void
    {
        $clean = (string) RichText::clean('<p onclick="x()" style="color:red">Hi<script>alert(1)</script></p><style>p{}</style><iframe src="https://e.x"></iframe><img src=x onerror=alert(1)>');

        self::assertSame('<p>Hi</p>', $clean);
    }

    public function testUnknownTagsAreUnwrappedNotLost(): void
    {
        self::assertSame('<p>keep <b>me</b></p>', RichText::clean('<p><span class="x">keep <b>me</b></span></p>'));
    }

    public function testOnlySafeLinkTargetsSurvive(): void
    {
        self::assertSame('<a>bad</a>', RichText::clean('<a href="javascript:alert(1)">bad</a>'));
        self::assertSame('<a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow">ok</a>',
            RichText::clean('<a href="https://example.com" onmouseover="x()">ok</a>'));
    }

    public function testEmptyEditorIsStoredAsNull(): void
    {
        self::assertNull(RichText::clean('<p><br></p>'));
        self::assertNull(RichText::clean('   '));
        self::assertNull(RichText::clean(null));
    }

    public function testEmptyBlocksAreDropped(): void
    {
        self::assertSame('<p>a</p><ul><li>b</li></ul>', RichText::clean('<p>a</p><p></p><ul><li>b</li><li> </li></ul><h3></h3>'));
    }

    public function testPlainTextIsEscapedWithLineBreaks(): void
    {
        self::assertSame('a &lt; b<br>' . "\n" . 'c', RichText::render("a < b\nc"));
        self::assertSame('Tom &amp; Jerry', RichText::render('Tom & Jerry'));
    }

    public function testRenderCleansHtmlAlreadyStored(): void
    {
        self::assertSame('<p>x</p>', RichText::render('<p>x<script>bad()</script></p>'));
        self::assertSame('', RichText::render(null));
    }

    public function testNonAsciiTextSurvives(): void
    {
        self::assertSame('<p>Café – ✓ नमस्ते</p>', RichText::clean('<p>Café – ✓ नमस्ते</p>'));
    }
}
