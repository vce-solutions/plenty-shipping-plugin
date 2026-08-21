<?php

namespace VCEShipping\Providers;

use Plenty\Plugin\ServiceProvider;

class VCEShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(VCEShippingRouteServiceProvider::class);
    }
}
