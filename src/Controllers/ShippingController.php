<?php

namespace VCEShipping\Controllers;

use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;

class ShippingController extends Controller
{
    private const DOCUMENT_TYPE = 'shipping_label';

    public function __construct(
        private DocumentRepositoryContract $documents,
        private OrderShippingPackageRepositoryContract $packages
    ) {
    }

    public function getLabels(Request $request, $orderIds): array
    {
        $labels = [];
        foreach ($this->orderIds($request, $orderIds) as $orderId) {
            foreach ($this->packages->listOrderShippingPackages((int) $orderId) as $package) {
                $documents = $this->documents->findOrderShippingPackageDocuments((int) $package->id, self::DOCUMENT_TYPE);
                if (!is_array($documents) && !($documents instanceof \Traversable)) {
                    $documents = [$documents];
                }
                foreach ($documents as $document) {
                    if (!empty($document->content)) {
                        $content = base64_decode((string) $document->content, true);
                        $labels[] = $content === false ? $document->content : $content;
                        continue;
                    }
                    $storageObject = $this->documents->getDocumentStorageObject($document->path);
                    if ($storageObject !== null && $storageObject->body !== null) {
                        $labels[] = $storageObject->body;
                    }
                }
            }
        }
        return $labels;
    }

    private function orderIds(Request $request, $orderIds): array
    {
        if (is_numeric($orderIds)) {
            return [$orderIds];
        }
        return is_array($orderIds) ? $orderIds : (array) $request->get('orderIds', []);
    }
}
