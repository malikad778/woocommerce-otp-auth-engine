<?php

use PHPUnit\Framework\TestCase;

class TestConstants extends TestCase
{
    public function test_constants_defined()
    {
        $this->assertEquals('custom-auth/v1', WCA_Constants::namespace());
        $this->assertEquals(600, WCA_Constants::otp_ttl());
        $this->assertEquals(900, WCA_Constants::registration_ttl());
    }

    public function test_transient_key_format()
    {
        $session = 'abc123sessiontoken';
        $key = WCA_Constants::transient_registration($session);
        $this->assertEquals('wca_reg_abc123sessiontoken', $key);
    }

    public function test_country_dial_codes()
    {
        $codes = WCA_Constants::get_country_dial_codes();
        $this->assertIsArray($codes);
        $this->assertArrayHasKey('US', $codes);
        $this->assertEquals('+1', $codes['US']);
    }
}
