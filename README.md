# VCE-Shipping for plentyONE

This private plentyONE plugin exposes:

```text
POST /rest/custom-shipping/orders/{orderId}/shipping-label
```

The endpoint recreates the native post-registration state for externally generated PDF labels:

- find or create each order shipping package by tracking number
- upload a `shipping_label` document linked to the package
- verify that the document has status `done`
- save shipping information with status `registered`

Requests require HMAC-SHA256 authentication with a different shared secret for each plentyONE installation. Configure the masked `security.sharedSecret` plugin setting and the matching ESB `plentyShippingLabelSecretRef`.

## Deployment

1. Add this Git repository under **Plugins > Git** in plentyONE.
2. Install and activate `VCEShipping` in the target plugin set. plentyONE technical names cannot contain hyphens; the plugin is presented as VCE-Shipping in its description.
3. Deploy the plugin set.
4. Grant the calling plentyONE API user access to orders, shipping packages, documents, and shipping information.
5. Send `Accept: application/x.plentymarkets.v1+json` when calling the endpoint.

The operation is retry-safe for the same tracking number. Existing `pending` or completed `shipping_label` documents are never uploaded again. A pending document causes a retryable failure until plentyONE marks it `done`. Labels must be raw PDF bytes encoded as Base64 without a data URI prefix and may not exceed 10 MiB.
