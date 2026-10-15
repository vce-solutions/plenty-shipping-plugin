<?php

namespace VCEShipping\Api\Resources;

use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;

class ShippingLabelResource extends Controller
{
    private const DOCUMENT_TYPE = 'shipping_label';
    private const MAX_LABEL_BYTES = 10 * 1024 * 1024;
    private const SHIPPING_SERVICE_PROVIDER = 'VCEShipping';
    private const STORAGE_PLUGIN = 'VCEShipping';

    public function __construct(
        private Request $request,
        private Response $response,
        private ConfigRepository $config,
        private OrderShippingPackageRepositoryContract $packages,
        private DocumentRepositoryContract $documents,
        private ShippingInformationRepositoryContract $shippingInformation,
        private StorageRepositoryContract $storage
    ) {
    }

    public function store(int $orderId)
    {
        $expectedSecret = trim((string) $this->config->get('VCEShipping.security.sharedSecret', ''));
        $providedSecret = trim((string) $this->request->header('X-VCE-Shared-Secret', ''));
        if ($expectedSecret === '' || $providedSecret === ''
            || hash('sha256', $expectedSecret) !== hash('sha256', $providedSecret)) {
            return $this->response->json(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        $payload = $this->request->all();
        $error = $this->validatePayload($payload);
        if ($error !== null) {
            return $this->response->json(['message' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $currentPackages = $this->packages->listOrderShippingPackages($orderId);
        try {
            $matches = ShippingPackageResolver::resolve($currentPackages, $payload['packages']);
            // Check every existing label before updates, including later parcels in a batch.
            foreach ($matches as $index => $package) {
                if ($package !== null) {
                    $this->checkExistingLabel((int) $package->id, $payload['packages'][$index]['labelBase64']);
                }
            }
        } catch (\DomainException $e) {
            return $this->response->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        $results = [];
        foreach ($payload['packages'] as $index => $packageData) {
            $trackingNumber = trim((string) $packageData['trackingNumber']);
            $package = $matches[$index];
            $values = $this->packageValues($packageData, $package);
            if ($package === null) {
                $package = $this->packages->createOrderShippingPackage($orderId, $values);
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
            $storageKey = $packageId . '.pdf';
            $label = base64_decode((string) $packageData['labelBase64'], true);
            if ($this->storage->doesObjectExist(self::STORAGE_PLUGIN, $storageKey)) {
                $storageObject = $this->storage->getObject(self::STORAGE_PLUGIN, $storageKey);
                if (hash('sha256', (string) $storageObject->body) !== hash('sha256', $label)) {
                    return $this->response->json(
                        ['message' => 'A different shipping label already exists for package ' . $packageId],
                        Response::HTTP_CONFLICT
                    );
                }
            } else {
                $storageObject = $this->storage->uploadObject(self::STORAGE_PLUGIN, $storageKey, $label);
            }
            $this->packages->updateOrderShippingPackage($packageId, ['label' => (string) $storageObject->key]);
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
            'shippingServiceProvider' => self::SHIPPING_SERVICE_PROVIDER,
            'transactionId' => trim((string) $payload['transactionId']),
            'shippingStatus' => 'registered',
            'shippingCosts' => isset($payload['shippingCosts']) ? (float) $payload['shippingCosts'] : 0.0,
            'additionalData' => ['packages' => $results],
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
        if (($payload['shippingServiceProvider'] ?? null) !== self::SHIPPING_SERVICE_PROVIDER) {
            return 'shippingServiceProvider must be ' . self::SHIPPING_SERVICE_PROVIDER;
        }
        if (empty($payload['transactionId'])) {
            return 'transactionId is required';
        }
        if (empty($payload['packages']) || !is_array($payload['packages'])) {
            return 'At least one package is required';
        }
        $trackingNumbers = [];
        foreach ($payload['packages'] as $index => $package) {
            if (!is_array($package) || trim((string) ($package['trackingNumber'] ?? '')) === '' || empty($package['labelBase64'])) {
                return 'Package ' . ($index + 1) . ' requires trackingNumber and labelBase64';
            }
            foreach (['weight', 'packageType', 'volume'] as $field) {
                if (!array_key_exists($field, $package) || !is_numeric($package[$field])) {
                    return 'Package ' . ($index + 1) . ' requires numeric ' . $field;
                }
            }
            foreach (['orderShippingPackageId', 'packageId'] as $field) {
                if (array_key_exists($field, $package)
                    && (filter_var($package[$field], FILTER_VALIDATE_INT) === false || (int) $package[$field] <= 0)) {
                    return 'Package ' . ($index + 1) . ' requires a positive integer ' . $field;
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

    private function packageValues(array $package, $existing): array
    {
        $values = [
            'weight' => (int) $package['weight'],
            'packageNumber' => trim((string) $package['trackingNumber']),
            'packageType' => (int) ($existing->packageType ?? $package['packageType']),
            'volume' => (float) ($existing->volume ?? $package['volume'])
        ];
        // packageId describes the packing definition, not a sequence or the row identity.
        // Preserve it for existing parcels; let Plenty supply defaults for new parcels.
        if ($existing === null && isset($package['packageId'])) {
            $values['packageId'] = (int) $package['packageId'];
        }
        if (!empty($package['returnPackageNumber'])) {
            $values['returnPackageNumber'] = trim((string) $package['returnPackageNumber']);
        }
        return $values;
    }

    private function checkExistingLabel(int $packageId, string $base64): void
    {
        $label = base64_decode($base64, true);
        foreach ($this->activeDocuments($this->documents->findOrderShippingPackageDocuments($packageId, self::DOCUMENT_TYPE)) as $document) {
            if (!empty($document->content)
                && hash('sha256', base64_decode((string) $document->content, true)) !== hash('sha256', $label)) {
                throw new \DomainException('A different shipping label already exists for package ' . $packageId);
            }
            if ($document->status !== 'done') {
                throw new \RuntimeException('The shipping label is still pending for package ' . $packageId);
            }
        }
        $storageKey = $packageId . '.pdf';
        if ($this->storage->doesObjectExist(self::STORAGE_PLUGIN, $storageKey)
            && hash('sha256', (string) $this->storage->getObject(self::STORAGE_PLUGIN, $storageKey)->body) !== hash('sha256', $label)) {
            throw new \DomainException('A different shipping label already exists for package ' . $packageId);
        }
    }

    private function activeDocuments($documents): array
    {
        if (!is_array($documents)) {
            $documents = [$documents];
        }
        return array_values(array_filter($documents, function ($document): bool {
            return $document->type === self::DOCUMENT_TYPE
                && in_array($document->status, ['pending', 'done'], true);
        }));
    }
}
