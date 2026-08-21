<?php

namespace VCEShipping\Api\Resources;

use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;

class ShippingLabelResource extends Controller
{
    private const DOCUMENT_TYPE = 'shipping_label';
    private const MAX_LABEL_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private Request $request,
        private Response $response,
        private OrderShippingPackageRepositoryContract $packages,
        private DocumentRepositoryContract $documents,
        private ShippingInformationRepositoryContract $shippingInformation
    ) {
    }

    public function store(int $orderId)
    {
        $payload = $this->request->all();
        $error = $this->validatePayload($payload);
        if ($error !== null) {
            return $this->response->json(['message' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $currentPackages = $this->packages->listOrderShippingPackages($orderId);
        $byTrackingNumber = [];
        foreach ($currentPackages as $package) {
            if (!empty($package->packageNumber)) {
                $byTrackingNumber[(string) $package->packageNumber] = $package;
            }
        }

        $results = [];
        foreach ($payload['packages'] as $packageData) {
            $trackingNumber = trim((string) $packageData['trackingNumber']);
            $package = $byTrackingNumber[$trackingNumber] ?? null;
            $values = $this->packageValues($packageData);
            if ($package === null) {
                $package = $this->packages->createOrderShippingPackage($orderId, $values);
                $byTrackingNumber[$trackingNumber] = $package;
            } else {
                $package = $this->packages->updateOrderShippingPackage((int) $package->id, $values);
            }

            $packageId = (int) $package->id;
            $existingDocuments = $this->activeDocuments(
                $this->documents->findOrderShippingPackageDocuments($packageId, self::DOCUMENT_TYPE)
            );
            $uploaded = false;
            if (count($existingDocuments) === 0) {
                $existingDocuments = $this->activeDocuments($this->documents->uploadOrderShippingPackageDocuments(
                    $packageId,
                    self::DOCUMENT_TYPE,
                    $packageData['labelBase64']
                ));
                $uploaded = true;
            }
            if (count($existingDocuments) === 0) {
                throw new \RuntimeException('The shipping label was not stored for package ' . $packageId);
            }
            $document = $existingDocuments[0];
            if ($document->status !== 'done') {
                throw new \RuntimeException('The shipping label is still pending for package ' . $packageId);
            }
            if (!empty($document->content)
                && hash('sha256', base64_decode((string) $document->content, true))
                    !== hash('sha256', base64_decode((string) $packageData['labelBase64'], true))) {
                return $this->response->json(
                    ['message' => 'A different shipping label already exists for package ' . $packageId],
                    Response::HTTP_CONFLICT
                );
            }

            $results[] = [
                'packageId' => $packageId,
                'trackingNumber' => $trackingNumber,
                'documentId' => (int) $document->id,
                'documentStatus' => (string) $document->status,
                'uploaded' => $uploaded
            ];
        }

        $now = date(\DateTimeInterface::W3C);
        $this->shippingInformation->saveShippingInformation([
            'orderId' => $orderId,
            'shippingServiceProvider' => trim((string) $payload['shippingServiceProvider']),
            'transactionId' => trim((string) $payload['transactionId']),
            'shippingStatus' => 'registered',
            'shippingCosts' => isset($payload['shippingCosts']) ? (float) $payload['shippingCosts'] : 0.0,
            'additionalData' => json_encode(['packages' => $results]),
            'registrationAt' => $now,
            'shipmentAt' => $now
        ]);

        return $this->response->json([
            'orderId' => $orderId,
            'shippingStatus' => 'registered',
            'packages' => $results
        ]);
    }

    private function validatePayload(array $payload): ?string
    {
        if (empty($payload['shippingServiceProvider']) || empty($payload['transactionId'])) {
            return 'shippingServiceProvider and transactionId are required';
        }
        if (empty($payload['packages']) || !is_array($payload['packages'])) {
            return 'At least one package is required';
        }
        $trackingNumbers = [];
        foreach ($payload['packages'] as $index => $package) {
            if (!is_array($package) || empty($package['trackingNumber']) || empty($package['labelBase64'])) {
                return 'Package ' . ($index + 1) . ' requires trackingNumber and labelBase64';
            }
            foreach (['packageId', 'weight', 'packageType', 'volume'] as $field) {
                if (!array_key_exists($field, $package) || !is_numeric($package[$field])) {
                    return 'Package ' . ($index + 1) . ' requires numeric ' . $field;
                }
            }
            $document = base64_decode((string) $package['labelBase64'], true);
            if ($document === false || substr($document, 0, 5) !== '%PDF-') {
                return 'Package ' . ($index + 1) . ' labelBase64 must contain a Base64-encoded PDF';
            }
            if (strlen($document) > self::MAX_LABEL_BYTES) {
                return 'Package ' . ($index + 1) . ' PDF exceeds 10 MiB';
            }
            $trackingNumber = trim((string) $package['trackingNumber']);
            if (isset($trackingNumbers[$trackingNumber])) {
                return 'Package tracking numbers must be unique';
            }
            $trackingNumbers[$trackingNumber] = true;
        }
        return null;
    }

    private function packageValues(array $package): array
    {
        $values = [
            'packageId' => (int) $package['packageId'],
            'weight' => (int) $package['weight'],
            'packageNumber' => trim((string) $package['trackingNumber']),
            'packageType' => (int) $package['packageType'],
            'volume' => (float) $package['volume']
        ];
        if (!empty($package['returnPackageNumber'])) {
            $values['returnPackageNumber'] = trim((string) $package['returnPackageNumber']);
        }
        return $values;
    }

    private function activeDocuments(array $documents): array
    {
        return array_values(array_filter($documents, function ($document): bool {
            return $document->type === self::DOCUMENT_TYPE
                && in_array($document->status, ['pending', 'done'], true);
        }));
    }
}
