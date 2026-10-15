<?php

// Run with php tests/ShippingLabelResourceTest.php. SDK boundaries are in-memory;
// the real resource performs selection, updates, document upload and registration.
namespace Plenty\Modules\Document\Contracts { interface DocumentRepositoryContract {} }
namespace Plenty\Modules\Order\Shipping\Information\Contracts { interface ShippingInformationRepositoryContract {} }
namespace Plenty\Modules\Order\Shipping\Package\Contracts { interface OrderShippingPackageRepositoryContract {} }
namespace Plenty\Modules\Plugin\Storage\Contracts { interface StorageRepositoryContract {} }
namespace Plenty\Plugin {
    class Controller {}
    class ConfigRepository {
        public function get($key, $default = null) { return 'test-secret'; }
    }
}
namespace Plenty\Plugin\Http {
    class Request {
        public function __construct(public array $payload) {}
        public function header($key, $default = '') { return 'test-secret'; }
        public function all() { return $this->payload; }
    }
    class Response {
        public const HTTP_UNAUTHORIZED = 401;
        public const HTTP_UNPROCESSABLE_ENTITY = 422;
        public const HTTP_CONFLICT = 409;
        public function json($body, $status = 200) { return ['status' => $status, 'body' => $body]; }
    }
}
namespace VCEShipping\Tests {
    use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
    use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
    use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
    use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
    use Plenty\Plugin\ConfigRepository;
    use Plenty\Plugin\Http\Request;
    use Plenty\Plugin\Http\Response;
    use VCEShipping\Api\Resources\ShippingLabelResource;

    require __DIR__ . '/../src/Api/Resources/ShippingPackageResolver.php';
    require __DIR__ . '/../src/Api/Resources/ShippingLabelResource.php';

    class Packages implements OrderShippingPackageRepositoryContract {
        public array $rows = [];
        public int $created = 0;
        public int $updated = 0;
        public function __construct(array $rows) {
            foreach ($rows as $row) { $this->rows[$row->id] = clone $row; }
        }
        public function listOrderShippingPackages($orderId) {
            return array_values(array_filter($this->rows, fn($row) => $row->orderId === $orderId));
        }
        public function createOrderShippingPackage($orderId, $values) {
            $this->created++;
            $id = 200000 + $this->created;
            $this->rows[$id] = (object) array_merge(['id' => $id, 'orderId' => $orderId], $values);
            return $this->rows[$id];
        }
        public function updateOrderShippingPackage($id, $values) {
            $this->updated++;
            foreach ($values as $key => $value) { $this->rows[$id]->{$key} = $value; }
            return $this->rows[$id];
        }
    }
    class Documents implements DocumentRepositoryContract {
        public array $byPackage = [];
        public int $uploads = 0;
        public bool $pending = false;
        public function findOrderShippingPackageDocuments($id, $type) { return $this->byPackage[$id] ?? []; }
        public function uploadOrderShippingPackageDocuments($id, $type, $content) {
            $this->uploads++;
            return $this->byPackage[$id] = [(object) [
                'id' => $this->uploads, 'type' => $type,
                'status' => $this->pending ? 'pending' : 'done', 'content' => $content
            ]];
        }
    }
    class Information implements ShippingInformationRepositoryContract {
        public array $saved = [];
        public function saveShippingInformation($data) { $this->saved[] = $data; }
    }
    class Storage implements StorageRepositoryContract {
        public array $objects = [];
        public function doesObjectExist($plugin, $key) { return isset($this->objects[$key]); }
        public function getObject($plugin, $key) { return $this->objects[$key]; }
        public function uploadObject($plugin, $key, $body) {
            return $this->objects[$key] = (object) ['key' => $key, 'body' => $body];
        }
    }

