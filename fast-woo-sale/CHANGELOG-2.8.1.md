# 2.8.1 — Bug-fix release (2026-09-19)

Fixes for the 16 issues from the verified bug report (docs/fa/gozaresh-bughaye-v2.8-va-raf.md). No new features.

| ID | Severity | Fix |
|----|----------|-----|
| BUG-01 | Critical | Settings cards wrapped in `<form action="options.php">` + `settings_fields()`; save button now works |
| BUG-02 | Critical | `wc_reduce_stock_levels()` after upsell when order stock was already reduced |
| BUG-03 | Critical | Upsell refused on orders already paid via online gateway; unpaid orders redirected to pay URL; offline gateways (cod/bacs/cheque, filter `fws_upsell_offline_gateways`) allowed |
| BUG-04 | High | Bundle discount session uses the ids actually added to the cart |
| BUG-05 | High | `FWS_Prediction_Engine::is_recommendable()` excludes variable/grouped/external (filter `fws_non_addable_product_types`) |
| BUG-06 | High | Search cache keyed on matched product-id set, term capped at 60 chars, 1h TTL, empty results not persisted |
| BUG-07 | High | Mining lock released via `register_shutdown_function` if the run dies |
| BUG-08 | High | Search injection only when `post_type` is explicitly `product` |
| BUG-09 | Medium | `recs_limit` used for cart widget and free-shipping fillers; `min_confidence` applied to fillers |
| BUG-10 | Medium | `fws_refresh_nonce` endpoint + JS `fwsPost()` wrapper retries once with a fresh nonce |
| BUG-11 | Medium | `user_rec_{id}` purged on `woocommerce_new_order` / processing / completed |
| BUG-12 | Medium | uninstall clears `fws_weekly_db_cleanup_event`, drops dead option delete, comment corrected |
| BUG-13 | Medium | Rate limiter: atomic `wp_cache_add/incr` with object cache; bucketed transient fallback |
| BUG-14 | Low | admin `api()` returns the jqXHR |
| BUG-15 | Low | `abs(crc32())` |
| BUG-16 | Low | `FWS_Settings` in-request memo, flushed on option writes |
