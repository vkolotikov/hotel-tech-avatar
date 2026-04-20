# RevenueCat

**Status:** `planned`
**Last verified:** 2026-04-20
**Official docs:** https://www.revenuecat.com/docs

## What we use it for

Mobile in-app purchase + subscription lifecycle for the WellnessAI mobile app. We do **not** build StoreKit / Play Billing handling from scratch — RevenueCat normalises Apple & Google receipts, webhooks, and entitlements. Maps directly onto our vendor-neutral billing columns (`billing_provider='revenuecat'`, `billing_customer_id`, `billing_metadata`).

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated:

- **Mobile SDK**: `Purchases.configure()`, `Purchases.getCustomerInfo()`, `Purchases.purchase(product)` — client-side only
- **Server**: REST `GET /v1/subscribers/{app_user_id}` for reconciliation; webhook receiver for `INITIAL_PURCHASE`, `RENEWAL`, `CANCELLATION`, `EXPIRATION`, `BILLING_ISSUE`, `PRODUCT_CHANGE`
- Webhook endpoint will land at `POST /api/v1/webhooks/revenuecat` with HMAC signature verification

## Authentication

- **Mobile SDK**: public SDK key (safe to ship in the app bundle)
- **REST API**: secret API key in `REVENUECAT_SECRET_API_KEY` env var
- **Webhook verification**: `REVENUECAT_WEBHOOK_AUTH_HEADER` — shared Authorization header value RevenueCat sends, verified on every webhook request

## Error handling expectations

_To be filled in by the first implementer._ Critical: webhook idempotency (retries are expected), receipt-revocation (fraud / chargeback flow), out-of-order event delivery.

## Cost and quota notes

- Pricing: https://www.revenuecat.com/pricing — free tier covers up to $10k MTR (monthly tracked revenue), then 1% over.
- Costs are revenue-driven, not per-call — no quota enforcement needed.

## Project-specific notes

- **Never trust the mobile client** as the source of entitlement truth. Always reconcile via webhook or REST before granting premium access. The mobile SDK is a UX convenience, not the security boundary.
- Entitlement state lives in our `subscription_entitlements` table — RevenueCat is the upstream event source, we are the system of record.
- `subscription_plans.slug` ↔ RevenueCat `product_id` mapping is configuration, stored in `billing_metadata`.
- RevenueCat Experiments / A/B testing for paywalls is a future option; not in scope for v1.0.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
