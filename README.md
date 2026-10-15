# VCE-Shipping for plentyONE

This private plentyONE plugin exposes:

```text
POST /rest/custom-shipping/orders/{orderId}/shipping-label
```

The endpoint recreates the native post-registration state for externally generated PDF labels:

- reuse the explicitly identified prepared order shipping package, or find it by tracking number on retries
- create an order shipping package only when there is no matching/prepared parcel
- upload a `shipping_label` document linked to the package
- mirror the PDF into plugin storage for Shipping Center downloads
- verify that the document has status `done`
- save shipping information with status `registered` using the `VCEShipping` provider

Requests require `X-VCE-Shared-Secret` with a different shared secret for each plentyONE installation. Configure the masked `security.sharedSecret` plugin setting and the matching ESB `plentyShippingLabelSecretRef` value.

`VCEShipping` registers itself as the shipping provider. Its Shipping Center integration returns the completed `shipping_label` documents already stored on order shipping packages.

## Deployment

1. Add this Git repository under **Plugins > Git** in plentyONE.
2. Install and activate `VCEShipping` in the target plugin set. plentyONE technical names cannot contain hyphens; the plugin is presented as VCE-Shipping in its description.
3. Deploy the plugin set.
4. Grant the calling plentyONE API user access to orders, shipping packages, documents, and shipping information.
5. Send `Accept: application/x.plentymarkets.v1+json` when calling the endpoint.

The operation is retry-safe for the same tracking number. Existing `pending` or completed `shipping_label` documents are never uploaded again. A pending document causes a retryable failure until plentyONE marks it `done`. Labels must be raw PDF bytes encoded as Base64 without a data URI prefix and may not exceed 10 MiB.

## Prepared package contract

Deploy the updated plugin set **before** deploying the ESB change. Older plugin versions match only by tracking number and can create a duplicate of a prepared package.

```json
{
  "shippingServiceProvider": "VCEShipping",
  "transactionId": "CARRIER-TRANSACTION",
  "packages": [
    {
      "orderShippingPackageId": 136323,
      "packageId": 3,
      "trackingNumber": "CARRIER-TRACKING",
      "weight": 16400,
      "packageType": 24,
      "volume": 128012.5,
      "labelBase64": "<raw PDF bytes encoded as Base64>"
    }
  ]
}
```

- `orderShippingPackageId` is optional, but required for unambiguous reuse of multiple prepared parcels. It is the **`shippingPackages[].id`** from the order, not `packageId` or `items[].id`. A missing/deleted row or a row belonging to another order produces HTTP 409; it never silently creates a replacement.
- `packageId` is a separate packing-definition field. It is preserved on existing parcels and optional on new ones; omitted values are left to Plenty. ESB does not allocate it using `max + 1`.
- Matching uses the explicit row ID first, then the tracking number. Legacy requests without an ID may reuse exactly one remaining untracked parcel for exactly one unmatched result. Ambiguous batches return HTTP 409 rather than guessing based on array order.
- If prepared parcels are added after dispatch and would remain unmatched, writeback fails for explicit reconciliation instead of marking the entire order shipped.
- Closed parcels, conflicting tracking numbers, and different existing PDFs return a conflict before package mutations. Reusing a parcel preserves its item assignments, packing definition, package type and volume. The reported shipped weight and tracking number are updated.
- ESB maps existing prepared parcels to one Carma package / Shipping v3 loading unit each. `externalID: "plenty-package:<row-id>"` preserves the identity in saved requests, including delayed dispatch. Echoed response identities are used when results are reordered; responses without identities retain the carrier's request-order correspondence.
- Prepared package weights are converted from grams to kilograms for shipping. Missing weight/type uses configured defaults; length/width/height still come from the preset mapping because volume alone does not determine dimensions. Without prepared parcels, the existing item/fallback mapping is used.
- ESB completes label writeback before updating order status. A label conflict or pending document does not advance the order status.

Concurrent writers are not serialized by this endpoint. ESB's normal per-order processing should remain the single writer; sequential partial-success retries are covered, but concurrent native Shipping Center registration needs separate coordination.

## Local regression checks

```sh
php tests/ShippingLabelResourceTest.php
php -l src/Api/Resources/ShippingLabelResource.php
php -l src/Api/Resources/ShippingPackageResolver.php
```

The PHP regression flow runs the real resource with in-memory substitutes for Plenty SDK repositories. It checks package/content preservation, creation, multi-package identity, conflicts and retries through document storage and shipping registration. It does not replace verification against a deployed Plenty plugin set.
