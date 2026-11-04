# Group size and trip type — design note

Testers asked for two new planner inputs: **how many people** are going, and
**what kind of trip** it is (family, friends, couple, …).

This note says what those inputs can honestly change, what they cannot, where
the code would go, and which part of it is dangerous. Written 2026-11-04,
against the planner as it stands at `23f4991`.

---

## 1. What the planner takes today

`app/Http/Requests/Trip/QuoteTripRequest.php` is the whole contract — towns,
interests, days, radius, stay, arrival, departure. One class, shared by
`quote` and `plan`, deliberately: *"the two must accept exactly the same input
or a quote stops predicting its own plan."*

Answers land in `saved_trips.filters`, a **JSON column**. Both new fields fit
there with **no migration**.

## 2. The constraint that decides everything

**No site carries suitability data.** All 4,334 were checked:

| Column | Populated |
|---|---|
| `speciality` | 1,660 |
| `meta_data` | 1,662 |
| `rules` | **0** |

Nothing about children, accessibility, difficulty, or capacity. The model is
told even less — `CandidateFetcher::project()` sends `site_id, name,
categories, lat, lng, about` (160 characters of description).

Commerce has no party size either: no `capacity`, `pax` or `occupancy` on
products or variants.

**Therefore trip type cannot filter candidates.** Any UI promising
"family-friendly places" would be shipping the model's guesswork as a
recommendation about where to take a four-year-old. That is the one line in
this note worth arguing about before anything is built.

## 3. What the two inputs can really change

Ranked by value delivered per unit of risk.

### A. Pacing — the real win, and it needs no new data

`min_stops_per_day` (2) and `max_stops_per_day` (5) are global `ai_configs`
values. A couple can do five stops in a day. Two toddlers and a grandparent
cannot. Making those **per trip** changes the plan's shape rather than its
wording, which is the difference between a feature and a label.

### B. Prose — cheapest real improvement available

The model writes every note. Told "4 adults, 2 children under 8", *"arrange a
boat"* becomes *"the shallow end is safer for children"*. One payload field,
no new data, no schema change.

### C. Category weighting — a proxy, and it must be labelled one

The candidate query could bias `suggested` by type using codes that already
exist:

| Type | Codes that lean that way |
|---|---|
| couple | `beach`, `kokan_view`, `scenic_route`, `cruise`, `heritage_site` |
| family | `garden_park`, `playground`, `zoo`, `agro_tourism`, `temple` |
| friends | `water_sport`, `sport_activity`, `fort`, `cave`, `waterfall` |

This is a heuristic over category names, not a judgement about suitability.
Useful; not evidence.

### D. The downstream prize

Group size is exactly what a stay, taxi or boat booking needs. If marketplace
booking is coming, pax is the join, and capturing it in the planner is free.

## 4. The recommendation, in detail

**Do A and B first, as one change. Treat C as a separate decision. Do not
promise D or anything that sounds like real suitability.**

### Why pacing is both the best and the most dangerous part

Pacing looks like a one-line config change. It is not. The value is read in
**six independent places**, each calling `AiConfig::get` with its own default:

```
TripPlanner.php:72          max_stops_per_day  → the cart_too_big capacity guard
TripPlanner.php:271         min_stops_per_day  → not_enough_places
ItineraryAssembler.php:47   min_stops_per_day  → thin-day rejection
ItineraryAssembler.php:48   max_stops_per_day  → the top-up ceiling
Ai/FakeClient.php:28        max_stops_per_day  → its capacity slice
ItineraryPrompt.php:30,31   both               → what the model is TOLD
```

Three of those enforce, one instructs, one guards the gate, one is the stand-in
model. **Move some and not others and they disagree with each other.** The
concrete failure: lower the prompt's maximum for a family, leave the
assembler's alone, and the model dutifully returns three stops a day while the
assembler happily accepts five — the setting appears to work and silently does
nothing. Invert it and the assembler rejects plans the prompt asked for, which
costs the traveller a credit and returns nothing.

This is not hypothetical. The fake driver and the assembler already disagreed
once, for exactly this reason: `FakeClient` sliced the combined list to
`days × max_stops_per_day` while the assembler treated a dropped pick as
fatal, so the stand-in model produced plans its own validator rejected. It
took a test to find, and `ai_driver` defaults to `fake`, so it was wrong on
every developer machine.

**So the shape of the change matters more than the change.** Resolve pacing
**once** per generation into a small value object, and pass it down:

```php
final class Pacing
{
    public function __construct(
        public readonly int $minPerDay,
        public readonly int $maxPerDay,
    ) {}

    public static function for(array $filters): self { /* … */ }
}
```

`TripPlanner` builds it once and hands it to the prompt, the assembler and the
capacity guard. The six `AiConfig::get` calls become one. That refactor is
worth doing **even if group size is never shipped**, because six call sites
that must agree and have no mechanism forcing them to is a standing bug.

### How the numbers should be derived

Deliberately conservative — the cost of a day that is too full is a bad trip;
the cost of one too empty is a traveller who adds a stop.

