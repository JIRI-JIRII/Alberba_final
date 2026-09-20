# Alberba Dental Clinic — Code Review & Improvements

A review of the PHP/MySQL clinic management system, with fixes applied.

---

## First, what was already good

Worth saying up front, because it shaped where the effort went:

- **Every page is access-controlled.** All 23 role pages call `checkLogin()` and `checkRole()`. No unguarded pages.
- **No SQL injection.** The dynamic search/filter queries escape their inputs with `mysqli_real_escape_string()` and whitelist `ORDER BY` values against a fixed list. I specifically went looking for injection and did not find any.
- **Passwords are properly hashed** with `password_hash()` / `password_verify()`.
- **Ownership is enforced** where it matters — a patient cancelling an appointment is checked against `patient_id`, so one patient cannot cancel another's booking.
- **Booking re-validates server-side.** The posted time slot is re-checked against live availability rather than trusted.
- **The schema is well designed**: InnoDB throughout, real foreign keys, sensible indexes, soft deletes, roles normalised into their own table.

The problems were elsewhere.

---

## Critical

### 1. No CSRF protection anywhere (42 forms)

Not a single token existed across the app. Every state-changing action — archiving a user, cancelling an appointment, marking a bill paid, changing a username, adding a service — could be triggered by a logged-in staff member simply visiting a malicious page.

**Fix:** new `config/session.php` providing `csrf_token()`, `csrf_field()`, `csrf_verify()` and `require_csrf()`. A token field was added to all 42 POST forms and a `require_csrf()` guard to all 13 POST handlers.

Verified end-to-end against a live page: requests with no token and with a forged token are both rejected and write nothing to the database; a request with the correct token succeeds normally.

### 2. Passwords were mangled before hashing

`login.php` and `register.php` both ran the password through `FILTER_SANITIZE_SPECIAL_CHARS` before hashing/verifying. A password like `P@ss&word<2026>` was silently rewritten to `P@ss&amp;word&lt;2026&gt;`.

Login worked only because both sides corrupted it identically — a latent trap where fixing either file alone would have locked out every affected user. It also quietly reduced password entropy.

**Fix:** passwords are now read raw from `$_POST`. They are hashed, never rendered as HTML, so sanitising them was never correct.

> **Migration note:** any existing account whose password contains `& < > " '` will need a password reset. Purely alphanumeric passwords are unaffected.

### 3. Double-booking race condition

`get_available_slots()` followed by `INSERT` is check-then-act. Two patients could both be offered the same slot, both pass the check, and both book it.

**This was not theoretical.** Racing 10 concurrent bookings through the original code put **7 patients into a single appointment slot**.

**Fix:** new `book_appointment_safely()` wraps the overlap re-check and the insert in one transaction, locking the dentist's rows for that date with `SELECT ... FOR UPDATE`. Both the patient and receptionist booking paths now use it.

Re-running the same 10-way race against the fixed code: exactly 1 booking wins, 9 are cleanly rejected, 1 row in the database.

---

## Broken features

### 4. `receptionist/walk-in.php` — never worked at all

