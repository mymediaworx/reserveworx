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

A unit is free only when it is eligible for the service, active, not blocked, not in maintenance, the location is open, the date is not a blackout, and no allocation, active rental, or unexpired hold overlaps the buffered interval. An active checkout (`returned_at` null) occupies `[checked_out_at, max(due_at, now()) + turnaround)`. That is what extends the block when a rental runs past its due time. Returning the rental sets each allocation end to `now() + turnaround` and the checkout stops counting. A pool checkout is not added again for a bucket the same booking's allocation already covers.

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

A booking that names a departure locks that departure row first, before any pool or asset. Seat and private-tour checks run under that lock. Two departures cannot deadlock with each other, and a booking without a departure never waits on a departure row. A later party on that departure does not allocate the shared resources again, but it still locks and conflict-checks its guide user. Other bookings on the same departure do not count as a guide conflict.

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
- Mutating booking and payment requests carry an idempotency key. Uniqueness is `(tenant_id, idempotency_key)` plus a hash of the payload. The same key with a different payload returns 409.
- A successful payment never revives an expired hold. Allocate again. If that fails, the booking becomes `payment_exception` and the refund rules in spec Section 7.6 apply.
- An extension takes the same locks for the extra interval. A conflict rejects the extension and offers a shorter extension or a different asset. A Stripe charge for the extension follows the fee rules.
- A reschedule or asset reassignment of a confirmed booking takes the same locks for the new interval, excludes that booking from the recheck, and replaces its allocations only when the recheck passes. A conflict leaves the original rows in place. Reassignment stays on an active asset in the same category and location.

## Phase 3 tests

Run on MySQL or MariaDB, never SQLite:

- Two requests for the last asset.
- Two requests for the last N units of a pool.
- A tour and a rental competing for the same kayaks.
- An extension competing with a new booking.
- `America/New_York` spring-forward, fall-back, and a multi-day rental that spans each.

Assertions: at most one conflicting success, capacity never negative, no orphan allocation or hold after a rollback.

The current `tests/Feature/Database/RowLockTest.php` only proves that a second connection waits on `SELECT … FOR UPDATE`. It is not the suite above.
