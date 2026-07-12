<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CI stays network-free (CLAUDE.md): any unfaked HTTP call is a bug.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }
}