Written against an older schema that no longer exists. It queried a `dentists` table (there isn't one — dentists are `employees`) and wrote to `users.password`, `users.role`, `patients.phone`, `patients.is_walk_in` and `appointments.appointment_time`. Not one of those is a real column. Every request fatally errored.

It was also orphaned — no page linked to it, and the receptionist sidebar never included it. Its functionality already exists correctly in `patients.php` (walk-in registration) and `appointments.php?view=book` (walk-in booking).

**Fix:** replaced with a documented redirect to the working flow, so old bookmarks still land somewhere useful.

### 5. `dentist/schedule.php` — same problem, but reachable

This one is linked in the dentist sidebar as **"Calendar"**, so any dentist clicking it hit a fatal error. Same legacy schema: `dentists` table, `p.phone`, `a.appointment_time`, plus a stylesheet path that doesn't exist.

*This was found by running the pages, not by reading them — a static review missed it.*

**Fix:** rebuilt against the real schema as a working 7-day calendar with week navigation, showing each day's appointments alongside the dentist's actual working hours. It also runs one query for the whole week instead of the original seven-queries-in-a-loop.

### 6. `checkRole()` redirected to a non-existent page

Redirected to `../index.html`, with a code comment asserting there was no `index.php` — the opposite of the truth. Any wrong-role access produced a 404 instead of the landing page.

**Fix:** corrected to `../index.php`.

### 7. `dentist_schedules` was dead weight

The table existed, was indexed, had foreign keys — and was referenced by **zero lines of PHP**. Availability came from clinic-wide opening hours only, so a dentist could be booked on a day they don't work.

**Fix:** new `get_working_windows()` intersects clinic hours with the dentist's shifts.

**Backwards compatible by design:** a dentist with no rows in `dentist_schedules` is treated as "not configured" and falls back to full clinic hours. Existing installs with an empty table behave exactly as before.

---

## Hardening

### 8. Session cookies

No cookie hardening existed. Now set before `session_start()`:

- `httponly` — XSS cannot read the session cookie
- `SameSite=Lax` — the browser won't attach it to cross-site POSTs (defence in depth with the CSRF tokens)
- `secure` — auto-detected, so plain-HTTP XAMPP/localhost still works
- `use_strict_mode` — rejects session IDs the server never issued (session fixation)

### 9. Login brute-force throttling

Unlimited password guessing was possible. Added `func/login_throttle.php`: 8 failures per account per IP, 30 per IP overall, in a 15-minute window, cleared on successful login.

**Fails open if the table is missing**, so login can never break on an un-migrated database.

### 10. Database configuration

- `mysqli_connect()` failure printed the driver message, leaking host, database name and username. Now logged server-side with a generic 503 to the user.
- Config variables renamed to `$db_*`. The originals were `$host`/`$username`/`$password` — the same names several pages use for `$_POST` data. Nothing broke in practice, but it was a real footgun.
- `mysqli_report()` set explicitly so behaviour is consistent across PHP versions, and so the app's existing `try/catch` transaction blocks actually fire.

### 11. Password rehashing

Successful logins now check `password_needs_rehash()` and transparently upgrade the stored hash when PHP's default algorithm or cost changes.

### 12. `like_escape()` helper

Search inputs were escaped against injection but `%` and `_` were left live, so searching for `%` matched every record.

---

## New files

| File | Purpose |
|---|---|
| `config/session.php` | Hardened session bootstrap + CSRF helpers |
| `func/login_throttle.php` | Brute-force throttling |
| `migrations/2026_01_login_attempts.sql` | Adds `login_attempts` to an existing database |

`login_attempts` was also added to `alberba_dental_clinic.sql` so fresh installs get it automatically.

---

## How to apply

**Existing database:**

```bash
mysql -u root alberba_dental_clinic < migrations/2026_01_login_attempts.sql
```

**Fresh install:** load `alberba_dental_clinic.sql` as usual — the new table is included.

Nothing else changes. No configuration edits are required.

---

## Verification

All checks run against a live MariaDB instance with the real schema loaded.

| Check | Result |
|---|---|
| PHP 8.3 syntax, all 32 files | pass |
| Unit + integration suite | 51/51 pass |
| CSRF end-to-end (no token / forged / valid) | 7/7 pass |
| 10-way concurrent booking race | exactly 1 winner, 1 row |
| Page render smoke test, all 23 role pages | no errors, warnings or deprecations |

The suite covers backwards compatibility for unconfigured dentist schedules, shift clipping to clinic hours, clinic closures, duration-aware slot fitting, overlap rejection (exact, partial, adjacent), cancelled slots being freed, throttle lock and release, and token validation.

---

## Recommended next steps

Not done here, but worth planning:

1. **Verify the `$_SESSION['role']` capitalisation convention.** `checkRole()` is case-insensitive, which papers over pages passing `'dentist'` vs `'Dentist'`. It works, but it's inconsistent.
2. **Move the dynamic search queries to prepared statements.** They're safe today because they escape correctly, but the pattern invites a future mistake.
3. **Add a password reset flow.** There is currently no way for a patient to recover an account.
4. **Consider a DB-level exclusion constraint** on overlapping appointments as a final backstop beneath the application lock.
