# Literals: demo library

Applies [literals_base](../literals_base/README.md) and imports eight
literals as default content (`content/literal/`): the main, reservations,
accounts and staff phones, the login, password reset and my-account links,
and the site name. Their gists are the ones the finder evaluation uses,
including the `ALSO` phrases (see `adr/how-it-works.md`).

Re-applying skips literals that already exist (matched by UUID), so a
value edited on the site is not overwritten.

```bash
ddev exec vendor/bin/dr recipe:apply web/modules/custom/literals/recipes/literals_demo_library
```

Phone numbers are fictional. The `url` literals point at core paths
(`/user/login`, `/user/password`, `/user`), so they resolve on any site.
