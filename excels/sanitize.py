#!/usr/bin/env python3
"""
Konkan Tourism Data Sanitization Script
Cleans new_sites.xls and AllRoutesWithStopsCSV.csv for database import.

Outputs (all in excels/sanitized/):
  cleaned_new_sites.xlsx  — sites with original_name, is_duplicate, duplicate_of
  cleaned_routes.xlsx     — routes with original_bstop_name, corrected_bstop_name,
                            match_confidence, match_status
  uncertain_matches.xlsx  — stops with 60-89 score
  unmatched_stops.xlsx    — stops with <60 score
  duplicate_sites.xlsx    — duplicate site rows
"""

import os
import re
import sys
import warnings
from collections import Counter, defaultdict

import xlrd
import pandas as pd
from thefuzz import fuzz
from thefuzz import process as fuzz_process

warnings.filterwarnings("ignore")

# ─────────────────────────────────────────────────────────────────────────────
# Paths
# ─────────────────────────────────────────────────────────────────────────────
BASE_DIR    = "/Users/pranavkamble/Documents/tourkokan-backend/excels"
SITES_FILE  = os.path.join(BASE_DIR, "new_sites.xls")
ROUTES_FILE = os.path.join(BASE_DIR, "AllRoutesWithStopsCSV.csv")
OUT_DIR     = os.path.join(BASE_DIR, "sanitized")
os.makedirs(OUT_DIR, exist_ok=True)

# ─────────────────────────────────────────────────────────────────────────────
# Step 1 — Load sites file
# ─────────────────────────────────────────────────────────────────────────────
print("Loading sites file …")
wb = xlrd.open_workbook(SITES_FILE)
sh = wb.sheet_by_index(0)
headers = sh.row_values(0)
rows    = [sh.row_values(r) for r in range(1, sh.nrows)]
sites_df = pd.DataFrame(rows, columns=headers)
print(f"  Loaded {len(sites_df)} rows, {len(headers)} columns")

# ─────────────────────────────────────────────────────────────────────────────
# Step 2 — Name standardisation helpers
# ─────────────────────────────────────────────────────────────────────────────

def basic_clean(text: str) -> str:
    """
    Strip whitespace (incl. non-breaking), collapse internal spaces,
    remove trailing dots/commas/semicolons, apply Title Case intelligently.
    """
    if not isinstance(text, str):
        text = str(text) if text else ""
    # Replace non-breaking space and zero-width chars
    text = text.replace('\xa0', ' ').replace('​', '').strip()
    # Collapse internal spaces
    text = re.sub(r' +', ' ', text)
    # Remove trailing punctuation
    text = re.sub(r'[.,;:]+$', '', text).strip()
    if not text:
        return ''
    # Selective Title Case:
    # - all-caps tokens > 3 chars → title case them
    # - all-lower tokens → title case them
    # - mixed-case tokens → leave as-is (e.g. "MSRTC", "McDonald")
    words = text.split()
    result = []
    for w in words:
        if w.isupper() and len(w) > 2:
            result.append(w.title())
        elif w.islower():
            result.append(w.title())
        else:
            result.append(w)
    return ' '.join(result)


def build_vw_correction_map(name_list: list) -> dict:
    """
    For every name that has both a 'v' variant and a 'w' variant present
    in the corpus, pick the majority form and build a minority→majority map.
    Only checks inter-vowel v/w swaps (the main Konkan transliteration issue).
    """
    clean_counts = Counter(basic_clean(n) for n in name_list if n and str(n).strip())
    correction = {}
    visited = set()

    for name in list(clean_counts.keys()):
        if name in visited:
            continue
        # Generate the alternate v↔w form (between vowels only)
        alt_v = re.sub(r'(?<=[aeiouAEIOU])w(?=[aeiouAEIOU])', 'v', name)
        alt_w = re.sub(r'(?<=[aeiouAEIOU])v(?=[aeiouAEIOU])', 'w', name)

        for alt in (alt_v, alt_w):
            if alt == name or alt in visited:
                continue
            if alt in clean_counts:
                # Both name and alt exist — pick the higher-frequency form
                cnt_name = clean_counts[name]
                cnt_alt  = clean_counts[alt]
                if cnt_name >= cnt_alt:
                    correction[alt] = name   # alt → name (name is majority)
                else:
                    correction[name] = alt   # name → alt (alt is majority)
                visited.add(name)
                visited.add(alt)

    return correction


