# Literals: base

Installs `literals` (with its shipped literal types and the three
audiences) and grants the audiences to the built-in roles:

| Role | Audiences it can see |
| --- | --- |
| Anonymous | `anonymous` |
| Authenticated | `anonymous`, `authenticated` |

The `restricted` audience is granted to nobody; give a role
"View Restricted literals" on the permissions page when you want one.
Nothing grants these on a plain `drush en literals` (fail closed), which is
why this recipe exists.

```bash
ddev exec vendor/bin/dr recipe:apply web/modules/custom/literals/recipes/literals_base
```
