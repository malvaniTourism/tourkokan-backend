<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // The non-test-database guard lives in CreatesApplication::createApplication(), which
    // runs before Laravel boots the RefreshDatabase trait. See the note there.
    use CreatesApplication;
}
