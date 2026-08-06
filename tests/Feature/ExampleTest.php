<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The application boots and serves a response.
     *
     * `/` is an authenticated surface in this app and answers 403 to an anonymous request,
     * so the stock "expect 200" assertion never held here. What is worth asserting is that
     * the framework boots and routing resolves at all.
     */
    public function test_the_application_boots_and_routes_resolve(): void
    {
        $this->get('/')->assertStatus(403);
    }
}
