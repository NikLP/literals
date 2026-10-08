# ADR-0040: Literals - exact values with a fuzzy address, found by a Jev choice

**Status:** Accepted (settled by the Phase 1 to 3 builds); built through the finder, tokens, tool and
search (see "What is built"). Refactored 2026-10-07 from a draft with nine
addenda into the current state; the superseded designs (a field type, an
Annotations host, a `config_pages` host, pool bundles, an `aim_fact` mirror,
an embedding gate, an alias field) are summarized under "Rejected" and the
full text is in the `aim` repo history (`adr/0040-...` at commit 502f799).
Supersedes [ADR-0039](0039-token-scope-live-config-values.md).
**Date:** 2026-10-03

Related: [ADR-0047](0047-literal-candidates-and-tracked-gists.md)
(candidates, tracked gists), [ADR-0048](0048-token-literal-access-and-entity-targets.md)
(token access, entity targets), [ADR-0052](0052-what-literals-is-for.md)
(what it is for), [ADR-0053](../../aim/adr/0053-gist-shared-vocabulary.md)
(the shared "gist" term). Current state and runbooks:
[CLAUDE.md](../CLAUDE.md) and [DEVELOPING.md](../DEVELOPING.md); how it works and its
measurements: [how-it-works.md](how-it-works.md); the model ladder:
[progressive-enhancement.md](progressive-enhancement.md).

## Context

A visitor asks the chat bot "what is the phone number?". The value exists in
exactly one right place and the agent has to find it. Today it can only call
`aim_recall`, which embeds the question and searches fact text. A number is a
poor target for an embedding: recall can miss it, return opening hours
instead, or return a stale copy that consolidation has merged or rewritten
([ADR-0020](../../aim/adr/0020-verbatim-facts-consolidation-opt-out.md)). A
missed recall is also invisible
([ADR-0035](../../aim/adr/0035-standing-constraints-action-gate.md)).

The idea: **the value is exact, the address is probabilistic.** A person or
agent describes what they want in words; the system works out which exact
value that means and returns it through an access-checked resolve. The value
is never embedded, paraphrased or consolidated.

Two facts shape everything below:

1. **The match comes from a typed decision model, not a vector score.** A
   decision model (Jev, [ADR-0021](../../aim/adr/0021-jev-typed-decision-provider.md))
   picks from a menu of options, or says "none" or "ambiguous". It cannot
   generate text, so it can choose among IDs it is given but never invent a
   value. Literals are a small, closed, human-vetted set, which is the easy
   case for a classifier.
2. **The semantic part is optional.** A literal is first a key and a value.
   Exact lookup by key (PHP, tokens, the tool) is a complete product with no
   AI at all; the finder is the optional layer on top.

**Against Annotations** ([ADR-0024](../../aim/adr/0024-annotations-integration-target-scoped-promotion.md)),
the same mapping read in opposite directions: Annotations describes a place
and asks what goes there (data **in**); literals describes a value and asks
where it lives (data **out**). Good annotation text makes good gists.

**Prior art.** Two shallow `drupal-code-query` searches and web searches
(2026-10-03) found no contrib module or CMS field type doing
semantic-description-to-exact-value lookup; treat "no existing module" as
unverified (`drupal_rag_toolkit`, `ai_rag_api`, `search_api_ai`, `daedalus`
turned up adjacent and were not opened). The nearest research is
attribute-level uncertainty in probabilistic databases (Orion), inverted
here: the value is exact and the uncertainty is in how it is addressed.

## Decision

### 1. A standalone `literal` content entity

`literals` is a module of its own, a sibling of `aim` under
`web/modules/custom/` (its own repo), and the name is Nik's decision. A
`literal` is a revisionable content entity with a published status, owner
and timestamps; the bundle is the **type** (section 3). Values are content,
not config: editing a phone number on production never needs a config import.
Only the types are config.

It is an entity and not a field type or an Annotations bundle because the
fields must live together in one place with revisions, access and a list UI:

