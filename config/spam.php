<?php

/*
|--------------------------------------------------------------------------
| Spam / bot filtering
|--------------------------------------------------------------------------
|
| Lightweight, dependency-free defenses for public submission endpoints
| (currently the query/enquiry form via ContactController@addQuery). All
| values are env-overridable so you can tune blocklists without a deploy.
|
*/

return [
    // A hidden form field that real users never see or fill in. Front-ends add
    // an off-screen <input> with this name; if a submission arrives with it
    // non-empty, it's a bot and is silently dropped. Legit API clients simply
    // omit it. Rename via env if bots start targeting the field name.
    'honeypot_field' => env('SPAM_HONEYPOT_FIELD', 'website'),

    // Hard-blocked phone numbers (repeat spammers). Comma-separated in env,
    // e.g. SPAM_BLOCKED_PHONES="9999999999,8888888888". Compared digits-only.
    'blocked_phones' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SPAM_BLOCKED_PHONES', ''))
    ))),

    // Hard-blocked email domains (disposable / spam senders). Comma-separated.
    'blocked_email_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SPAM_BLOCKED_EMAIL_DOMAINS', 'jmailservice.com'))
    ))),

    // If a single phone number is already attached to this many DISTINCT names
    // in the contacts table, treat further submissions from it as spam. Set to
    // 0 to disable this check.
    'max_names_per_phone' => (int) env('SPAM_MAX_NAMES_PER_PHONE', 4),
];
