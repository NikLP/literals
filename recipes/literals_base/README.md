# Literals: base

Installs `literals` and grants the view permissions to the built-in roles:

| Role | Permissions | Sees |
| --- | --- | --- |
| Anonymous | `view literals` | literals not marked restricted |
| Authenticated | `view literals` | literals not marked restricted |

Nothing grants these on a plain `drush en literals`, which is
why this recipe exists. Restricted literals are seen by no built-in role: grant
`view restricted literals` to a staff role (or whichever role should see
them) after applying the recipe. Granting it to Authenticated would make
"restricted" mean nothing for any signed-in visitor.

The literal types (Text, Path, Token, Content, Entity, Field) ship with the
module itself, not this recipe. Content has no bundles ticked, so it holds
nothing until you tick some on its edit screen.

```bash
ddev drush recipe /var/www/html/web/modules/custom/literals/recipes/literals_base
```

Adjust the path to where the module sits (`/var/www/html/web/modules/contrib/literals/...` if
installed through Composer).
