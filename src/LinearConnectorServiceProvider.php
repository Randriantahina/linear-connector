<?php

declare(strict_types=1);

namespace Uccello\LinearConnector;

use Illuminate\Support\ServiceProvider;

class LinearConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register the Linear driver into the shared connector registry.
        $this->mergeConfigFrom(__DIR__.'/../config/connector.php', 'connectors.drivers');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'linear');
    }
}
