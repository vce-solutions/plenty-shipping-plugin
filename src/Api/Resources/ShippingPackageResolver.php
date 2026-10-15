<?php

namespace VCEShipping\Api\Resources;

/** Resolve the complete batch before mutating anything; array order is never a package identity. */
class ShippingPackageResolver
{
    public static function resolve(array $currentPackages, array $requestedPackages): array
    {
        $byId = [];
        $byTracking = [];
        foreach ($currentPackages as $package) {
            $id = (int) $package->id;
            if ($id <= 0 || isset($byId[$id])) {
                throw new \DomainException('Missing or duplicate order shipping package ID');
            }
            $byId[$id] = $package;
            $tracking = trim((string) ($package->packageNumber ?? ''));
            if ($tracking !== '') {
                if (isset($byTracking[$tracking])) {
                    throw new \DomainException('Duplicate existing package tracking number');
                }
                $byTracking[$tracking] = $package;
            }
        }

        $matches = [];
        $usedIds = [];
        foreach ($requestedPackages as $data) {
            $tracking = trim((string) $data['trackingNumber']);
            $tracked = $byTracking[$tracking] ?? null;
            $package = $tracked;
            if (isset($data['orderShippingPackageId'])) {
                $package = $byId[(int) $data['orderShippingPackageId']] ?? null;
                if ($package === null) {
                    throw new \DomainException('Prepared package does not exist on this order');
                }
                if ($tracked !== null && (int) $tracked->id !== (int) $package->id) {
                    throw new \DomainException('Tracking number belongs to a different package');
                }
            }
            if ($package !== null) {
                if (isset($usedIds[(int) $package->id])) {
                    throw new \DomainException('Order shipping package selected more than once');
                }
                $usedIds[(int) $package->id] = true;
            }
            $matches[] = $package;
        }

        $untracked = array_values(array_filter($currentPackages, function ($package) use ($usedIds): bool {
            return trim((string) ($package->packageNumber ?? '')) === '' && !isset($usedIds[(int) $package->id]);
        }));
        $unmatched = array_keys($matches, null, true);
        if (count($unmatched) > 0 && count($untracked) > 0) {
            if (count($unmatched) !== 1 || count($untracked) !== 1) {
                throw new \DomainException('Ambiguous prepared packages; orderShippingPackageId is required');
            }
            $matches[$unmatched[0]] = $untracked[0];
        } elseif (count($untracked) > 0) {
            throw new \DomainException('Prepared package count changed; unmatched parcels remain');
        }

        foreach ($matches as $index => $package) {
            if ($package === null) {
                continue;
            }
            $tracking = trim((string) ($package->packageNumber ?? ''));
            if (!empty($package->isClosed)
                || ($tracking !== '' && $tracking !== trim((string) $requestedPackages[$index]['trackingNumber']))
                || ($tracking === '' && trim((string) ($package->labelPath ?? '')) !== '')) {
                throw new \DomainException('Package ' . (int) $package->id . ' is closed or has conflicting shipping data');
            }
        }
        return $matches;
    }
}
