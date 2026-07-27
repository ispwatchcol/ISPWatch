<?php

namespace Tests\Unit\Services;

use App\Services\Templates\TemplateSanitizer;
use Tests\TestCase;

class TemplateSanitizerTest extends TestCase
{
    private TemplateSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new TemplateSanitizer();
    }

    public function test_strips_script_tags_and_their_content(): void
    {
        $result = $this->sanitizer->sanitize('<p>Hola</p><script>alert("xss")</script>');

        $this->assertSame('<p>Hola</p>', $result);
    }

    public function test_strips_inline_event_handlers(): void
    {
        $result = $this->sanitizer->sanitize('<p onclick="alert(1)">Hola</p>');

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringContainsString('Hola', $result);
    }

    public function test_strips_img_tags_to_avoid_remote_fetch_in_dompdf(): void
    {
        $result = $this->sanitizer->sanitize('<p>Antes</p><img src="https://evil.test/track.png"><p>Después</p>');

        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringNotContainsString('evil.test', $result);
    }

    public function test_strips_iframe_and_object_tags(): void
    {
        $result = $this->sanitizer->sanitize('<iframe src="https://evil.test"></iframe><object data="x"></object><p>Ok</p>');

        $this->assertStringNotContainsString('iframe', $result);
        $this->assertStringNotContainsString('object', $result);
        $this->assertStringContainsString('Ok', $result);
    }

    public function test_strips_non_http_uri_schemes_from_links(): void
    {
        $result = $this->sanitizer->sanitize('<a href="javascript:alert(1)">Click</a>');

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_keeps_allowed_formatting_tags_and_safe_links(): void
    {
        $html = '<p><strong>Importante</strong>: revise <a href="https://example.test">este enlace</a>.</p>'
            . '<ul><li>Uno</li><li>Dos</li></ul>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('<strong>Importante</strong>', $result);
        $this->assertStringContainsString('href="https://example.test"', $result);
        $this->assertStringContainsString('<li>Uno</li>', $result);
    }

    public function test_keeps_allowed_span_color_style_and_strips_disallowed_css(): void
    {
        $result = $this->sanitizer->sanitize(
            '<span style="color:#ff0000;position:absolute;">Rojo</span>'
        );

        $this->assertStringContainsString('color:#ff0000', $result);
        $this->assertStringNotContainsString('position', $result);
    }
}
