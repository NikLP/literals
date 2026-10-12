# Literals: base

Installs `literals`, creates the starter literal types (Text, Phone, Path,
Token, Content) and grants the view permissions to the built-in roles:

| Role | Permissions | Sees |
| --- | --- | --- |
| Anonymous | `view literals` | literals not marked restricted |
| Authenticated | `view literals`, `view restricted literals` | every literal |

Nothing grants these on a plain `drush en literals`, which is
why this recipe exists. For a staff-only tier instead, grant `view
restricted literals` to a staff role rather than to Authenticated.

Content ships with no bundles ticked, so it holds nothing until you tick some
on its edit screen. The module itself ships no types; `literals_base` is
where a site gets these defaults, and any of them can be recreated or removed
in the UI.

```bash
ddev drush recipe modules/custom/literals/recipes/literals_base
```
