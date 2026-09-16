# Admin User Search — Why "name / email / phone" Filters Return Nothing

**Endpoint:** `POST /admin/v2/listUsers`
**Date:** 2026-09-16
**Verified against:** production (`api.tourkokan.com`) with an admin token.

---

## TL;DR

There is exactly **one** filter parameter — `search` — and it matches **name, email and
mobile**, but **only as an exact, full-value match**. Partial / substring search returns
nothing, by design, because those fields are encrypted at rest. The filter is not broken; it
is exact-match only.

---

## What was observed

A search for a partial value (`Abhi`, `98765`, `abhijit`) returns zero rows, so it looks like
no filter works.

## Proof (production, read-only)

| Request body | Result |
|---|---|
| `{"apitype":"list"}` | 69 users |
| `{"apitype":"list","search":"Abhijit Kamble"}` (exact full name) | **1** ✓ |
| `{"apitype":"list","search":"Abhi"}` (partial) | **0** ✗ |

So the value **does** filter — but only when the whole string is typed.

---

## Why — encryption, not a bug

`users.name`, `users.email` and `users.mobile` are **encrypted at rest**. You cannot run
`LIKE '%abhi%'` against ciphertext. The only way to find an encrypted value is a **blind
index** — a deterministic hash of the *entire* value, stored in a sibling column:

```php
// app/Traits/EncryptsPersonalData.php
name_hash   = hash_hmac('sha256', strtolower(trim($fullName)),   APP_KEY)
email_hash  = hash_hmac('sha256', strtolower(trim($fullEmail)),  APP_KEY)
mobile_hash = hash_hmac('sha256', strtolower(trim($fullMobile)), APP_KEY)
```

The search hashes the term and matches it against `name_hash OR email_hash OR mobile_hash`:

```php
// AuthController::listUsers
if ($request->filled('search')) {
    $hash = User::makeBlindIndex($request->search);
    $query->where(fn($q) => $q
        ->where('name_hash', $hash)
        ->orWhere('email_hash', $hash)
        ->orWhere('mobile_hash', $hash));
}
```

A hash matches only when the **whole** input is identical (case-insensitive, trimmed).
`Abhi` hashes to something completely unrelated to `Abhijit Kamble`, so it never matches.
Substring matching on encrypted columns is **impossible** without changing the storage model.

### What works vs what does not

| Works (exact, case-insensitive) | Does not work |
|---|---|
| `Abhijit Kamble` | `Abhi`, `abhijit` |
| `abhijitkamble1795@gmail.com` | `abhijit@`, `gmail` |
| `9876543210` (full 10 digits) | `98765` |

---

## The one filter, precisely

| Param | Type | Behaviour |
|---|---|---|
| `apitype` | `list` \| `dropdown` | **required** — column set, not a filter |
| `search` | string | the only filter: exact full value across name / email / mobile (OR) |
| `per_page` | int (max 30) | pagination size |

---

## Options to improve it

You cannot have both strong PII encryption **and** free substring search on the same field.
Realistic choices:

1. **Keep exact-only, fix the UX.** Label the admin box "enter full name / email / phone".
   Cheapest; nothing changes server-side. The filter is exact-match, not broken.
2. **Add non-PII filters.** `id`, `role`, `registered_from`, `isVerified`, date range — none
   are encrypted, so they filter normally. Quick, high-value, pairs well with #1.
3. **Token / prefix blind indexes.** Store extra hashes (per name-word, email local-part) so
   first-name or single-word search works. Partial *within a token*, not arbitrary
   substrings. Moderate work, mild privacy trade-off.
4. **Full substring search.** Requires storing name/email/mobile unencrypted (or a searchable
   lowercase copy), which undoes the PII encryption the platform deliberately added. **Not
   recommended.**

**Recommendation:** #1 + #2. Label the search as full-value and add the non-encrypted filters
so admins have working, normal filters; add #3 only if first-name search is genuinely needed.

---

## For the admin-panel team

- The search box must be told to send the **complete** name, email, or phone — a partial value
  will always return an empty list.
- Consider adding separate inputs for the non-encrypted filters (id, role, registered_from,
  verified, date) once the backend exposes them.
