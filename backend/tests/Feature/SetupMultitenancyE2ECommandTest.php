<?php

namespace Tests\Feature;

use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class SetupMultitenancyE2ECommandTest extends TestCase
{
    public function test_multitenancy_fixture_is_always_blocked_in_production(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        try {
            $this->artisan('test:setup-multitenancy-e2e', ['--env' => 'production'])
                ->expectsOutputToContain('Fixture bloqueado en producción')
                ->assertExitCode(Command::FAILURE);
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }
}