- A `literal` **field** on any host gains nothing the host does not already
  provide, and a formatter owns neither data nor an index.
- **`config_pages` cannot host values:** no revisions, no Content Moderation,
  access per page type only, and its token handler does no view-access check.
- **Annotations as a host** (a `literal` annotation type) would reuse a heavy
  surface built for another concept. The entity, bundle, access handler and
  permissions are about 450 lines of boilerplate (the `AimFact`/`AimScope`
  pattern); the real cost is the editing UI, and a thin one is mostly free
  (core `ContentEntityForm`, Views, Field UI). The Phase 1 build went standalone
  and the UI stayed small. The Annotations bridge is optional (section 10).

### 2. Anatomy of a literal

- **Name:** the human label (the entity label).
- **Key:** a machine name generated from the name, editable, **unique across
  all literals**. Not semantic. It is the option ID handed to the Decision
  API, the exact-lookup handle, and the stable identifier in audit lines
  (which cannot log text). Keys equal to a literal field name or an entity
  token (`url`, `name`, `value`) are refused, because the Token module adds
  entity tokens under the same `literal` namespace.
- **Value:** the exact thing returned, interpreted by the type's resolver.
- **Gist:** short free text (255 characters) describing what the value is
  for. The only part the chooser ever sees beside the key. A gist answers a
  one-shot question, not a document: Jev's own guidance prefers concise
  descriptions, and small local models budget about 125 options in 512
  tokens. An author may put arbitrary house names after a plain-text `ALSO`
  ("Main telephone number, ALSO the Beacon Line"); measured 9/9 against 4/9
  without, with no field and no parser (see "Rejected").
- **Audience:** who may see it (section 4).
- No numeric confidence field. The judgement lives in the gist's wording.

### 3. Types and resolvers

The bundle is a `literal_type` config entity (fieldable, Field UI on its
edit form). A type names a **resolver** (`LiteralResolver` plugin: `text`,
`token`, `entity`, `url`) and carries its settings (text: `validate_as` of
any text, whole number, phone, email or URL). A site defines its own types
("Phone number": text, validated as phone; "Page link": url).

A literal holds exactly one value. The resolver validates it on save (a
`LiteralValue` constraint, so it applies to agents and Drush, not just form
submits) and `resolve($account)` reads it at read time. The finder, token
handler and tool all call one `$literal->resolve()` and never read `value`.
Rejected: a literal holding several fields of mixed kinds (the `config_pages`
model), because the finder would have to understand arbitrary field types.

- `entity` stores `entity_type:id` and resolves to the entity's URL after a
  view-access check.
- `url` is **internal paths only** (starts with a slash, access checked for
  the viewer): external URLs cannot be access checked. An "allow external"
  type setting waits for a reason.
- `token` must contain at least one known token; a cycle guard stops token
  literals looping.

### 4. Access: one `audience` column

Each literal has an `audience`: `anonymous` (everyone), `authenticated` (any
signed-in account) or `restricted` (needs the `view restricted literals`
permission). Default `authenticated`, so a forgotten audience hides rather
than publishes. `LiteralAudience::visibleTo($account)` returns the audiences
an account can see and answers both a single access check and a list query
(`audience IN (...)`), applied by the access handler and a `hook_query_alter`
for entity queries and Views. **Every consumer filters this way before any
gist reaches a model.** Other permissions are flat (`administer`, `create`,
`edit`, `delete literals`, plus `use literal lookup tool` and `search
literals` in the submodules). Drafts are visible only to editors.

Core's access handler memoizes per entity per request. View access varies on
`user.permissions` and `user.roles:authenticated`, which is all the rule
depends on, so a page using `[literal:key]` does not fragment per user.

Not built, the likely first extension when a second real audience exists:
per-type "restricted" permissions, or a group that carries the audience (see
"Deferred").

### 5. The finder and the chooser

Terms: the **finder** is the whole lookup (access filter, outcome cache,
chooser, result); the **chooser** is only the model call inside it.

