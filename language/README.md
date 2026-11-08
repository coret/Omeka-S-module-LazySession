# Translations

Shipped catalogues:

| File | Locale | Notes |
|---|---|---|
| `template.pot` | | Extracted source strings (2). |
| `en.po` / `en.mo` | `en` | Identity catalogue. English is the source language, but an explicit `en` locale then resolves instead of falling through to the raw msgid. |
| `nl_NL.po` / `nl_NL.mo` | `nl_NL` | Dutch. Matches the filename Omeka core uses (`application/language/nl_NL.po`). |
| `nl.po` / `nl.mo` | `nl` | Same Dutch content, so a plain `nl` locale resolves too. |

The module has no user interface. The only strings are the name and description
from `config/module.ini`, which Omeka runs through the translator in the module
list.

## Regenerating after changing a string

1. Change the `msgid` in `template.pot` (and `config/module.ini`).
2. Update the matching entries in `en.po` (identity) and `nl_NL.po`.
3. Copy `nl_NL.po` to `nl.po`, setting its `Language:` header to `nl`, and recompile:

```sh
cd modules/LazySession/language
sed 's/^"Language: nl_NL\\n"$/"Language: nl\\n"/' nl_NL.po > nl.po
for po in en nl_NL nl; do msgfmt --check -o "$po.mo" "$po.po"; done
```
