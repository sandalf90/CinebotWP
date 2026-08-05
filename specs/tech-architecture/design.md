# Architecture

The plugin uses a composition root to connect WordPress adapters, repositories, and domain services. Seven custom tables store the title/event/sector/price hierarchy, venues, event types, and sync logs. API-owned hierarchy rows carry reconciliation metadata; public reads use a named projection model and show only active state-3 events.
