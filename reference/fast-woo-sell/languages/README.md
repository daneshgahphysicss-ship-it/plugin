Translation files live here.

- `fast-woo-sell.pot` is generated with `wp i18n make-pot . languages/fast-woo-sell.pot`
- `fast-woo-sell-fa_IR.po` / `.mo` hold the Persian translation
- The plugin header declares `Domain Path: /languages`, so WordPress loads
  `fast-woo-sell-{locale}.mo` from this directory.

Source strings are written in Persian. The `.pot` file is still generated so the
plugin can be translated into other languages, and so that every user-facing
string is provably wrapped in a translation function.
