<?php

use PHPUnit\Framework\TestCase;

class TestSanitizer extends TestCase
{
    public function test_phone_sanitization()
    {
        $raw = "+1 (555) 019-2834";
        $sanitized = WCA_Sanitizer::phone($raw);
        $this->assertEquals('+15550192834', $sanitized);
    }

    public function test_phone_formatting_spaces()
    {
        $raw = "  +92 300 1234567  ";
        $sanitized = WCA_Sanitizer::phone($raw);
        $this->assertEquals('+923001234567', $sanitized);
    }
}
