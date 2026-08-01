<?php

use PHPUnit\Framework\TestCase;

class TestIdentifierResolver extends TestCase
{
    public function test_classify_email()
    {
        $type = WCA_Identifier_Resolver::classify('user@example.com');
        $this->assertEquals('email', $type);
    }

    public function test_classify_phone()
    {
        $type = WCA_Identifier_Resolver::classify('+15550192834');
        $this->assertEquals('phone', $type);
    }

    public function test_classify_username()
    {
        $type = WCA_Identifier_Resolver::classify('john_doe_99');
        $this->assertEquals('username', $type);
    }
}
