# Marathi names, and the select that loses them

A bug that has been fixed four times. This is here so there is not a fifth.

## The trap, in one paragraph

`Site`, `Category` and a few others carry an English column and a Marathi one
— `name`/`mr_name`, `tag_line`/`mr_tag_line`, `description`/`mr_description`.
The model translates through an accessor:

```php
public function getNameAttribute($value)
{
    $language = auth()->user()?->language ?? 'en';
    return empty($language) || $language === 'en'
        ? $value
        : ($this->mr_name == "" ? $value : $this->mr_name);
}
```

Read that last line against a **partial select**. If `mr_name` was not in the
column list, `$this->mr_name` is `null`; in PHP `null == ""` is `true`; the
accessor concludes there is no Marathi name and returns English. No error, no
warning, no sign in the payload. The only symptom is a Marathi reader seeing
English on one screen and Marathi on the next.

## Three mechanisms, not one

This is the part that makes a single blanket fix wrong, and it is worth
knowing before touching any of it.

| Model | How it translates | What the payload must carry |
|---|---|---|
| `Site` | **Server accessor**, 3 fields: `name`, `tag_line`, `description` | the `mr_*` columns must be SELECTED; they are then `$hidden` from JSON |
| `Category` | **Server accessor**, 1 field: `name` | `mr_name` selected; exposed |
| `Product`, `ProductCategory` | **Client side** — the app's `localised()` in `src/Services/marketplace.js` reads `obj.mr_name` itself | `mr_name` / `mr_description` selected AND present in the JSON |

So a site and a product look identical in the database and need opposite
things from the response:

- A **site** hides its `mr_*` columns deliberately. The app never sees them
  and never needs to; the server has already answered in the right language.
  Verifying a site therefore means reading the **value** — a missing
  `mr_name` key proves nothing.
- A **product** exposes them, because the app does the choosing. Verifying a
  product means checking the **key is present** — the value will be English
  either way, since the server does not translate it.

Use the wrong test and you get the wrong answer in both directions.

## The three shapes it hides in

A sweep that knows only the first will miss the others. All three have
happened, in that order.

```php
// 1. Relation string
->with(['categories:id,name,code'])            // thin
->with(['categories:id,name,mr_name,code'])    // correct

// 2. A `fields` option that becomes a select — CategoryService
'fields' => ['id', 'name', 'code']             // thin

// 3. A bare select, on the instance OR statically
->select('id', 'name', 'tag_line')             // thin
Site::select('id', 'name', 'parent_id')        // thin, and `::` not `->`
```

`CategoryService::getPaginated()` takes its parent columns as a `fields`
option that ends up in `->select()`. That is exactly how the landing page
survived a sweep that fixed forty other selects: the service's own
`subCategories` eager-load named `mr_name` and translated, while the parents
took the caller's list and did not. Marathi readers got `शहर` nested under
`Destination`.

## History

| | What was fixed | What it missed |
|---|---|---|
| `65d6a5e` | the planner's candidate query | `ItineraryAssembler` |
| (trip work) | the assembler — and it froze the *generating* user's language into a stored payload, so `getRawOriginal()` is used there | 40 selects elsewhere |
| `4fb0213` | those 40, across site/route/gallery/landing/vendor/contact controllers | the `fields` shape |
| `d31b44d` | the landing page's parent categories | sites and products |
| `fd9498d` | 47 more across sites, products and product categories | four bare `select()` lists |
| (this pass) | those four, incl. `SiteService`'s hot places — `tag_line` with no `mr_tag_line`, two columns after a correct `mr_name` | admin endpoints unverified (see below) |

Note the assembler is a special case: `saved_trips.itinerary` is **stored**,
so an accessor would bake whichever language generated the trip into a
payload every later reader sees. It carries `getRawOriginal('name')` plus
`mr_name` and lets the app choose, like a product.

## The guards

`tests/Feature/CategoryNameLocalisationTest.php` fails the build and names
the file and line. It covers both shapes:

- `test_no_category_eager_load_forgets_the_marathi_name`
- `test_no_category_field_list_forgets_the_marathi_name`
- `test_no_site_or_product_select_forgets_its_translation`
- `test_no_bare_select_forgets_a_translation_column` — matches `->select(`
  **and** `Model::select(`, which is how `SiteService` and `app/helpers.php`
  slipped past a scan that only knew the arrow form. It judges a column list
  only when it carries a marker column (`parent_id`, `bus_stop_type`,
  `booking_type`…), because `select('id','name','email')` on a User is
  correct and there are many of those.

Deliberately skipped by the guards, so they do not cry wolf:

- **Commented-out code.** Four of five stragglers in one pass were dead
  comments; a check people learn to ignore is worse than no check.
- **`Controllers/API/V1/*`** — unrouted legacy. No `route:list` entry, no
  importer.
- **Models with no `mr_` columns** — `user`, `roles`, `plan`, `reviewer`,
  `Route`. A `user:id,name` select is correct as written.

The relation guard matches by **relation name**, which is all a select string
carries. A name that stops pointing at a translatable model costs one
harmless extra column; a name that is missing costs a Marathi reader. Add new
relation names to the list when models gain `mr_` columns.

## How to verify a deploy

Do not trust the sweep — read it in Marathi. Both times a fix was incomplete,
this is what caught it.

```bash
# a throwaway guest, switched to Marathi
curl -s -X POST https://api-test.tourkokan.com/api/v2/auth/register \
  -H 'User-Agent: okhttp/4.9.2' -H 'Content-Type: application/json' \
  -d '{"is_guest":true,"name":"Guest_check"}'
# then updateProfile {"language":"mr"} with that token
```

The `okhttp` User-Agent matters: api-test's WAF 403s a `Python-urllib` agent.

Then read `landingpage`, `listProducts` and `trip/resolveMapLink` with a
point outside the district. Expect `देवगड` with the tag line
`सुस्वादू हापूस आंब्यांची भूमी`, `mr_name` keys on products, and a refusal
whose nearby list reads `विजयदुर्ग किल्ला`.

**If a fix looks absent, check whether it is deployed before debugging it.**
For a payload that exposes `mr_*` the tell is the key: it appears whether or
not the value translates, so an absent key means the code is not running. A
`d31b44d` check survived both a deploy and an `octane:reload` showing no key
at all, and only a redeploy fixed it. The backend runs Octane, so workers
hold old code until reloaded.

## Still unverified

The `Admin/V2/*` selects — roughly a third of the last sweep — have not been
read from a live admin session. They rest on the suite and the guards. A
Marathi-speaking admin is the person who would notice first.

`mr_image` was checked and needs nothing: it exists only on `banners`, and
every banner query loads the full model rather than a column list, so
`Banner::getImageAttribute` can always read it. Worth re-checking if anyone
adds a partial select there. Note it reads the language from
`request()->attributes`, which `PreMiddleware` sets from `$user->language` —
a different route to the same value as the other accessors.

One knowing exception: `app/helpers.php` selects `mr_name` on a site whose
`name` it never reads — only `id` is used, and the `where('name', …)` matches
the raw column. It carries the column anyway so the rule stays "if you select
`name` on a Site, select `mr_name`", with no exception for a reader to
remember.
