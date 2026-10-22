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
| above | `count_review` | Counter is not sure why; saved as counted, staff decide later |
| above, no choice given | `count_review` | API clients that do not send `positive_reason` |
| (older rows) | `other` | Legacy: positive counts from before reasons were asked |

A count above the system count must say which of the two it is, otherwise
unlogged restocks cancel out real shrinkage. API clients read the choices from
`positive_reasons` on `GET /api/v1/inventory/item/{nid}` and send the chosen
value back as `positive_reason`.

The person counting often cannot tell a missed delivery from a miscount, so
"Not sure, staff will check" lets them save and move on. The count is applied
straight away; only the reason is open. Staff with "edit any
material_inventory entities" sort these at `/admin/material/count-review`
(two buttons per row set the reason; stock does not move), and the count form
shows staff when an item has open ones. The count form links to the restock entry form
(the `inventory_adjustment` add form prefilled with `reason=restock`) so
deliveries get logged before counting.

The restock form shows the material's backstock location
(`field_material_backstock`). People who can edit that field can change it there;
others see it read-only, as on the count form.

## Store inventory (start, complete, recheck, finalize)

`/admin/material/inventory` runs a monthly inventory:

1. Staff ("edit any material_inventory entities") click **Start inventory**.
2. People count as usual, on the website or in the app. Counts change stock
   straight away; the count form shows "Inventory in progress".
3. Anyone with "update material inventory counts" clicks **Inventory complete**.
   Each item's latest count since the start is checked by
   `InventoryFlagRules`: went the other way from the last count, went up with
   no delivery logged, more than 25% and more than 3 off, or a loss of $50+
   (any loss on an item with backstock). Flagged items are listed by value.
4. Anyone rechecks each flag: **recount** (corrects the original count in
   place, so stock equals what is there now), **confirm** (a high count needs
   its reason), or **Not sure** (reason `count_review`, goes to the count
   review page; low counts can be "Not sure" too).
5. Staff click **Finalize** once no flag is open. The summary is saved and
   emailed to `staff@makehaven.org` (override with
   `$settings['material_inventory_summary_email']`).

Counts outside an inventory work as before. Sessions and flags live in the
`material_inventory_session` and `material_inventory_session_flag` tables
(`material_inventory_totals_update_9002`).

## App endpoints

All under OAuth2, permission `update material inventory counts`.

| Endpoint | Does |
|---|---|
| `GET /api/v1/inventory/item/{nid}` | Item details; `features` lists the endpoints below so the app can hide what an older site lacks |
| `POST /api/v1/inventory/count` | `{nid, total_count, memo?, positive_reason?}`: a physical count |
| `POST /api/v1/inventory/restock` | `{nid, quantity, memo?}`: a delivery received; adds `quantity` as a Restock and leaves the last-counted date alone |
| `GET /api/v1/inventory/search?q=` | Materials whose title contains every word typed (2+ letters), published first, 20 max |

Backstock is part of a count, so moving backstock to the shelf is not a
restock. Log a restock when a delivery arrives, wherever it is put.
