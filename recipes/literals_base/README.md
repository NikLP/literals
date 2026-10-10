# Literals: base

Installs `literals` (with its shipped literal types) and grants the view
permissions to the built-in roles:

| Role | Permissions | Sees |
| --- | --- | --- |
| Anonymous | `view literals` | literals not marked restricted |
| Authenticated | `view literals`, `view restricted literals` | every literal |

Nothing grants these on a plain `drush en literals` (fail closed), which is
why this recipe exists. For a staff-only tier instead, grant `view
restricted literals` to a staff role rather than to Authenticated.

```bash
ddev drush recipe modules/custom/literals/recipes/literals_base
```
