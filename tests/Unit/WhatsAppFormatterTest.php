<?php

namespace Tests\Unit;

use App\Support\WhatsAppFormatter;
use PHPUnit\Framework\TestCase;

class WhatsAppFormatterTest extends TestCase
{
    public function test_formatting_markers(): void
    {
        $this->assertSame('<strong>आजच्या चालू घडामोडी</strong>', WhatsAppFormatter::toHtml('*आजच्या चालू घडामोडी*'));
        $this->assertSame('<em>— Education Hub</em>', WhatsAppFormatter::toHtml('_— Education Hub_'));
        $this->assertSame('<del>रद्द</del>', WhatsAppFormatter::toHtml('~रद्द~'));
        $this->assertSame('<code class="wa-mono">Q1 (a)</code>', WhatsAppFormatter::toHtml('```Q1 (a)```'));
        $this->assertSame('Use <code class="wa-code">mpsc</code> tag', WhatsAppFormatter::toHtml('Use `mpsc` tag'));
        $this->assertSame('वेळ: <strong>7:00 वाजता</strong> हजर रहा', WhatsAppFormatter::toHtml('वेळ: *7:00 वाजता* हजर रहा'));
    }

    public function test_markers_inside_words_or_with_spaces_are_left_alone(): void
    {
        $this->assertSame('file_name_v2.pdf', WhatsAppFormatter::toHtml('file_name_v2.pdf'));
        $this->assertSame('5 * 3 * 2', WhatsAppFormatter::toHtml('5 * 3 * 2'));
        $this->assertSame('* not bold *', WhatsAppFormatter::toHtml('* not bold *'));
    }

    public function test_formatting_does_not_cross_lines(): void
    {
        $this->assertSame("*line one<br>\nline two*", WhatsAppFormatter::toHtml("*line one\nline two*"));
    }

    public function test_html_is_escaped(): void
    {
        $html = WhatsAppFormatter::toHtml('<script>alert(1)</script> *<b>x</b>*');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_line_breaks(): void
    {
        $this->assertSame("एक<br>\nदोन", WhatsAppFormatter::toHtml("एक\nदोन"));
    }

    public function test_plain_text_excerpt(): void
    {
        $this->assertSame('आजच्या चालू घडामोडी वाचा', WhatsAppFormatter::plain("*आजच्या चालू घडामोडी*\n\n_वाचा_"));
        $this->assertSame('abcde...', WhatsAppFormatter::plain('abcdefgh', 5));
    }

    public function test_length_counts_an_emoji_once(): void
    {
        $this->assertSame(3, WhatsAppFormatter::length('a🏆b'));
        $this->assertSame(0, WhatsAppFormatter::length(null));
    }
}
