<?php

use PHPUnit\Framework\TestCase;

class TestSanitizer extends TestCase
{
    public function test_phone_normalization()
    {
        $raw = "+1 (555) 019-2834";
        $sanitized = WCA_Sanitizer::normalize_phone($raw);
        $this->assertEquals('+15550192834', $sanitized);
    }

    public function test_phone_formatting_spaces()
    {
        $raw = " +447911123456 ";
        $sanitized = WCA_Sanitizer::normalize_phone($raw);
        $this->assertEquals('+447911123456', $sanitized);
    }
}
