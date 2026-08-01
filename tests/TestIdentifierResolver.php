<?php

use PHPUnit\Framework\TestCase;

class TestIdentifierResolver extends TestCase
{
    public function test_resolve_by_email()
    {
        $user = WCA_Identifier_Resolver::resolve('test@example.com');
        $this->assertInstanceOf('WP_User', $user);
        $this->assertEquals('test@example.com', $user->user_email);
    }

    public function test_resolve_by_username()
    {
        $user = WCA_Identifier_Resolver::resolve('testuser');
        $this->assertInstanceOf('WP_User', $user);
        $this->assertEquals('testuser', $user->user_login);
    }

    public function test_resolve_non_existent()
    {
        $user = WCA_Identifier_Resolver::resolve('non_existent_user_123');
        $this->assertFalse($user);
    }
}
