<?php

namespace Tests\Unit;

use App\Services\MikroTik\Concerns\NormalizesRouterComment;
use PHPUnit\Framework\TestCase;

class NormalizesRouterCommentTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // Anonymous host that exposes the trait's protected helpers.
        $this->subject = new class {
            use NormalizesRouterComment;

            public function comment(?string $value, string $default = 'ISPWatch Auto'): string
            {
                return $this->normalizeRouterComment($value, $default);
            }

            public function ascii(string $value): string
            {
                return $this->asciiRouterComment($value);
            }
        };
    }

    public function test_it_replaces_enye_with_n(): void
    {
        $this->assertSame('Munoz Pena', $this->subject->comment('Muñoz Peña'));
        $this->assertSame('NINO', $this->subject->ascii('NIÑO'));
    }

    public function test_it_strips_accents_to_base_vowels(): void
    {
        $this->assertSame('Jose Ramirez', $this->subject->comment('José Ramírez'));
        $this->assertSame('Ines Guell', $this->subject->comment('Inés Güell'));
        $this->assertSame('AEIOU', $this->subject->ascii('ÁÉÍÓÚ'));
    }

    public function test_empty_comment_falls_back_to_default_and_is_also_normalized(): void
    {
        $this->assertSame('ISPWatch Auto', $this->subject->comment(null));
        $this->assertSame('ISPWatch Auto', $this->subject->comment('   '));
        // The fallback (e.g. the customer full name used by the queue) is
        // transliterated too.
        $this->assertSame('Begona Pena', $this->subject->comment(null, 'Begoña Peña'));
    }

    public function test_plain_ascii_names_are_unchanged(): void
    {
        $this->assertSame('John Smith', $this->subject->comment('John Smith'));
    }
}