# Explicit known corrections (applied before auto-detected map)
EXPLICIT_CORRECTIONS = {
    # v/w – Kankavli is the only form in the sites file
    'Kankawli':    'Kankavli',
    # Vengurle vs Vengurla: Vengurla appears more in parent_code
    'Vengurle':    'Vengurla',
    # Sawantwadi vs Savantwadi: Sawantwadi is dominant
    'Savantwadi':  'Sawantwadi',
    'Savantvadi':  'Sawantwadi',
    # Typos / alternate spellings seen in data
    'Kudall':      'Kudal',
    # ee-endings (very rare in this dataset, but guard against them)
    'Malwanee':    'Malvani',
}


def apply_explicit(name: str) -> str:
    return EXPLICIT_CORRECTIONS.get(name, name)


def clean_site_name(name: str, vw_map: dict) -> str:
    """Full cleaning pipeline for name / parent_code columns."""
    s = basic_clean(name)
    # Apply explicit corrections
    s = apply_explicit(s)
    # Apply auto-detected v/w majority map
    if s in vw_map:
        s = vw_map[s]
    return s


# ─────────────────────────────────────────────────────────────────────────────
# Build VW correction map from the full corpus of names + parent_codes
# ─────────────────────────────────────────────────────────────────────────────
all_raw_names = (
    list(sites_df['name'].astype(str)) +
    list(sites_df['parent_code'].astype(str))
)
vw_map = build_vw_correction_map(all_raw_names)
print(f"  Built v/w correction map with {len(vw_map)} entries")
if vw_map:
    sample = list(vw_map.items())[:8]
    for k, v in sample:
        print(f"    {k!r} → {v!r}")

# Store originals before any modification
sites_df['original_name'] = sites_df['name'].astype(str).replace('nan', '')

# Apply cleaning
sites_df['name']        = sites_df['name'].astype(str).apply(
    lambda x: clean_site_name(x, vw_map))
sites_df['parent_code'] = sites_df['parent_code'].astype(str).apply(
    lambda x: clean_site_name(x, vw_map))

# Fix spurious 'nan' strings from empty cells
for col in ('name', 'parent_code', 'original_name'):
    sites_df[col] = sites_df[col].replace('nan', '')

# ─────────────────────────────────────────────────────────────────────────────
# Step 3 — Detect duplicates
# ─────────────────────────────────────────────────────────────────────────────
print("Detecting duplicates in sites …")

sites_df['is_duplicate'] = False
sites_df['duplicate_of'] = ''

# Composite key: lower-stripped name + parent_code
sites_df['_key'] = (
    sites_df['name'].str.strip().str.lower()
    + '||'
    + sites_df['parent_code'].str.strip().str.lower()
)

seen_keys: dict = {}   # key → first occurrence index
for idx, row in sites_df.iterrows():
    key = row['_key']
    if not key or key == '||':
        continue
    if key not in seen_keys:
        seen_keys[key] = idx
    else:
        sites_df.at[idx, 'is_duplicate'] = True
        sites_df.at[idx, 'duplicate_of'] = sites_df.at[seen_keys[key], 'name']

sites_df.drop(columns=['_key'], inplace=True)

n_duplicates = int(sites_df['is_duplicate'].sum())
n_canonical  = len(sites_df) - n_duplicates
print(f"  Duplicate rows found   : {n_duplicates}")
print(f"  Unique canonical sites : {n_canonical}")

# ─────────────────────────────────────────────────────────────────────────────
# Step 4 — Load routes file
# ─────────────────────────────────────────────────────────────────────────────
print("\nLoading routes file …")
routes_df = pd.read_csv(ROUTES_FILE, encoding='latin1', low_memory=False, dtype=str)
routes_df = routes_df.fillna('')   # treat NaN as empty string for text ops
print(f"  Loaded {len(routes_df)} rows, {len(routes_df.columns)} columns")

STOP_COLS = ['Bstop Name', 'From Stop Name', 'Till Stop Name', 'Via Stop Name']

