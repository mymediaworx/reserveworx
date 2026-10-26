# Allocation design

This document is the design required by spec Section 4. The engine is `App\Services\Allocation\Allocator`. A foundation test locks one row with `SELECT … FOR UPDATE`. The Section 4.7 suite is `tests/Feature/Allocation/ConcurrencyTest.php` and runs on MySQL and MariaDB.

If a simpler mechanism is later shown to be equally correct, propose it in [`DECISIONS.md`](DECISIONS.md) before replacing this design.

## Resources

- **Asset:** one physical or human resource (a specific boat, kayak, bike, or guide). Locked by primary key.
- **Pool:** a quantity of interchangeable units (20 single kayaks). Capacity is `pools.quantity`, read under the lock. Do not copy capacity onto the ledger and then trust the copy.
- **Service requirements** name the assets and pool units a booking needs. Tours and rentals draw from the same rows.

Locations own the timezone. Tenants do not. Timestamps are stored in UTC.

## Intervals

Booking intervals are half-open: `[start, end)`.

The buffered interval that must be free is `[start - prep_buffer, end + return_buffer + turnaround)`. Prep and return buffers and the turnaround gap live on the asset or the pool. Turnaround is the gap after one rental before the next.

A unit is free only when it is eligible for the service, active, not blocked, not in maintenance, not retired, the location is open, the date is not a blackout, the customer interval is not inside a closure, and no allocation, active rental, or unexpired hold overlaps the buffered interval. A closure is checked with opening hours on `[start, end)`, not on the buffered interval. A location-wide closure closes the location. A category or single-resource closure closes only that resource. An active checkout (`returned_at` null) occupies `[checked_out_at, max(due_at, now()) + turnaround)`. That is what extends the block when a rental runs past its due time. Returning the rental sets each allocation end to `now() + turnaround` and the checkout stops counting. A pool checkout is not added again for a bucket the same booking's allocation already covers.

An unexpired hold is one with `expires_at > now()`. Expired holds do not count, even if the cleanup job has not run.

## Capacity ledger

Pools use a ledger keyed by `(tenant_id, pool_id, bucket_start_utc)`.

- Bucket length is per tenant: `tenant_settings.ledger_bucket_minutes`, one of 5, 10, 15, 30, or 60. Default 15.
- Bucket start is aligned to the Unix epoch: `floor(unix_ts / (bucket_minutes * 60)) * bucket_minutes * 60`. Daylight-saving transitions do not change the bucket length. Wall-clock conversion uses the location timezone.
- The ledger row is the lock target and a counter. The authoritative check still recomputes unexpired holds and allocations under that lock. A stale counter must not admit a booking.

## Lock order

One transaction, one global order, so two composite bookings cannot deadlock by taking the same locks in opposite orders:

1. Pool ids ascending. For each pool, the pool row, then bucket starts ascending.
2. Asset ids ascending.
3. The guide user id, when the booking names one. `SELECT users.id FOR UPDATE` runs after every asset lock.

Lazy-create missing ledger rows with insert-ignore, then `SELECT … FOR UPDATE`. Insert-ignore is not itself the lock.

The transaction isolation is `READ COMMITTED`. A repeatable-read snapshot taken while loading the service would hide a booking that committed while this transaction waited on the lock. The ledger and asset locks are still `SELECT … FOR UPDATE`.

For each pool, the pool row is locked before its buckets so `pools.quantity` cannot change during the recheck. That stays inside the pool-then-asset order.

A booking that names a departure locks that departure row first, before any pool or asset. Seat and private-tour checks run under that lock. Two departures cannot deadlock with each other, and a booking without a departure never waits on a departure row. Moving a confirmed booking from one departure to another locks that booking row, then both departure rows in ascending id order, still before any pool or asset. A later party on that departure does not allocate the shared resources again, but it still locks and conflict-checks its guide user. Other bookings on the same departure do not count as a guide conflict.

Waitlist promotion runs only after the freeing transaction has committed. It calls the same `hold()` path, so it takes this lock order under `READ COMMITTED`. It does not send email. It queues a notification hook.

Set `innodb_lock_wait_timeout` to 3 seconds for the transaction. On MySQL deadlock `1213` or lock-wait timeout `1205`, retry up to 3 times with jitter. After that, return a clean "no longer available" response. Do not surface the SQL error. Individually tracked assets are exclusive for the buffered interval. `assets.capacity` is stored and does not allow a second overlap.

## Transaction

1. Begin.
2. Resolve eligible resources.
3. Acquire locks in the order above.
4. Recheck eligibility, overlaps, and `allocated + held + requested <= capacity` using live `pools.quantity`.
5. Insert the booking, line items, and allocation or hold rows.
6. Commit only if every check passes. Otherwise roll back.

