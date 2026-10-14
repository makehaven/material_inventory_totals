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

## Count reasons

The count form (`/admin/material/{nid}/inventory-count`) and the inventory app
API (`POST /api/v1/inventory/count`) record the difference between the counted
total and the system count as an adjustment. The reason codes live in
`src/CountReasons.php`:

| Count vs system | Reason | Meaning |
|---|---|---|
| equal | `verification` | Count verified, no change |
| below | `lossage` | Loss / shrinkage |
| above | `restock_unlogged` | Stock arrived (delivery, backstock) and nobody logged a restock |
| above | `count_found` | Stock was already here: miscount, or items found |
| above, no choice given | `other` | Legacy: API clients that do not send `positive_reason` yet |

A count above the system count must say which of the two it is, otherwise
unlogged restocks cancel out real shrinkage. API clients read the choices from
`positive_reasons` on `GET /api/v1/inventory/item/{nid}` and send the chosen
value back as `positive_reason`. The count form links to the restock entry form
(the `inventory_adjustment` add form prefilled with `reason=restock`) so
deliveries get logged before counting.

The restock form shows the material's backstock location
(`field_material_backstock`). People who can edit that field can change it there;
others see it read-only, as on the count form.
