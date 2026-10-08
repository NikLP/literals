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
| `literals` | `user`, `views`; no AI | entity, types, resolvers, audience access, reader, `[literal:key]` and `[literal:key:link]` tokens, Guardrails constraint, plain search service, list UI |
| `literals_finder` | `drupal/ai` | finder, chooser (Decision API), outcome cache, Guardrails runner, `drush literals:find`/`literals:eval` |
| `literals_tool` | `tool` | `literals:lookup` Tool API / MCP tool (key, question, search) |
| `literals_chat` | finder | chat responder and chat processor; a demo surface, likely to be scrapped |

## Decisions in force (do not reopen without a reason)

- A literal is not a fact: no `aim_scope_literal`, no mirror of gists into
  `aim_fact`. The gist lives on the literal row; `aim` consumes the finder.
- The model never sees a value. The chooser sees key + gist only; the value
  is resolved after the choice, access-checked for the asking account.
  A literal's value is only ever checked by deterministic Guardrails.
- Base `literals` needs no AI. The finder is the optional `drupal/ai` part.
- Access is the `audience` column (`anonymous`, `authenticated`,
  `restricted`), default `authenticated` (fail closed), enforced by the
  access handler and a `hook_query_alter`; a hidden literal is
  indistinguishable from a missing one ("none").
- The bundle is the **type** (config entity + resolver plugin), not a pool.
- Embedding gate, alias field, vector tier: built or considered and
  removed; do not rebuild without a measured need (ADR-0040 "Rejected").
- Tokens in body copy via a text-format filter are dropped (cache-context
  leak risk); tokens are for callers that control their render.
- External URLs are not wanted: the `url` resolver stays internal paths only.

## Gotchas

- **Config sync parity.** Keep `config/sync` matching the DB
  (`ddev drush config:status`, then `config:export -y`), or a stale
  `drush cim` uninstalls the module. Snapshots: `pre-literals-spike`,
  `pre-gate-removal`, `pre-search-fold`.
- **Never hand-type `dependencies` or `cache_metadata`** in config: save
  through the API, export, copy to `config/install` minus `uuid`/`_core`.
- **No migration story for entity definition changes.** Field changes go
  through `hook_update_N` (`literals.install`); a bundle-key change needed
  `drush pmu literals` and re-enable (remove the view from `config/install`
  first, rebuild it from the exported YAML). Uninstalling a field that
  targets a removed entity type fails: keep the old class until the update
  runs. Uninstalling a module needs its files present; fold or move code
  only after `drush pmu`.
- **`key` is an SQL reserved word** as a column name. It works; watch raw
  queries.
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
- **Eval runs as anonymous by default** (`as: 0`); a literal with
  `authenticated` audience (for example `site_name`) needs `as: 1` on its
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

About 3 minutes for the base suite (separate processes); run a submodule
by pointing at its `tests` directory. phpstan shows only `\Drupal::`
service-location warnings. The real chooser is covered only by
`drush literals:eval`, not PHPUnit.
