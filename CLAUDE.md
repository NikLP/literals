# CLAUDE.md - literals

Exact values (phone numbers, URLs, names) kept as governed `literal`
entities, found from a description. Standalone module, sibling of `aim` at
`web/modules/custom/literals/`; `aim` is meant to consume it (as a finder
consumer), not contain it. Status: working PoC, live on this DDEV site.

See [README.md](README.md) for the pitch, [DEVELOPING.md](DEVELOPING.md)
for commands, API and runbooks, and [adr/](adr/) for decisions: start with
[ADR-0040](adr/0040-literals-probabilistic-lookup-of-exact-values.md) (current
state), then [ADR-0052](adr/0052-what-literals-is-for.md) (what it is for,
how `aim` uses it, the answer modes) and [adr/how-it-works.md](adr/how-it-works.md)
(measurements). The demo plan is [ADR-0054](adr/0054-demo-the-scottish-play.md).
Backlog items live in aim's [TODO.md](../aim/TODO.md) ("Literals").

The project-wide rules in the site CLAUDE.md and aim's CLAUDE.md apply
here: no em dashes, American English, no banner comments, **never
auto-commit**, run phpcs/phpstan before calling PHP work done.

## Modules

| Module | Needs | Provides |
| --- | --- | --- |
| `literals` | `user`, `views`; no AI | entity, types, resolvers, view access, reader, `[literal:key]` and `[literal:key:link]` tokens, Guardrails constraint, plain search service, `literals.lookup` service, list UI |
| `literals_finder` | `drupal/ai` | finder, chooser (Decision API), outcome cache, Guardrails runner, `drush literals:find`/`literals:eval` |
| `literals_tool` | `tool` | `literals:lookup` Tool API / MCP tool, a thin wrapper over `literals.lookup`; for sites without aim (aim sites use `aim_tool`'s `aim_literal`, the same service) |
| `literals_chat` | finder | chat responder and chat processor; a demo surface, likely to be scrapped |

## Decisions in force (do not reopen without a reason)

- The gist is only worth writing carefully when `literals_finder` is enabled
  (it is the finder's whole input). Without the finder a literal needs a
  clear name and key; plain search also reads the gist. `literals_finder`
  is optional and stands alone as a fuzzy value lookup.
- A literal is not a fact: no `aim_scope_literal`, no mirror of gists into
  `aim_fact`. The gist lives on the literal row; `aim` consumes the finder.
- The model never sees a value. The chooser sees key + gist only; the value
  is resolved after the choice, access-checked for the asking account.
  A literal's value is only ever checked by deterministic Guardrails.
- Base `literals` needs no AI. The finder is the optional `drupal/ai` part.
- Access is four flat permissions and one boolean column, `restricted`
  (`LiteralVisibility`): `view literals` sees unrestricted literals, `view
  restricted literals` sees all, `edit literals` creates, edits and deletes
  any literal and sees drafts and restricted ones, `administer literals`
  adds types and settings. The `literal_audience` config entities were
  removed 2026-10-10 as more machinery than one realistic split needed.
  Enforced by the access handler and a `hook_query_alter`; a hidden literal
  is indistinguishable from a missing one ("none"). The resolver's own
  check (e.g. a `url` route) is a second, separate layer. The two can
  disagree, and that is accepted, not fixed: core's account routes
  change access by login state (`/user/login` is anonymous-only, `/user`
  signed-in only), so an unrestricted `login` literal resolves to "none"
  for a signed-in user. Mark such a literal restricted when its route
  needs a signed-in user; otherwise live with the "none".
- The bundle is the **type** (config entity + resolver plugin), not a pool.
  Access is the separate `restricted` flag.
- Embedding gate, alias field, vector tier: built or considered and
  removed; do not rebuild without a measured need (ADR-0040 "Rejected").
- Tokens in body copy via a text-format filter are dropped (cache-context
  leak risk); tokens are for callers that control their render.
- The `field` resolver (`entity_type:id:field_name`) needs the literal's
  view rule **and** view access to the entity **and** to the field: the
  intersection, the stricter of the two access models. The source module
  keeps owning the value.
- Every edit makes a revision (`LiteralType::shouldCreateNewRevision()`
  returns TRUE), so history and Content Moderation drafts work; tested in
  `LiteralModerationTest`. Core's revision UI (Revisions tab, revert,
  delete revision, as block_content does) is gated on `edit literals`.
- External URLs are not wanted: the `url` resolver stays internal paths
  only, and the text resolver has no `url` validation option.
- **The key is the entity ID**, a string machine name (`[a-z0-9_]`, max
  64), as core's Workspace entity does: `[literal:main_phone]` is
  `Literal::load('main_phone')`, and admin URLs use it. No serial ID. Set
  by a form-level `machine_name` element in `LiteralForm` (not a widget,
  so `flagViolations()` reports its errors). Uniqueness is core's
  `UniqueField` plus the primary key.
- **Keys are immutable** after create (form element disabled, and the
  `LiteralKey` constraint refuses a change): facts embed `[literal:key]`, so
  a rename would silently redact them.

## Gotchas

- **Config sync parity.** Keep `config/sync` matching the DB
  (`ddev drush config:status`, then `config:export -y`), or a stale
  `drush cim` uninstalls the module. Snapshot:
  `pre-audience-removal` (the older ones went with the 2026-10-09 wipe).
- **Never hand-type `dependencies` or `cache_metadata`** in config: save
  through the API, export, copy to `config/install` minus `uuid`/`_core`.
- **No update hooks (PoC).** `literals.install` was removed 2026-10-09:
  nothing real depends on this site's data, so a schema or bundle change
  means `drush pmu literals` and re-enable (remove the view from
  `config/install` first, rebuild it from the exported YAML). Uninstalling a
  module needs its files present; fold or move code only after `drush pmu`.
  Uninstalling needs the literals deleted first (`drush entity:delete
  literal -y`); the `literals_demo_library` recipe re-seeds them.
  Nothing grants the view permissions on install: a fresh site shows
  literals to admins only until roles are granted `view literals` (the
  `literals_base` recipe does).
- **The admin list is for editors**: its View uses the `literal_editor`
  access plugin (`edit literals` or `administer literals`), so
  `administer literals` covers the list as it covers every literal
  operation in the access handler. No separate overview permission.
  Editors see every literal; the query alter still filters other literal
  queries and any other View of literals.
- **`/admin/config/literals` 403s if it has no visible child** (core's
  admin-block access check); the base Settings form keeps one there.
- **Stray `token` view modes.** 14 `core.entity_view_mode.*.token` configs
  once appeared from an unknown source; if they reappear in
  `config:status`, find the cause before exporting them.
- **Core's access handler memoizes per entity per request**: a test that
  edits then re-reads in one process must `resetCache()` it.
- **A programmatic writer must call `validate()` before `save()`**: the
  Guardrails run at entity validation (same as aim).
- **Keys that equal a literal field name or entity token** (`url`, `name`,
  `value`, ...) are refused: the Token module adds entity tokens under the
  same `literal` namespace.
- **The finder's cache fingerprint does not include the decision model ID.**
  Clear the cache when swapping models.
- **Eval runs as anonymous by default** (`as: 0`); a restricted
  literal (for example `site_name`) needs `as: 1` on its
  gold queries or it correctly answers none.
- `config_devel` is installed (dev): `drush config:devel-export literals`.
  Validate config schema with
  `\Drupal::service('config.typed')->createFromNameAndData($name, $data)->validate()`
  (`config:inspect` is not available).
- The two `ai.ai_guardrail*` configs raise a schema warning about
  `check_all_messages` (an upstream schema gap, same as aim's copy).

## Lint and tests

```bash
ddev exec "cd /var/www/html && vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/literals --extensions=php,module,inc,install,yml"
ddev exec "cd /var/www/html && SIMPLETEST_DB='sqlite://localhost/sites/default/files/literals-test.sqlite' SIMPLETEST_BASE_URL=http://localhost vendor/bin/phpunit -c web/core web/modules/custom/literals/tests"
```

About 3 minutes for the base suite (unit, kernel and functional, separate
processes); run a submodule by pointing at its `tests` directory, and run
base and submodules as two commands (one combined run was OOM-killed on
this laptop). Functional tests work with `SIMPLETEST_BASE_URL=http://localhost`
inside the web container. phpstan (pass `-c
web/modules/custom/literals/phpstan.neon`) is clean. The real chooser is covered only by
`drush literals:eval`, not PHPUnit.
