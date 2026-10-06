<?php

declare(strict_types=1);

namespace Uccello\LinearConnector\Tests;

use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Uccello\Core\CoreServiceProvider;
use Uccello\Crud\CrudServiceProvider;
use Uccello\Hub\HubServiceProvider;
use Uccello\LinearConnector\LinearConnectorServiceProvider;
use Uccello\Testing\Models\Team;
use Uccello\Testing\Models\User;
use Uccello\Testing\TestbenchServiceProvider;
use Uccello\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            AiServiceProvider::class,
            CoreServiceProvider::class,
            CrudServiceProvider::class,
            HubServiceProvider::class,
            LinearConnectorServiceProvider::class,
            TestbenchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('hub.team_model', Team::class);
        $app['config']->set('hub.user_model', User::class);
        $app['config']->set('ai.text.provider', 'openai');
        $app['config']->set('ai.text.model', 'gpt-4o-mini');
    }
}
