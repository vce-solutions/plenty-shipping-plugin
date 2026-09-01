<?php

namespace VCEShipping\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\ApiRouter;
use Plenty\Plugin\Routing\Router;

class VCEShippingRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router, ApiRouter $api): void
    {
        $api->version(
            ['v1'],
            ['namespace' => 'VCEShipping\\Api\\Resources'],
            function (ApiRouter $api): void {
                $api->post(
                    'custom-shipping/orders/{orderId}/shipping-label',
                    'ShippingLabelResource@store'
                )->where('orderId', '[0-9]+');
            }
        );
    }
}
