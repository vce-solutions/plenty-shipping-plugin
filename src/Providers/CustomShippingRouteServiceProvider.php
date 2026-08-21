<?php

namespace CustomShipping\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\ApiRouter;
use Plenty\Plugin\Routing\Router;

class CustomShippingRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router, ApiRouter $api): void
    {
        $api->version(
            ['v1'],
            [
                'namespace' => 'CustomShipping\\Api\\Resources',
                'middleware' => ['oauth']
            ],
            function (ApiRouter $api): void {
                $api->post(
                    'custom-shipping/orders/{orderId}/shipping-label',
                    'ShippingLabelResource@store'
                )->where('orderId', '[0-9]+');
            }
        );
    }
}
