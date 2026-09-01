<?php

namespace VCEShipping\Controllers;

use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;

class ShippingController extends Controller
{
    private const STORAGE_PLUGIN = 'VCEShipping';

    public function __construct(
        private OrderShippingPackageRepositoryContract $packages,
        private StorageRepositoryContract $storage
    ) {
    }

    public function getLabels(Request $request, $orderIds): array
    {
        $labels = [];
        foreach ($this->orderIds($request, $orderIds) as $orderId) {
            foreach ($this->packages->listOrderShippingPackages((int) $orderId) as $package) {
                $storageKey = (int) $package->id . '.pdf';
                if ($this->storage->doesObjectExist(self::STORAGE_PLUGIN, $storageKey)) {
                    $labels[] = $this->storage->getObject(self::STORAGE_PLUGIN, $storageKey)->body;
                }
            }
        }
        return $labels;
    }

    public function registerShipments(Request $request, array $orderIds): array
    {
        return [];
    }

    public function deleteShipments(Request $request, array $orderIds): array
    {
        return [];
    }

    private function orderIds(Request $request, $orderIds): array
    {
        if (is_numeric($orderIds)) {
            return [$orderIds];
        }
        if (is_array($orderIds) && count($orderIds) > 0) {
            return $orderIds;
        }
        $requestOrderIds = $request->get('orderIds', []);
        return is_numeric($requestOrderIds) ? [$requestOrderIds] : (array) $requestOrderIds;
    }
}