# ─────────────────────────────────────────────────────────────────────────────
# Build canonical site-name lookup
# ─────────────────────────────────────────────────────────────────────────────
canonical_sites = (
    sites_df[~sites_df['is_duplicate']]['name']
    .dropna()
    .pipe(lambda s: s[s.str.strip() != ''])
)
site_names_list  = canonical_sites.tolist()
site_names_set   = set(s.strip() for s in site_names_list)
site_names_lower = {s.lower(): s for s in site_names_set}   # fast exact lower match
print(f"  Canonical site names available for matching: {len(site_names_set)}")

# ─────────────────────────────────────────────────────────────────────────────
# Step 5 — Cross-match every unique stop name
# ─────────────────────────────────────────────────────────────────────────────
print("\nCross-matching stop names against site names …")

# Collect unique stop names from all four columns
all_stop_names: set = set()
for col in STOP_COLS:
    if col in routes_df.columns:
        for v in routes_df[col].unique():
            s = str(v).strip()
            if s and s.lower() != 'nan':
                all_stop_names.add(s)

all_stop_names_list = sorted(all_stop_names)
print(f"  Unique stop names to process: {len(all_stop_names_list)}")

# Results: stop_name → (best_match, score, status)
stop_results: dict = {}
correction_log: list = []  # (original, corrected, score) — only when original != corrected

total_exact    = 0
total_auto     = 0
total_uncertain = 0
total_unmatched = 0

print("  Running fuzzy matching …")
BATCH = 200
for i, stop in enumerate(all_stop_names_list):
    if i % BATCH == 0 and i > 0:
        print(f"    {i}/{len(all_stop_names_list)} processed …")

    stop_s = stop.strip()

    # ── Fast path 1: exact match ───────────────────────────────────────────
    if stop_s in site_names_set:
        stop_results[stop] = (stop_s, 100, 'exact')
        total_exact += 1
        continue

    # ── Fast path 2: case-insensitive exact ──────────────────────────────
    canonical_case = site_names_lower.get(stop_s.lower())
    if canonical_case:
        stop_results[stop] = (canonical_case, 100, 'exact')
        total_exact += 1
        continue

    # ── Pre-process stop before fuzzy match ──────────────────────────────
    cleaned_stop = basic_clean(stop_s)
    cleaned_stop = apply_explicit(cleaned_stop)
    if cleaned_stop in vw_map:
        cleaned_stop = vw_map[cleaned_stop]

    # Re-check after pre-processing
    if cleaned_stop in site_names_set:
        stop_results[stop] = (cleaned_stop, 95, 'auto_corrected')
        total_auto += 1
        if cleaned_stop != stop_s:
            correction_log.append((stop_s, cleaned_stop, 95))
        continue

    canonical_case = site_names_lower.get(cleaned_stop.lower())
    if canonical_case:
        score = 100 if canonical_case == cleaned_stop else 95
        status = 'exact' if score == 100 else 'auto_corrected'
        stop_results[stop] = (canonical_case, score, status)
        if status == 'auto_corrected':
            total_auto += 1
            if canonical_case != stop_s:
                correction_log.append((stop_s, canonical_case, score))
        else:
            total_exact += 1
        continue

    # ── Fuzzy match ────────────────────────────────────────────────────────
    result = fuzz_process.extractOne(
        cleaned_stop,
        site_names_list,
        scorer=fuzz.token_sort_ratio,
        score_cutoff=40
    )

    if result is None:
        stop_results[stop] = ('', 0, 'unmatched')
        total_unmatched += 1
    else:
        best_match, score = result[0], result[1]
        if score >= 90:
            stop_results[stop] = (best_match, score, 'auto_corrected')
            total_auto += 1
            if best_match != stop_s:
                correction_log.append((stop_s, best_match, score))
        elif score >= 60:
            stop_results[stop] = (best_match, score, 'uncertain')
            total_uncertain += 1
        else:
            stop_results[stop] = (best_match or '', score, 'unmatched')
            total_unmatched += 1

print(f"  Done — Exact: {total_exact}, Auto-corrected: {total_auto}, "
      f"Uncertain: {total_uncertain}, Unmatched: {total_unmatched}")

# ─────────────────────────────────────────────────────────────────────────────
# Step 6 — Apply corrections to all four stop columns in routes_df
# ─────────────────────────────────────────────────────────────────────────────
print("\nApplying corrections to routes …")

