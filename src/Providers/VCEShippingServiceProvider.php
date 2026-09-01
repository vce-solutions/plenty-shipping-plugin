<?php

namespace VCEShipping\Providers;

use Plenty\Modules\Order\Shipping\ServiceProvider\Services\ShippingServiceProviderService;
use Plenty\Plugin\ServiceProvider;

class VCEShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(VCEShippingRouteServiceProvider::class);
    }

    public function boot(ShippingServiceProviderService $shippingServiceProviderService): void
    {
        $shippingServiceProviderService->registerShippingProvider(
            'VCEShipping',
            ['de' => 'VCE Shipping', 'en' => 'VCE Shipping'],
            ['VCEShipping\\Controllers\\ShippingController@getLabels']
        );
    }
}
