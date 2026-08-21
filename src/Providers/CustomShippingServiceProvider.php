<?php

namespace CustomShipping\Providers;

use Plenty\Plugin\ServiceProvider;

class CustomShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(CustomShippingRouteServiceProvider::class);
    }
}
