# Literals: base

Installs `literals` and grants the view permissions to the built-in roles:

| Role | Permissions | Sees |
| --- | --- | --- |
| Anonymous | `view literals` | literals not marked restricted |
| Authenticated | `view literals`, `view restricted literals` | every literal |

Nothing grants these on a plain `drush en literals`, which is
why this recipe exists. For a staff-only tier instead, grant `view
restricted literals` to a staff role rather than to Authenticated.

The literal types (Text, Path, Token, Content, Entity, Field) ship with the
module itself, not this recipe. Content has no bundles ticked, so it holds
nothing until you tick some on its edit screen.

```bash
ddev drush recipe modules/custom/literals/recipes/literals_base
```