    function check(bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    function row(int $id = 136323, array $overrides = []): object {
        return (object) array_merge([
            'id' => $id, 'orderId' => 30437, 'packageId' => 3, 'packageNumber' => '',
            'packageType' => 24, 'weight' => 16400, 'volume' => 128012.5, 'labelPath' => '',
            'isClosed' => null, 'items' => [(object) ['variationId' => 1098, 'itemQuantity' => 1]]
        ], $overrides);
    }
    function parcel(?int $id = 136323, string $tracking = 'T1'): array {
        $data = ['weight' => 16400, 'packageType' => 24, 'volume' => 128012.5,
            'trackingNumber' => $tracking, 'labelBase64' => base64_encode('%PDF-1.4 test')];
        if ($id !== null) { $data['orderShippingPackageId'] = $id; $data['packageId'] = 3; }
        return $data;
    }
    function resource(array $rows, array $parcels): array {
        $packages = new Packages($rows);
        $documents = new Documents();
        $information = new Information();
        $storage = new Storage();
        $request = new Request(['shippingServiceProvider' => 'VCEShipping', 'transactionId' => 'MASTER', 'packages' => $parcels]);
        $resource = new ShippingLabelResource($request, new Response(), new ConfigRepository(),
            $packages, $documents, $information, $storage);
        return [$resource, $packages, $documents, $information, $storage, $request];
    }

    $tests = [];
    $tests['reuse prepared package and retry without duplicates'] = function () {
        [$resource, $packages, $documents, $information, $storage] = resource([row()], [parcel()]);
        $response = $resource->store(30437);
        check($response['status'] === 200, 'first writeback failed');
        check($packages->created === 0 && count($packages->rows) === 1, 'created a duplicate package');
        check($packages->rows[136323]->packageNumber === 'T1', 'tracking not attached');
        check($packages->rows[136323]->packageId === 3, 'packing definition changed');
        check($packages->rows[136323]->items[0]->variationId === 1098, 'contents lost');
        check(isset($storage->objects['136323.pdf']), 'PDF not stored on existing package');
        check($information->saved[0]['shippingStatus'] === 'registered', 'shipping not registered');
        check($resource->store(30437)['status'] === 200, 'retry failed');
        check($documents->uploads === 1 && $packages->created === 0, 'retry duplicated package/document');
    };
    $tests['create when no package exists and retry by tracking'] = function () {
        [$resource, $packages, $documents] = resource([], [parcel(null)]);
        check($resource->store(30437)['status'] === 200, 'creation failed');
        check($resource->store(30437)['status'] === 200, 'creation retry failed');
        check($packages->created === 1 && $documents->uploads === 1, 'creation retry duplicated data');
    };
    $tests['legacy request reuses only unambiguous prepared parcel'] = function () {
        [$resource, $packages] = resource([row()], [parcel(null)]);
        check($resource->store(30437)['status'] === 200 && $packages->created === 0, 'legacy reuse failed');
    };
    $tests['explicit identities survive package order changes'] = function () {
        [$resource, $packages] = resource([row(136324), row()], [parcel(), parcel(136324, 'T2')]);
        check($resource->store(30437)['status'] === 200, 'multi-package writeback failed');
        check($packages->created === 0 && $packages->rows[136323]->packageNumber === 'T1'
            && $packages->rows[136324]->packageNumber === 'T2', 'packages incorrectly matched');
    };
    $tests['mixed retry and legacy prepared parcel are resolved once each'] = function () {
        [$resource, $packages] = resource([row(136323, ['packageNumber' => 'T1']), row(136324)],
            [parcel(null), parcel(null, 'T2')]);
        check($resource->store(30437)['status'] === 200 && $packages->created === 0, 'mixed retry failed');
        check($packages->rows[136324]->packageNumber === 'T2', 'prepared package not selected');
    };
    $tests['existing packing definition cannot be overwritten by request'] = function () {
        $data = parcel();
        $data['packageId'] = 99;
        $data['packageType'] = 26;
        $data['volume'] = 10;
        [$resource, $packages] = resource([row()], [$data]);
        check($resource->store(30437)['status'] === 200, 'writeback failed');
        check($packages->rows[136323]->packageId === 3 && $packages->rows[136323]->packageType === 24
            && $packages->rows[136323]->volume === 128012.5, 'packing data overwritten');
    };
    $tests['invalid identities and duplicate tracking are rejected without mutation'] = function () {
        $cases = [[parcel(), parcel(136324)], [array_merge(parcel(), ['orderShippingPackageId' => 0])],
            [array_merge(parcel(), ['orderShippingPackageId' => 1.5])],
            [array_merge(parcel(), ['trackingNumber' => ' '])]];
        foreach ($cases as $parcels) {
            [$resource, $packages, $documents] = resource([row()], $parcels);
            check($resource->store(30437)['status'] === 422, 'invalid request accepted');
            check($packages->updated === 0 && $documents->uploads === 0, 'invalid request mutated data');
        }
    };
    $tests['all conflicts are detected before any package mutation'] = function () {
        $cases = [
            [[], [parcel()]],
            [[row(136323, ['orderId' => 999])], [parcel()]],
            [[row(136323, ['isClosed' => true])], [parcel()]],
            [[row(136323, ['packageNumber' => 'OTHER'])], [parcel()]],
            [[row(136323, ['labelPath' => 'old.pdf'])], [parcel()]],
            [[row(), row(136324)], [parcel(null)]],
            [[row(), row(136324, ['packageNumber' => 'OTHER'])], [parcel(), parcel(136324, 'T2')]],
            [[row()], [parcel(), parcel(136323, 'T2')]],
            [[row(), row(136324, ['packageNumber' => 'T1'])], [parcel()]],
            [[row(), row(136324)], [parcel()]]
        ];
        foreach ($cases as [$rows, $parcels]) {
            [$resource, $packages, $documents, $information] = resource($rows, $parcels);
            check($resource->store(30437)['status'] === 409, 'conflict not rejected');
            check($packages->created === 0 && $packages->updated === 0
                && $documents->uploads === 0 && count($information->saved) === 0, 'conflict mutated data');
        }
    };
    $tests['different PDF is rejected before package update'] = function () {
        [$resource, $packages, $documents, $information, $storage, $request] = resource([row()], [parcel()]);
        check($resource->store(30437)['status'] === 200, 'setup failed');
        $updates = $packages->updated;
        $request->payload['packages'][0]['labelBase64'] = base64_encode('%PDF-1.4 different');
        check($resource->store(30437)['status'] === 409, 'different label accepted');
        check($packages->updated === $updates && $documents->uploads === 1, 'conflicting retry mutated package');
    };
    $tests['pending document retry finishes without creating or uploading again'] = function () {
        [$resource, $packages, $documents] = resource([row()], [parcel()]);
        $documents->pending = true;
        try { $resource->store(30437); throw new \LogicException('pending document was accepted'); }
        catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'pending'), 'unexpected pending error'); }
        $documents->byPackage[136323][0]->status = 'done';
        check($resource->store(30437)['status'] === 200, 'pending retry failed');
        check($packages->created === 0 && $documents->uploads === 1, 'pending retry duplicated data');
    };
    $failed = 0;
    foreach ($tests as $name => $test) {
        try { $test(); print "PASS $name\n"; }
        catch (\Throwable $e) { $failed++; print "FAIL $name: {$e->getMessage()}\n"; }
    }
    exit($failed === 0 ? 0 : 1);
}
