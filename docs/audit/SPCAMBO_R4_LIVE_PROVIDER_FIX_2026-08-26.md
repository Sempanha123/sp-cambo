# SP Cambo R4 — live provider/selling fix

## Why validation passed but the provider was still blocked

Automated validation verifies code and tests. It does not rewrite existing MySQL catalog rows.
The existing OmniRoute provider in the local database was created before the first-probe auto-activation change, so it can remain `READY` with `active_connection_revision_id = null` until it is activated or published again.
The screenshots also showed two independent publication blockers stored in MySQL: the private model had not been marked as commercially resale-verified and the public alias was hidden.

## R4 behavior

### Publish for sale

A blocked public alias now exposes **Publish for sale**. The action:

1. requires an explicit admin confirmation that the upstream/provider agreement permits commercial resale;
2. activates the latest successfully probed `READY` connection if the provider is a legacy `READY`/not-active record;
3. enables the provider and private model;
4. records commercial resale verification for the private model;
5. enables and makes the public alias customer-visible; and
6. refreshes the provider, revisions, private models and aliases immediately.

The backend never silently asserts resale permission. The admin must explicitly confirm it.

### Provider deletion

The provider delete modal now has **Delete dependent provider configuration** enabled by default. Cascade deletion removes unused provider configuration, including public aliases/pricing, private models and connection revisions, while detaching aliases from package/API-key scope tables. Packages left with no models are disabled. Playground/redeem-code configuration is cleaned up.

Historical gateway reservations still block deletion. Those rows are billing/routing history and are intentionally preserved; disable the provider instead if history exists.

### Docker

Docker is optional for this local Windows workflow. `scripts/START_ALL.ps1` starts Nuxt, Laravel, the SP Cambo gateway, KHQR service and scheduler directly. A native MySQL server must still be running, and the configured upstream OmniRoute service (for example `127.0.0.1:20128`) must be reachable.

## Existing local provider after upgrading

Open **Admin → Providers → OmniRoute**.

- If `Active connection` is `No` and a revision is `READY / Probe SUCCESS`, use **Activate READY revision**, or simply choose **Publish for sale** on the alias.
- On **Publish for sale**, read and confirm the commercial-resale statement only if your upstream terms permit it.
- After success, the public alias should show `Enabled`, `Customer visible`, and `Route ready`.
- Model Pricing should then count the alias under **Sold to customers**, assuming the pricing record is present.

No migration is required for R4 because this patch changes behavior, not database schema.