def corrected_value(val: str) -> str:
    s = str(val).strip()
    if not s or s.lower() == 'nan':
        return val
    if s in stop_results:
        best, score, status = stop_results[s]
        if status in ('auto_corrected', 'exact') and best:
            return best
    return val

# Save Bstop original before correction
routes_df['original_bstop_name'] = routes_df['Bstop Name'].apply(
    lambda x: str(x).strip() if str(x).strip().lower() != 'nan' else '')

# Correct all four columns
for col in STOP_COLS:
    if col in routes_df.columns:
        routes_df[col] = routes_df[col].apply(corrected_value)

# Populate Bstop tracking columns using the original values
def bstop_row_info(orig: str):
    s = orig.strip()
    if not s or s.lower() == 'nan':
        return ('', 0, 'unmatched')
    if s in stop_results:
        best, score, status = stop_results[s]
        corrected = best if (status in ('auto_corrected', 'exact') and best) else s
        return (corrected, score, status)
    return (s, 0, 'unmatched')

bstop_info = routes_df['original_bstop_name'].apply(
    lambda x: pd.Series(bstop_row_info(x),
                         index=['corrected_bstop_name', 'match_confidence', 'match_status'])
)
routes_df['corrected_bstop_name'] = bstop_info['corrected_bstop_name']
routes_df['match_confidence']     = bstop_info['match_confidence']
routes_df['match_status']         = bstop_info['match_status']

# ─────────────────────────────────────────────────────────────────────────────
# Step 7 — Build report dataframes (uncertain / unmatched)
# ─────────────────────────────────────────────────────────────────────────────
print("Building report dataframes …")

# Re-read original CSV to get per-stop route context
routes_orig = pd.read_csv(ROUTES_FILE, encoding='latin1', low_memory=False, dtype=str)
routes_orig = routes_orig.fillna('')

# Build: (original_stop, column) → first (Route No, Route Name) encountered
stop_col_ctx: dict = {}
for col in STOP_COLS:
    if col not in routes_orig.columns:
        continue
    for _, row in routes_orig.iterrows():
        val = str(row.get(col, '')).strip()
        if not val or val.lower() == 'nan':
            continue
        key = (val, col)
        if key not in stop_col_ctx:
            stop_col_ctx[key] = {
                'route_no':   str(row.get('Route No', '')).strip(),
                'route_name': str(row.get('Route Name', '')).strip(),
            }

uncertain_rows = []
unmatched_rows = []

for stop, (best_match, score, status) in stop_results.items():
    for col in STOP_COLS:
        key = (stop, col)
        if key not in stop_col_ctx:
            continue
        ctx = stop_col_ctx[key]
        if status == 'uncertain':
            uncertain_rows.append({
                'original_stop_name': stop,
                'suggested_match':    best_match,
                'similarity_score':   score,
                'route_no':           ctx['route_no'],
                'route_name':         ctx['route_name'],
                'column_source':      col,
            })
        elif status == 'unmatched':
            unmatched_rows.append({
                'original_stop_name': stop,
                'best_guess':         best_match,
                'best_score':         score,
                'route_no':           ctx['route_no'],
                'route_name':         ctx['route_name'],
                'column_source':      col,
            })

uncertain_df  = pd.DataFrame(uncertain_rows)
unmatched_df  = pd.DataFrame(unmatched_rows)
duplicate_df  = sites_df[sites_df['is_duplicate']].copy()

print(f"  Uncertain match rows : {len(uncertain_df)}")
print(f"  Unmatched stop rows  : {len(unmatched_df)}")
print(f"  Duplicate site rows  : {len(duplicate_df)}")

# ─────────────────────────────────────────────────────────────────────────────
# Step 8 — Save outputs
# ─────────────────────────────────────────────────────────────────────────────
print("\nSaving output files …")

_EMPTY_UNCERTAIN = pd.DataFrame(columns=[
    'original_stop_name','suggested_match','similarity_score',
    'route_no','route_name','column_source'])
_EMPTY_UNMATCHED = pd.DataFrame(columns=[
    'original_stop_name','best_guess','best_score',
    'route_no','route_name','column_source'])

def save_xlsx(df, fname):
    path = os.path.join(OUT_DIR, fname)
    df.to_excel(path, index=False)
    print(f"  Saved: {path}  ({len(df)} rows)")