Availability responses computed outside this transaction are hints. They are not authority.

## Holds, payment, and extensions

- Checkout creates a hold. Duration is `tenant_settings.hold_minutes`, from 5 to 120, default 15. The hold reduces availability immediately.
- When a new hold is refused because the slot is taken, the error keeps `message` and adds `offers` when another time or asset is free. `offers.authoritative` is false. `offers.times` lists the same service within two hours of the requested local clock time, on that day and the next two days, on the same 30-minute step as the booking screen, and skips any start before now. A tour lists other departures in that window that still have seats. `offers.other_asset_ids` uses the same category and location search as an extension or reschedule. A refusal for any other reason stays a message only. These offers are hints. The next hold still rechecks under the lock.
- Mutating booking and payment requests carry an idempotency key. Uniqueness is `(tenant_id, idempotency_key)` plus a hash of the payload. The same key with a different payload returns 409.
- A successful payment never revives an expired hold. Allocate again. If that fails, the booking becomes `payment_exception` and the refund rules in spec Section 7.6 apply.
- An extension takes the same locks for the extra interval, including a move onto another active asset of the same category and location when `asset_id` is sent. A conflict rejects the extension and leaves the original rows. It offers the longest shorter end, on a whole minute, that still fits, and any other active asset of the same category and location that is free for the full requested interval. Those offers are hints. The next extension still rechecks under the lock. A Stripe charge for a higher price follows the fee rules.
- A confirmed tour booking moves onto another scheduled departure of the same service with `Allocator::moveToDeparture`. The party size and the pricing snapshot stay. The move does not charge or refund. The same departure is left as it is. A held booking cannot move. A rental still changes by start and end. Seat and private checks use the target and leave this party out. When this party holds the boat or pool and someone else is still on the source departure, those resource rows stay on the source interval and move to the oldest confirmed party there, or the oldest held party if nobody is confirmed. A held party receives holds. When nobody else is on the source, those rows are released. The target's first party keeps the resources; this party takes a seat only. When the target has no resource holder, this party is allocated for its own size at the target time, after the handoff or release, so an overlapping boat stays taken. Parties on the target share its guide. Otherwise this party keeps its guide, and that guide is copied to the party that inherited the boat when that party had none. A guide already booked on an overlapping departure is refused. A conflict rolls the move back.
- A reschedule or asset reassignment of a confirmed booking takes the same locks for the new interval, excludes that booking from the recheck, and replaces its allocations only when the recheck passes. A conflict leaves the original rows in place. Reassignment stays on an active asset in the same category and location. The source of a reassignment may already be retired, so a booking can move off it. A new hold still refuses that retired asset. `/staff/schedule` moves a confirmed booking through that same transaction, including a new start and a different asset together. The length stays the same. A refusal because the time or asset is taken adds the nearby times and same-category assets a refused hold already offers. Those offers are hints. The quoted price stays until increase checkout or a decrease refund.
- Retiring an asset leaves its past allocations in place and flags future bookings that use it. It does not cancel or reassign them. An optional replacement in the same category and location is offered for each future confirmed booking. Accepting that offer is the reassignment above.
- Naming a guide on a held or confirmed booking locks that booking, and every other booking on the same departure, in id order, then the guide user. Live parties on that departure store the same guide. The conflict check uses the buffered allocation interval, or the customer interval when a party has no allocation. A conflict leaves every guide as it was. The price, allocations, and payments are not rewritten. An expired hold cannot be assigned a guide.
- A party-size change of a held or confirmed booking takes those same locks for the current interval, excludes that booking from the recheck, and replaces its holds or allocations only when the recheck passes. A conflict leaves the original rows in place and offers a smaller party that fits, or another asset in the same category. The party cannot shrink below named participants already stored. A per-person add-on line is retotaled from its stored unit price. Flat and each lines stay. A later party on a departure changes its seat count under the departure lock and does not allocate the shared resources again. The pricing snapshot is left for the increase-checkout and decrease-refund paths, which include those add-on lines in the fee basis.

## Phase 3 tests

Run on MySQL or MariaDB, never SQLite:

- Two requests for the last asset.
- Two requests for the last N units of a pool.
- A tour and a rental competing for the same kayaks.
- An extension competing with a new booking.
- A party-size increase competing with another allocation for the last pool units.
- `America/New_York` spring-forward, fall-back, and a multi-day rental that spans each.

Assertions: at most one conflicting success, capacity never negative, no orphan allocation or hold after a rollback.

The current `tests/Feature/Database/RowLockTest.php` only proves that a second connection waits on `SELECT … FOR UPDATE`. It is not the suite above.
