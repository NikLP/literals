# Handoff: the `literals` module (2026-10-05, updated after the Phase 1 rebuild)

Read [ADR-0040](../aim/adr/0040-literals-probabilistic-lookup-of-exact-values.md)
first, especially **Addendums 4 to 7** (later addenda win over pieces
1 to 12). **Addendum 7 is the current model** (types, kinds, audience);
the older text still says pools and `[literal:pool:key]`. The build plan is Addendum 5 ("Build plan", Phases 0 to 5); the
evals are in [TESTS.md](../aim/TESTS.md) ("Literals").

## Decisions already made (do not reopen)

- Standalone module `literals`, sibling of `aim` at `web/modules/custom/literals/`. No `aim_scope_literal`, no mirror of gists into `aim_fact`.
- `entity` scope stays (annotative facts about entities, ADR-0024). A literal is not a fact.
- The gist lives on the literal row. aim integration is a finder consumer: `aim_recall` calls `LiteralFinder` if the service exists (progressive enhancement), nothing stored in aim. An aim-stored-gist backend is a parked option.
- Finder (whole lookup) vs chooser (the model call) vs gate (cheap pre-check). Gate default: gist embedding stored on the literal row (+ model ID), cosine compare in PHP. No vector DB or Search API needed for small pools.
- Chooser = Decision API `ChoiceQuestion` over key + gist only. Values never go to the model. Hosted Jev, demo data only.
- Base `literals` needs no AI. The finder (embeddings, chooser) is the optional, `drupal/ai`-dependent part: submodule or optional service.
- Pinned: moderation, aliases (field removed from the spike), pool sets, semantic cache, vector tier, staged choice.
- ADR-0039 is superseded by 0040.

The progressive-enhancement ladder (no model, full menu, gate, vector index; what each costs and when to move up) is in [adr/progressive-enhancement.md](adr/progressive-enhancement.md).

## Current state (Phase 1 built, live on the DDEV site)

- **Model.** `literal` content entity (revisionable, published status, owner, created, changed; show_revision_ui, Gin sidebar layout via `hook_gin_content_form_routes`). Bundle = `literal_type` config entity (fieldable, `field_ui_base_route`). Fields: `name`, `key` (machine name generated from the name, unique across all literals), `value`, `gist` (short text, 255), `audience`.
- **Types and kinds.** A type picks a `LiteralKind` plugin (`text`, `token`, `entity`, `url`) and carries its settings (text: `validate_as` string/int/phone/email/url). Plugins validate on save (`LiteralValue` constraint) and `resolve($account)` at read time; `$literal->resolve()` is the single read call. `entity` stores `type:id` and resolves to its URL after a view-access check; `url` is **internal paths only** (starts with `/`, access checked; external URLs deliberately out until there is a reason, then an "allow external" type setting); `token` needs at least one known token.
- **Access.** One `audience` column: `anonymous` (everyone), `authenticated`, `restricted` (needs the `view restricted literals` permission). `LiteralAudience::visibleTo($account)` gives the matching set, used by the access handler and by a `hook_query_alter` on entity queries and Views, so lists never include a literal the viewer cannot open. Flat permissions: `administer literals`, `view restricted literals`, `create literals`, `edit literals`, `delete literals`. Pools no longer exist. Default audience is `authenticated` (fail closed).
- **UI.** Content > Literals is a view (`views.view.literals`) with exposed "Visible to" and Type filters, plus a `page_type` display at `admin/content/literals/type/%` (path kept for later). Structure > Literal types is the type UI. Value widget by kind: URL gets a content title autocomplete (stores `/node/N`); token gets the Token module's browser if that module is installed (it is not on this site yet: `composer require drupal/token`).
- **Seed data on this site.** Types `text`, `phone`, `url`, `token`, `entity`; five throwaway literals. Config is exported to `config/sync` and the view is copied to `config/install` (no `uuid`/`_core`). `literals.info.yml` lists the view under `config_devel`.

## Phase 3 state (`modules/literals_finder`, needs `drupal/ai`; enabled on this site)