save_xlsx(sites_df,    'cleaned_new_sites.xlsx')
save_xlsx(routes_df,   'cleaned_routes.xlsx')
save_xlsx(uncertain_df if not uncertain_df.empty else _EMPTY_UNCERTAIN,
          'uncertain_matches.xlsx')
save_xlsx(unmatched_df if not unmatched_df.empty else _EMPTY_UNMATCHED,
          'unmatched_stops.xlsx')
save_xlsx(duplicate_df, 'duplicate_sites.xlsx')

# ─────────────────────────────────────────────────────────────────────────────
# Step 9 — Summary report
# ─────────────────────────────────────────────────────────────────────────────

# Top 10 auto-corrections (unique originals → corrected, sorted by score desc)
seen_corr: set = set()
unique_corrections = []
for orig, corrected, score in sorted(correction_log, key=lambda x: -x[2]):
    if orig not in seen_corr and orig != corrected:
        unique_corrections.append((orig, corrected, score))
        seen_corr.add(orig)
    if len(unique_corrections) >= 10:
        break

# Dirty-data patterns
patterns = []

changed_names = sites_df[sites_df['original_name'] != sites_df['name']]
if len(changed_names):
    patterns.append(f"  - {len(changed_names)} site names changed during name cleaning")

nbsp_count = sum(1 for n in sites_df['original_name']
                 if isinstance(n, str) and '\xa0' in n)
if nbsp_count:
    patterns.append(f"  - {nbsp_count} names contained non-breaking spaces (\\xa0)")

multi_space = sum(1 for n in sites_df['original_name']
                  if isinstance(n, str) and '  ' in n)
if multi_space:
    patterns.append(f"  - {multi_space} names had multiple consecutive spaces")

trailing_punct = sum(1 for n in sites_df['original_name']
                     if isinstance(n, str) and re.search(r'[.,;:]\s*$', n))
if trailing_punct:
    patterns.append(f"  - {trailing_punct} names had trailing punctuation (dot/comma/semicolon)")

vw_corrections = sum(1 for o, c, _ in correction_log
                     if ('w' in o.lower() and 'v' in c.lower())
                     or ('v' in o.lower() and 'w' in c.lower()))
if vw_corrections:
    patterns.append(f"  - {vw_corrections} stop names auto-corrected for v/w transliteration variant")

# Category inconsistencies in sites
cat_vals = sites_df['category_code'].value_counts()
near_dupes = [c for c in cat_vals.index
              if any(fuzz.ratio(c, d) > 80 and c != d
                     for d in cat_vals.index)]
if near_dupes:
    patterns.append(f"  - {len(near_dupes)} category_code values appear to be near-duplicates "
                    f"(e.g. 'Farmstay'/'Farm-stay'/'Farm Stay')")

null_stops_count = sum(1 for s, (_, _, st) in stop_results.items()
                       if not s.strip())
if null_stops_count:
    patterns.append(f"  - {null_stops_count} empty/null stop names in routes")

patterns.append(f"  - {len(vw_map)} v/w variant pairs detected and normalised in site names")

if not patterns:
    patterns.append("  (none detected)")

print()
print("=" * 50)
print("=== SANITIZATION SUMMARY ===")
print("=" * 50)
print()
print("Sites file:")
print(f"  - Total rows: {len(sites_df)}")
print(f"  - Duplicate rows found: {n_duplicates}")
print(f"  - Unique canonical sites: {n_canonical}")
print()
print("Routes file:")
print(f"  - Total rows: {len(routes_df)}")
print(f"  - Unique stop names processed: {len(all_stop_names_list)}")
print(f"  - Auto-corrected (>=90): {total_auto}")
print(f"  - Uncertain (60-89): {total_uncertain}")
print(f"  - Unmatched (<60): {total_unmatched}")
print(f"  - Already exact match: {total_exact}")
print()
print("Top 10 auto-corrections made (original → corrected):")
if unique_corrections:
    for orig, corrected, score in unique_corrections:
        print(f"  {orig!r:42s} → {corrected!r}  (score={score})")
else:
    print("  (none — all matches were exact or no corrections differed)")
print()
print("Patterns noticed in dirty data:")
for p in patterns:
    print(p)
print()
print("=" * 50)
print(f"Output files saved to: {OUT_DIR}")
print("=" * 50)