`LiteralFinder::find($question, $account, $context)` returns a
`LiteralFindResult`: `match`, `ambiguous` or `none` (literals, never values),
the tier that answered (`cache`, `chooser`) and a reason.

1. **Candidates:** published literals, filtered by audience in the query and
   by `access('view')` per entity.
2. **Outcome cache** (`cache.default`): key = question + audience set + admin
   flag + a fingerprint of the settings, context and decision model's
   thresholds; tagged `literal_list`; a `none` is cached `miss_ttl` seconds;
   errors are never cached. Never served across permission sets.
3. **Chooser over the whole menu:** one Decision API `ChoiceQuestion`
   (`drupal/ai`'s `Decision` operation type) whose options are key + gist per
   candidate plus a `__none__` option. No decision model configured: `none`
   with reason `no_backend`.

Rules over the answer's per-option probabilities: argmax `__none__` is
`none`; a lead over the best other literal under `choice_margin` is
`ambiguous`; a top below `match_threshold` is `none` (`low_confidence`).
Defaults are 0.5 and 0.2, untuned placeholders that held on every gold set.

- **Whole menu, no shortlist.** The full menu held at 234 literals with no
  loss in accuracy or speed (about 0.3 to 0.4 s per query on hosted Jev,
  flat). No vector DB, Search API or embedding gate (all built or measured,
  none paid; see "Rejected").
- **Values never go to the chooser.** Only key and gist do. Only a write-time
  check would ever need a value.
- **Context.** `chooser_context` (settings, prepended to the instructions)
  says who is being asked, so "you" and "your" resolve to the site's owner;
  one sentence also removed near-topic false positives about other
  organizations. Write contexts positively: a negation ("not the library")
  over-steered. A caller may pass its own context to `find()` (replaces the
  site default for that lookup); the tool does not expose one. It is a hint,
  not a filter. `chooser_instructions` overrides the built-in task prompt.
- **Misses are visible:** `none` and `ambiguous` are first-class results,
  never a guess. A wrong confident match is invisible without a spot-check;
  0 were seen in any run.
- **Hosted-model caution.** CLAUDE.md allows hosted Jev for synthetic or demo
  data only. Gists go to the hosted chooser; values never do. A production
  site needs a local decision model or a data-handling decision first.
- **Latency:** hosted Jev 0.17 to 0.41 s per call, local `tev1:4b` on this
  CPU-only laptop 10 to 14 s ([ADR-0038](../../aim/adr/0038-local-decision-models-parked.md)).
  Supplied figures for a warm GPU (15 to 80 ms p50) are unsourced and
  describe short inputs; re-measure a local model at 50 to 500 options
  before deciding on any pool-chopping.

### 6. Tool, search and lookup modes

`literals:lookup` (Tool API, so MCP via `mcp_server_tool_bridge`; submodule
`literals_tool`) has three modes with precedence `key` > `question` >
`search`. It returns `outcome` (match, ambiguous, none, candidates), `key`,
`value` (empty unless a match) and `candidates` ("key: name - gist" lines)
for `search` and for an ambiguous question, so an agent can see the choices
and ask again by key. Missing, draft and not-visible are one identical
"none". The logic is typed service methods returning a value object, with
the tool a thin wrapper, so it can adopt method-attribute tooling
([ADR-0044](../../aim/adr/0044-tool-api-method-attributes.md)).

`literals_search` is plain search with no AI: every word (up to 6) must
appear in name, key or gist, case-insensitive, published only, audience
filtered, no scoring. It lists candidates and never decides. Its
autocomplete route (removed 2026-10-07, no consumer) returned key + escaped name + gist, never values.
A keyword baseline got 4/29 on the seed set against the finder's 29/29, so it
is no substitute for the chooser.

Agents propose, they never write storage: no tool lets an agent create
literal types or enter values (ADR-0035). A narrow `literal_register` tool
(gist and pointer to an existing target) is not built.

### 7. Tokens

`[literal:key]` (keys are global). A soft dependency on the Token module.
The handler checks view access itself on every resolve (nothing returned and
the token stays unreplaced when unreadable), resolves only the published
revision, reads for `$options['literals_account']` when given (so a nested
token literal is not evaluated for the session user), and bubbles
`literal_list`, the entity's tags and the access result's contexts. Output
is plain text; escaping is the caller's job, like core tokens.
Rejected: a semantic token (`[literal:ask:...]`): token rendering sits on
hot, cached paths, and a model call there is slow, nondeterministic and
uncacheable. Semantic lookup stays in the tool and finder.

### 8. Guardrails at save

Every `gist` and `value` runs through `drupal/ai` Guardrails at entity
validation (decision 7 of the aim CLAUDE.md, extended to literals): the base
`LiteralGuardrails` constraint is a no-op unless a `literals.guardrails`
service exists, which `literals_finder` provides (set `literals_write_guardrails`:
max length 2000, no markup). A value is checked with `deterministic_only`, so
a model-backed guardrail added later still never sees a value. A programmatic
writer must call `validate()` before `save()`, as in aim.

Deferred write-time checks, each a typed decision (Jev, fail closed, never on
the query path): a **one-intent** check (a gist naming two values is
rejected), a **neighbour** check (could a question be answered by one literal
and not another), and a **match** check (does this gist fit this value).
The match check is plausibility, not truth, and
[ADR-0037](../../aim/adr/0037-transient-source-passages-for-grounding.md)
found bare plausibility the wrong test for small local models; measure
before leaning on it.

### 9. The `aim` relationship

There is no `literal` scope, no `aim_scope_literal` and no mirror of gists
into `aim_fact`. A scope says what a fact is **about**; a literal is an exact
value with a fuzzy address, not a fact, and a pointer-only fact has no text
to embed. `entity` scope stays ([ADR-0027](../../aim/adr/resolved/0027-entity-scope.md)):
a fact about a literal is an ordinary `scope=entity` fact with
`target_type=literal`.

`aim` integration is a finder **consumer**: `aim_recall` calls `LiteralFinder`
when the service exists (progressive enhancement), and nothing is stored in
aim. The 2026-10-08 addendum to
[ADR-0052](0052-what-literals-is-for.md) sets how: the finder as a live
source beside vector recall, convert-a-fact retiring the fact, and the answer
modes. Not built (Phase 5), along with convert-a-fact (below). A parked option:
an aim-stored gist backend (the gist lives only as a `scope=entity` fact
targeting the literal). The interface already allows it; it is not built
because it needs two storage modes, a literal form writing another entity's
text, no draft gists on a fact, a retire-on-delete link, and a consolidation
skip.

### 10. Growing and linking the gist

- **Wording grows by observed misses, not hand-kept aliases.** The pinned
  design: a question that returned `none` or `ambiguous`, which a person
  points at the right literal, is folded into the gist by a model that
  proposes a refined gist, a verifier that checks it still names one value
  and does not drift toward a neighbour, and a person who approves a draft
  revision. Same shape as aim's consolidation UPDATE. Needs opt-in query-text
  logging (question text is personal data). Risk: widening a gist pulls the
  literal toward vague neighbours (a wider alias list created a false
  positive in a trial); the neighbour check is the guard.
- **Annotations bridge (optional, not built):** the gist of the field a
  literal points at may be copied once as its default gist; the literal's own
  gist wins. Annotations address a bundle and field, never an instance, so
  per-instance literals keep their own gist. See ADR-0047 and ADR-0053.
  Annotations is `^2.0@alpha`; keep the coupling in an optional package.

## What is built

| Module | Needs | Provides |
| --- | --- | --- |
| `literals` | `user`, `views` (no AI) | entity, types, resolvers, audience access, reader, `[literal:key]`, Guardrails constraint, list UI |
| (in `literals`, was `literals_search`) | none | plain search |
| `literals_finder` | `drupal/ai` | finder, chooser, outcome cache, Guardrails runner, `drush literals:eval` |
| `literals_tool` | `tool` | `literals:lookup` |

Verified: PHPUnit kernel tests (audience matrix, every resolver, reader,
token, key rules, guardrail wiring, tool, finder over a fake chooser) and
`drush literals:eval` gold sets (hosted Jev, 0 wrong-confident in every run;
numbers in [how-it-works.md](how-it-works.md)). The finder's real chooser is
covered only by the eval. The progressive-enhancement ladder (no model,
decision model, large pools) is in [progressive-enhancement.md](progressive-enhancement.md).

## Deferred and pinned

None of these is built; each names its trigger. Measure before building.

- **Staged choice** (pick a group, then a literal) for pools past the full
  menu. Needs an author-written `group` field and group descriptions. The
  flat menu has not degraded at 99 or 234 literals. A caller-supplied
  category narrowing (by type) only helps callers that already know it.
- **A vector shortlist or embedding gate.** Built and removed 2026-10-05:
  with a decision model it added no accuracy and, at 234 literals, no speed;
  alone it could not say none. Revisit only for menus past a few hundred with
  a measured need (a real index past a few thousand).
- **Semantic cache** (nearest past question). Needs a tight threshold keyed by
  permission set; "reservations phone" versus "main phone" is the failure.
- **Groups and per-type access.** Row-level audience works but is fiddly. A
  group entity carrying the audience, or per-type `view {type} literals`
  permissions, would replace `audience`, not sit beside it. Wait for a second
  real audience.
- **Moderation (Content Moderation workflow)** on literal revisions: needed
  for regenerated gists and proposals, pinned until those exist.
- **Proposer** (nightly queue worker, draft revisions with provenance, trusted
  facts only, rate-limited, Guardrails on the proposal, source facts beside
  the diff, separate permission for business-critical literals), and the
  alias review loop. ADR-0035 applies: it only ever creates a draft revision.
- **Convert a fact to a literal** (a short form, never one click): a model
  splits the fact into gist and value, a human picks the type, the literal
  carries a `source_fact` back-reference, and the fact is retired after a
  cool-off. Depends on the agreed `retired`/`expires` split in aim's TODO.md.
- **Probe box** (an "ask a question" form on the edit page showing the match
  and why).
- **Computed ("latest X") literals:** its identity is a rule, not a vetted
  value; if built, its own resolver (a declarative access-checked entity
  query returning an entity reference). Views rejected as the mechanism.
- **Derived pointers ("dreaming")** from trusted data as draft revisions. Stable
  identities (a titled node, a route) fail visibly; role-holders ("the CEO")
  go stale silently and should not ship first.
- **Languages:** a multilingual embedding or chooser model might match across
  languages with no translation; verify with a second-language gold set
  before claiming it. Values return in the visitor's language through entity
  translation if the literal is translated.
- **A `literal_register` agent tool and the aim-stored gist backend** (section
  6 and 9).
- **Keyword fallback when the finder says none.** "dimwit?" gets
  `chose_none` although the search would match "dimwit" in the gist. Idea:
  on none, offer search hits as "did you mean", never as an answer, with its
  own audit outcome (`suggested`) so the log still counts finder matches
  honestly. Parked on purpose: it mixes a decision and a keyword guess.
- **Named per-medium contexts** (a registry). The per-call `context`
  already works; build the registry only for a second consumer.
- **A local decision model.** Re-measure the chooser (latency, accuracy, 50
  to 500 options) before deciding whether any pool-chopping is needed.
- **`search_api` behind `literals.search`.** Stemming, speed at thousands,
  facets; not needed at a few hundred.
- **Guardrails runner as its own module** (`literals_guardrails`): dropped
  2026-10-08, tidiness for a hypothetical site. The runner stays in
  `literals_finder` because it needs `drupal/ai`.
- **Tokens in body copy** (a `token_filter` text-format filter): dropped
  2026-10-08. A filter that does not pass our cache contexts could serve a
  restricted value to the wrong viewer, for a rarely used feature. The
  editor-side token browser work (scope it with
  `'#token_types' => ['literal']`, `'#global_types' => FALSE`; narrow
  `LiteralForm`'s current `'all'` to match) is only worth doing for callers
  that control their render.
- **Configurable display text for the audience levels** and **per-type
  restricted permissions**: small polish, nothing needs them yet.
- **An "allow external URLs" type setting**: not wanted; the `url` resolver
  stays internal paths only.

## Rejected

- **Vector score as the selector:** cannot express ties; top-k crowding and
  the cutoff make a miss possible for a well-written gist.
- **Keyword-stuffed gists:** pull a gist toward other intents.
- **An alias field:** built and fully removed 2026-10-05 (not asked for).
  Reconsidered 2026-10-06: the plain-text `ALSO` convention tested 9/9 on
  invented names with no field, parser or structured option rendering
  (Jev's Choice does accept structured option descriptions, but a send-time
  parse of the gist adds failure modes for no measured gain). A wider alias
  list pulled near-topic questions in.
- **Rewriting the query with Jev:** it cannot generate text.
- **A literal field type, a registry entity, an Annotations host, a
  `config_pages` host:** section 1.
- **Pools as bundles, per-pool permissions and `[literal:pool:key]`:**
  replaced by types and one audience column (Phase 1 build).
- **A literal scope, an `aim_scope_literal` bridge and a gist mirror in
  `aim_fact`:** a second copy of the gist with its own embedding, sync code
  on every save, delete and draft, and a standing duplicate invariant.
- **Form API `#pattern` for value patterns:** validates on submit only, so
  agents bypass it. Typed Data constraints instead.
- **Auto-applying regenerated gists, aliases or proposals, and letting
  agents create literal storage or values:** any can silently redirect the
  agent or poison a business-critical value.
- **Value in the fact text** (the status quo and ADR-0039's rejected option):
  drifts, embeds badly, gets consolidated.
- **A separate vector row per literal under `aim`.**

## Consequences

- A usable settings store with no AI, and a natural-language address book on
  top for sites that want one, depending on `drupal/ai` alone.
- The value is never embedded or consolidated; the finder and chooser are the
  only probabilistic parts. Stale copies cannot occur because the value
  lives once.
- Reliability is bounded by the chooser's accuracy, measured per site. Misses
  surface as `none` or `ambiguous`. Every number so far is in-sample (gold
  sets written by Claude); a blind set written by Nik is the fair test.
- The hosted decision model sees real gists. Demo data only until a local
  model or a data-handling decision exists.
- A site that adopts the proposer, convert-a-fact or the alias merge takes on
  a review queue someone must staff.

## Logging

Per CLAUDE.md: `literals.settings:log_audit` (off) writes insert, update,
delete and read lines with id, key, type, audience, uid and outcome;
`literals_finder.settings:log_audit` logs finder lookups (ids, outcome, tier
and reason). Never the gist, value or question text. An opt-in
`log_query_text` would gate the missed-question merge only.

## Open questions

- Should the finder, on `none`, offer `literals_search` hits as "did you
  mean" suggestions (kept visibly separate, its own audit outcome)? Parked.
- Per-type restricted permissions versus a group carrying the audience, once
  a second audience exists.
- Chooser accuracy at 500 or more options, hosted and local, and the point
  where the staged choice or a vector shortlist earns its place.
- Whether a local in-house Jev model changes any of the above (clear the
  finder cache when swapping models; the cache fingerprint deliberately omits
  the model).
- Whether `config_pages` and `dynamic_entity_reference` ever warrant a
  resolver of their own; whether the Token provider should declare dynamic
  tokens per literal or only the generic chain.
- Whether `literals` clashes on drupal.org (the check has not been run and is
  needed before release).
- ~~Fold `literals_search` into the base module?~~ Done 2026-10-07.