| Group | min | max | Why |
|---|---|---|---|
| couple, solo | 2 | 5 | today's defaults |
| friends | 2 | 5 | today's defaults |
| family, no young children | 2 | 4 | more coordination per stop |
| family with a child under ~8 | 2 | 3 | naps, meals, patience |
| any group with 8+ people | 2 | 3 | one bus, one lunch decision |

The floor stays at 2 throughout: below that it is not a day out, and the
assembler's thin-day rule exists for good reason.

These belong in `ai_configs` as a small table, not hardcoded, following the
pattern `interest_parent_codes` already uses — a CSV an admin edits without a
deploy, which matters because the backend runs Octane and a deploy is a reload
and a wait.

### Why trip type should be a config-driven vocabulary

`CandidateFetcher::interestOptions()` reads its parent codes from
`ai_configs`. Trip types should do the same: `trip_types` as a CSV, surfaced
by `questionSet` exactly as interests are. The app renders what the server
offers, so adding "pilgrimage" or "office group" later is a config edit, not a
release. Hardcoding an enum in the app guarantees a version where the server
knows a type the app cannot draw.

### What I would not build

- **A "family friendly" badge or filter.** No data backs it. See §2.
- **Safety advice derived from group composition** — "this fort has steps,
  not suitable for elderly". The model would be inventing it. Saying nothing
  is better than saying something confident and wrong about someone's parents.
- **Per-person pricing.** Budget is already advertised as `['available' =>
  false]`; group size makes it look closer than it is.

## 5. Implementation map

**Backend**

| File | Change |
|---|---|
| `Http/Requests/Trip/QuoteTripRequest.php` | `group.adults` (int, 1–30), `group.children` (int, 0–20), `group.youngest_age` (nullable int), `trip_type` (nullable, validated against the `trip_types` config) |
| `Services/Trip/Pacing.php` | **new** — resolves min/max once |
| `Services/Trip/TripPlanner.php` | build `Pacing` once; pass to prompt, assembler, capacity guard; drop two `AiConfig::get` calls |
| `Services/Trip/ItineraryPrompt.php` | take `Pacing`; add `group` to the payload; drop two config reads |
| `Services/Trip/ItineraryAssembler.php` | take `Pacing`; drop two config reads |
| `Services/Trip/Ai/FakeClient.php` | take the max from the prompt payload, not the config |
| `Http/Controllers/User/V2/TripPlannerController.php` | `questionSet` offers `trip_types` and group defaults |
| `Admin/V2/AiDashboardController.php` | validation for the new config keys so they are editable |

**Mobile**

| File | Change |
|---|---|
| `Screens/TripWhereScreen.js` | a "who is going" section — stepper for adults/children, chips for type |
| `Services/trip.js`, `Reducers/tripSlice.js` | carry `group` and `trip_type` in filters |
| `localization/translations/{en,mr}.json` | new strings, both languages |

**Tests** — group changes pacing; pacing reaches all three consumers; the
cache does not collide (below); an unknown `trip_type` degrades rather than
422s, the way a retired category code already does.

## 6. Traps

1. **`ItineraryPrompt::hash()` must include both fields.** It currently
   fingerprints days + site ids + arrival. Add group without touching it and a
   family is served a couple's cached plan for the same places — silently, and
   only for the second traveller, which is the hardest kind to reproduce.
2. **`QuoteTripRequest` is shared.** Adding there covers quote and plan at
   once; that is the point of the class. Do not add to `PlanTripRequest`.
3. **Step 1 is already long** — towns, interests, days, stay, arrival, mode.
   The two home-card buttons were merged specifically to cut friction. Default
   to 2 adults and leave type optional; do not add two more required sections.
4. **There is a dead channel already built.** `ItineraryPrompt::build()` takes
   `?string $freeText` and emits `traveller_note`, injection-hardened and
   documented — and **nothing has ever passed it**. A free-text "anything else
   we should know?" box is the cheapest version of this whole feature and is
   available today. Worth shipping first to see what people actually type.
5. **Privacy.** Children's ages in `saved_trips.filters`, on a row that has
   `share_token` and `is_public`. Decide whether group composition travels
   with a shared plan. The default should be that it does not.

## 7. Effort

| | |
|---|---|
| Pacing refactor (worth doing alone) | ~0.5d |
| Group + type through request, prompt, hash, questionSet | ~0.5d |
| Mobile form, state, i18n ×2 | ~0.5d |
| Tests | ~0.5d |
| **Phase 1 total** | **~2d** |
| Category weighting (phase 2) | ~0.5d |
| Real suitability data (phase 3) | a data project over 4,334 sites, not a code change |

## 8. Open questions

- Is **solo** a trip type, or is it group size 1? They overlap and the form
  should not ask twice.
- Do children's ages matter, or is a count enough? Ages give better pacing and
  are more sensitive to store.
- Should group size travel into a shared trip, or be stripped on share?
- Is this wanted on the express ("choose for me") path too, or only the
  stepper? Express currently sends filters straight to `planTrip`.
