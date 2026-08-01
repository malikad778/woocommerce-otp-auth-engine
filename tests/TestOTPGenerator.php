<?php

use PHPUnit\Framework\TestCase;

class TestOTPGenerator extends TestCase
{
    public function test_generate_otp()
    {
        $otp = WCA_OTP_Generator::generate(6);
        $this->assertIsArray($otp);
        $this->assertArrayHasKey('code', $otp);
        $this->assertArrayHasKey('hash', $otp);
        $this->assertArrayHasKey('expiry', $otp);

        $this->assertEquals(6, strlen($otp['code']));
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $otp['code']);
        $this->assertTrue($otp['expiry'] > time());
    }

    public function test_hash_uniqueness()
    {
        $otp1 = WCA_OTP_Generator::generate(6);
        $otp2 = WCA_OTP_Generator::generate(6);

        if ($otp1['code'] !== $otp2['code']) {
            $this->assertNotEquals($otp1['hash'], $otp2['hash']);
        }
    }
}
