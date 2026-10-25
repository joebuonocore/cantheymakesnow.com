<?php

use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\IndexNow;

require_once __DIR__ . '/../../classes/IndexNow.php';

class IndexNowTest extends TestCase
{
    public function testPublicHostsCanBeSubmitted()
    {
        $this->assertTrue(IndexNow::isPublicHost('cantheymakesnow.com'));
        $this->assertTrue(IndexNow::isPublicHost('www.cantheymakesnow.com'));
    }

    public function testLocalHostsAreSkipped()
    {
        foreach (['localhost', '127.0.0.1', '[::1]', 'cantheymakesnow.test', 'site.local', 'app.localhost', '', null] as $host) {
            $this->assertFalse(IndexNow::isPublicHost($host), var_export($host, true));
        }
    }

    public function testKeyIsValid()
    {
        // IndexNow keys: 8 to 128 characters of a-z, A-Z, 0-9 and dashes.
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9-]{8,128}$/', IndexNow::KEY);
    }
}
