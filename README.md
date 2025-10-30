# Material Inventory Totals

This helper module keeps the `material` content type's cached inventory fields up to date. It listens for changes to `material_inventory` (ECK) adjustment entities and updates:

- `field_material_inventory_count`
- `field_material_inventory_value`

The totals are recalculated whenever adjustments are created, edited, or deleted so Views no longer need to aggregate the entire adjustment history on each request.

## Features

- Incremental updates driven by entity CRUD hooks (no Views aggregation required).
- Full rebuild via `drush material-inventory-totals:rebuild` (supports `--nid` and `--limit`).
- Rolling cron-based consistency checks to detect and correct drift.
- Lightweight locking to prevent race conditions from concurrent updates.
