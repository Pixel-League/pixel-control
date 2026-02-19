# Pixel Control Server

This directory hosts the first-party backend API for Pixel Control.

Current state:

- Implementation scaffold is not started yet (Laravel app generation is pending).
- Contract-first documentation is now available for plugin event ingestion.

Contract docs:

- `docs/ingestion-contract.md`: at-least-once ingestion baseline (dedupe scope, idempotency semantics, acknowledgment behavior).
- `docs/schema/ingestion-request-2026-02-19.1.schema.json`: request envelope shape for `POST /plugin/events`.
- `docs/schema/ingestion-response-2026-02-19.1.schema.json`: acknowledgment/error response shapes expected by the plugin retry pipeline.

References:

- Plugin envelope + event naming baseline: `../pixel-control-plugin/docs/event-contract.md`
- Plugin envelope schema: `../pixel-control-plugin/docs/schema/envelope-2026-02-19.1.schema.json`
- Plugin delivery error schema: `../pixel-control-plugin/docs/schema/delivery-error-2026-02-19.1.schema.json`
