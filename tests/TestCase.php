<?php
namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Boot the Rhapsody framework container or configuration here if needed
        // before each test runs.
    }
}