- Services: `literals_finder.finder` (`LiteralFinderInterface::find($question, $account)` returns a `LiteralFindResult`: `match`/`ambiguous`/`none`, literals not values, plus tier and reason), `.chooser` (Decision API `ChoiceQuestion` over key + gist, `__none__` option, default `decision` provider from `ai.settings`), `.embedder` (default `embeddings` provider, cosine in PHP). `logger.channel.literals` lives in the base module.
- Flow: candidates (published, audience filter in the query *and* `access('view')` per entity) > outcome cache (`cache.default`, key includes the audience set, tag `literal_list`, `none` cached `miss_ttl`s, errors never cached) > gate if `gate_enabled` > chooser. Gate: embed the question, cosine vs stored `gist_vector` (+ `gist_vector_model`, base fields added by the submodule); below `gate_min_similarity` is `none`; lead of `gate_margin` is `match` with no chooser call; else top `gate_top` plus any literal lacking a current vector (queued to `literals_embed`) go to the chooser. No decision model: the shortlist decides alone (`margin` tier); no embeddings either: `none` / `no_backend`. An embedding outage degrades to the full menu; a chooser failure returns `none` / `error` with a warning.
- Chooser rule: argmax `none` is `none`; lead over the best *other literal* under `choice_margin` is `ambiguous`; below `match_threshold` is `none` (`low_confidence`).
- Settings: `literals_finder.settings` (`gate_enabled` is **off** on this site: Ollama is stopped). **All thresholds are untuned placeholders** (Phase 4). Observed with hosted Jev, 5 literals: "who is the admin", "where do I sign in" match; "what is your phone number" chose none against "The library main phone number" (the evals should catch this kind of miss).
- Drush: `literals:find "question" --uid=N` (prints outcome, tier, keys, never values), `literals:embed`.
- Verified live: chooser path (anonymous cannot match the `authenticated` literal), error paths, gate wiring with a fake embedder. **Not verified against real embeddings** (Ollama down); `literals:embed` and the presave hook need a run with Ollama up.
- Lint: phpcs clean on `modules/`; phpstan shows the same `ProviderProxy::decision()/embeddings()` "undefined method" noise as aim (needs an ignoreErrors rule in a literals phpstan.neon).

## Decisions made this session (do not reopen)

- Bundle is the **type** (config entity, kind plugin), not the pool. A literal holds one value; kinds replace per-literal "validate as".
- Access is data on the row (`audience`), not per-pool permissions. A scope-plugin interface (`checkViewAccess()` plus a query-condition method, aligned with aim's scope types) is the next step only when a second scope is needed; per-type "restricted" permissions are the likely first extension.
- Token form is `[literal:key]` (keys are global), not `[literal:pool:key]` as the ADR text says.

## Not done, in order

1. Phase 2 is next (start here): tokens `[literal:key]` (access check, cache metadata, published revision only), Tool API `literal_get` by key, Guardrails at save (gist and value), audit logging through a literals logger channel.
2. ~~Phase 3~~ BUILT 2026-10-05 as submodule `literals_finder` (see "Phase 3 state" below). Still open from it: the `literal_get` by-question mode (belongs with item 1's tool), tuning of the placeholder thresholds (item 3).
3. Phase 4 (separate thread): evals in TESTS.md.
4. Phase 5: aim consumer in `aim_recall`; convert-a-fact; audit line when the finder errors.
5. Move the literals-only ADRs (0040, superseded 0039, the two 0046 gist ADRs) into `adr/` here. Blocked on numbering: two files share 0046 and one is a duplicate from annopm. Not urgent.
6. Optional polish: entity-kind autocomplete picker (value is a plain `type:id` text today); inject the two `\Drupal::` calls in `LiteralForm` (phpcs warnings); per-type restricted permissions; "allow external URLs" type setting; `drupal.org` name clash check for `literals`.

## Gotchas and housekeeping

- **Stray `token` view modes.** 14 `core.entity_view_mode.*.token` configs appeared in the DB from an unknown source (not this module: deleted, then token calls, form, view and saves did not recreate them). Gone now; if they reappear in `drush config:status`, find the cause before exporting them.
- `literals.module` only holds the `literals_audience_options()` allowed-values callback.
- **Changing the entity definition on this site.** There is no migration story yet. Field changes went through `hook_update_N` (see `literals.install`); a bundle-key change needed `drush pmu literals` and re-enable (remove the view from `config/install` first, then rebuild the view from the exported YAML). Uninstalling a field that targets a removed entity type fails: keep the old class until the update runs.
- **Never hand-type `dependencies` or `cache_metadata`.** Save through the API, export, copy to `config/install` minus `uuid`/`_core`.
- **config_devel is installed (--dev).** `drush config:devel-export literals` (`cde`); entity IDs under `config_devel: install:` in `literals.info.yml`. Config schema checks: validate with `\Drupal::service('config.typed')->createFromNameAndData($name, $data)->validate()` (`config:inspect` is not available).
- **Drush scripts** run as anonymous: set `\Drupal::currentUser()->setAccount(User::load(1))` first. `drush php:script` only sees files inside the project (the container cannot read `/tmp`); put scratch scripts in the site root with absolute `/var/www/html/...` paths and delete them. Do not name a function `t()`.
- Core's validator context takes typed data only; use core constraint plugins via the `validation.constraint` manager, not raw Symfony constraints.
- `key` is an SQL reserved word as a column name; it works, but watch it in raw queries.
- **Config sync parity:** keep `config/sync` matching the DB (`drush config:status`, then `config:export -y`) or a stale `drush cim` uninstalls the module. Snapshot `pre-literals-spike` is from before the module.
- Project rules (CLAUDE.md): no em dashes, no auto-commit, American English, run phpcs (`ddev exec "cd /var/www/html && vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/literals --extensions=php,module,inc,install,yml"`) and phpstan before calling PHP work done.
