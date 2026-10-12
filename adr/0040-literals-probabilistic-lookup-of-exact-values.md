# ADR-0040: Literals - exact values with a fuzzy address, found by a Jev choice

**Status:** Accepted (settled by the Phase 1 to 3 builds); built through the finder, tokens, tool and
search (see "What is built"). Refactored 2026-10-07 from a draft with nine
addenda into the current state; the superseded designs (a field type, an
Annotations host, a `config_pages` host, pool bundles, an `aim_fact` mirror,
an embedding gate) are summarized under "Rejected" (the alias field is
pinned, see "Deferred and pinned") and the
full text is in the `aim` repo history (`adr/0040-...` at commit 502f799).
Supersedes the retired ADR-0039 (`scope: token`, label-only facts resolving live from a `config_pages` token; removed, history in git).
**Date:** 2026-10-03

Related: [ADR-0048](0048-token-literal-access-and-entity-targets.md)
(token access, entity targets), [ADR-0052](0052-what-literals-is-for.md)
(what it is for), [ADR-0049](../../aim/adr/0049-finder-family-parked.md) section D
(the shared "gist" term, formerly ADR-0053). Current state and runbooks:
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

Outside Drupal (reviewed 2026-10-10, from knowledge plus a short look at
semantic-router and NeMo Guardrails), each part has an established
equivalent: FAQ-bot intent matching (Dialogflow, Rasa, Azure custom
question answering) for gist-to-answer with a "none"; semantic-router and
NeMo dialog rails for the gate; Intercom-style custom answers for answer
mode 1; PII tokenization proxies and text-to-SQL semantic layers for "the
model picks, the value is filled in after"; DITA keyref and gettext
translator comments for keyed values with a description; feature flags for
per-audience resolution; Wikidata label/description/aliases with NIL for
entity linking. What is new is only the combination inside Drupal. Two
consequences: example phrasings (aliases) are the field's main lever, so
they are pinned, not rejected (see "Deferred and pinned"); and the chooser's
measured gain over the removed embedding gate comes from a model reading the
whole menu with "none" allowed, not from Jev being typed. Values never reach
the chooser, and constrained decoding (a local model limited to a JSON enum
of the menu's keys) gives the same "can only pick from the list" guarantee.

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

### 2 to 7. The parts, settled (mechanics live in the docs)

Condensed 2026-10-12: these sections described what is now documented where
it is maintained, so only the decisions and their reasons stay here. Anatomy
and stored fields: [how-it-works.md](how-it-works.md) "What is stored". Types,
the finder, the tool and tokens in use: [DEVELOPING.md](../DEVELOPING.md).
The rules in force (access, keys, revisions, the `field` resolver):
[CLAUDE.md](../CLAUDE.md) "Decisions in force".

- **Anatomy.** Name, key (the entity ID, unique, immutable, the only handle
  logs may carry), value, gist (255 characters, one-shot wording; arbitrary
  house names go after a plain-text `ALSO`, measured 9/9 against 4/9 without,
  no field and no parser) and a `restricted` flag. No numeric confidence
  field: the judgement lives in the gist's wording.
- **One value per literal, validated by its type's resolver at save** (a
  constraint, so agents and Drush are covered). The finder, tokens and tool
  call one `$literal->resolve()` and never read `value`. Rejected: a literal
  holding several fields of mixed kinds (the `config_pages` model), because
  the finder would have to understand arbitrary field types. `url` is
  internal paths only (external URLs cannot be access checked).
- **Access is one boolean, not audiences.** The `literal_audience` entities
  and three-level column were removed 2026-10-10 as more machinery than one
  realistic split needed. `LiteralVisibility::visibleFlags($account)` answers
  both a single check and a list query, and every consumer filters with it
  before any gist reaches a model. A hidden literal is indistinguishable from
  a missing one. Likely first extension when a second real audience exists:
  per-type "restricted" permissions, or a group carrying the audience.
- **The finder is the whole lookup; the chooser is the one model call in it.**
  It returns `match`, `ambiguous` or `none` (literals, never values), the
  tier that answered and a reason. The whole menu goes to the chooser with
  no shortlist (234 literals held, about 0.3 to 0.4 s flat); no vector DB,
  Search API or embedding gate (all built or measured, none paid; see
  "Rejected"). Values never go to the chooser, only key and gist. Misses are
  first-class results, never a guess. Defaults (`match_threshold` 0.5,
  `choice_margin` 0.2) are untuned placeholders that held on every gold set.
  Hosted Jev is for synthetic or demo data only (gists go to it, values
  never do); a production site needs a local decision model or a
  data-handling decision first. Latency and the context-line findings are in
  how-it-works.md and progressive-enhancement.md.
- **Tool, search and lookup modes.** `literals:lookup` has three modes with
  precedence `key` > `question` > `search`; missing, draft and not-visible are
  one identical "none". The logic is typed service methods returning a value
  object, the tool a thin wrapper, so it can adopt method-attribute tooling
  (ADR-0044, retired). Plain search (no AI, every word must appear) lists
  candidates and never decides; a keyword baseline got 4/29 on the seed set
  against the finder's 29/29. Agents propose, they never write storage: no
  tool creates literal types or enters values (ADR-0035), and a narrow
  `literal_register` tool (gist and pointer to an existing target) is not
  built.
- **Tokens.** `[literal:key]`, checked for view access on every resolve,
  published revision only, escaping left to the caller like core tokens.
  Rejected: a semantic token (`[literal:ask:...]`), because token rendering
  sits on hot cached paths and a model call there is slow, nondeterministic
  and uncacheable.

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
[ADR-0037](../../aim/adr/resolved/0037-transient-source-passages-for-grounding.md)
found bare plausibility the wrong test for small local models; measure
before leaning on it.

### 9. The `aim` relationship

There is no `literal` scope, no `aim_scope_literal` and no mirror of gists
into `aim_fact`. A scope says what a fact is **about**; a literal is an exact
value with a fuzzy address, not a fact, and a pointer-only fact has no text
to embed. `entity` scope stays ([ADR-0027](../../aim/adr/resolved/0027-entity-scope.md)):
a fact about a literal is an ordinary `scope=entity` fact with
`target_type=literal`.

`aim` depends on `literals` (hard) but not on `literals_finder`, and stores
nothing of a literal. Built 2026-10-08 to 10-10: `aim` replaces
`[literal:key]` tokens in fact text at recall as the viewing account (a fact
naming an unreadable literal is withheld), and the chat assistant looks
values up with `aim_literal`, a wrapper over `literals.lookup` exposed
through `tool_ai_connector`; today the large model is the gate for one-shot
lookups (measured 2026-10-12: a finder-first gate beats that, ADR-0052 decision 3). `aim_recall` does **not** call the finder: the finder as a live
recall source, answer modes and convert-a-fact are the unbuilt options in
the 2026-10-08 addendum to [ADR-0052](0052-what-literals-is-for.md). A parked option:
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
  per-instance literals keep their own gist. See "Deferred and pinned" (tracked gists) and [ADR-0049](../../aim/adr/0049-finder-family-parked.md) section D.
  Annotations is `^2.0@alpha`; keep the coupling in an optional package.

## What is built

Modules and what each provides: [CLAUDE.md](../CLAUDE.md) "Modules".
Verified: PHPUnit kernel tests (visibility matrix, every resolver, reader,
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
- **Groups and per-type access.** The row-level `restricted` flag is one
  split only. A group entity carrying an audience, or per-type
  `view {type} literals` permissions, would replace it, not sit beside it. Wait for a second
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
- **Candidates and tracked gists** (folded in from the retired ADR-0047;
  nothing built). Candidates are suggestions, never entities: a provider
  (a curated YAML list of site tokens, or a generic one over
  `Token::getInfo()` filtered to site-level types, `current-user` excluded)
  proposes a (source, key, gist) triple, a person edits and saves, and only
  then does a `literal` exist, so the count tracks use, not the token list
  (every literal costs a menu slot). Stores like `site_settings` and
  `token_custom` hold values but cannot find one from a question, gate it
  by viewer or carry a gist (checked 2026-10-05); literals is the
  find-and-gate layer over them. Tracked gists: a nullable gist source
  (target plus annotation ID) resolved at read time, with a local edit
  winning and stopping tracking; this replaces "copy the gist once" in
  section 10 and ships as an optional `literals_annotations` bridge
  (`Literal::getGist()` is the seam). Gist suggestion prefills from the
  token's own description; a person or small local model rewrites it into
  question phrasing. `current-user` tokens resolve per viewer.
  Facts reference literals through `aim_literal` or an inline
  `[literal:key]`; a pointer field on `aim_fact` is deferred (it would cut
  across "`aim` stores nothing about literals").
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
- **Per-type restricted permissions**: small polish, nothing needs them yet.
- **An "allow external URLs" type setting**: not wanted; the `url` resolver
  stays internal paths only.
- **Example phrasings per literal (aliases).** An alias field was built and
  removed 2026-10-05 (not asked for). Today the plain-text `ALSO` convention
  in the gist covers it: 9/9 on invented names with no field, parser or
  structured option rendering (Jev's Choice accepts structured option
  descriptions, but a send-time parse of the gist adds failure modes for no
  measured gain). A wider alias list pulled near-topic questions in, on one
  in-sample trial. Pinned, not rejected (2026-10-10): intent-matching
  systems (Dialogflow, Rasa, Azure custom question answering) treat several
  example phrasings per answer as their main lever. Re-test after a blind
  question set; tracked in aim's TODO.md. Until then: add specific `ALSO`
  phrasings freely (ideally from real misses), never one-word or
  category-level ones ("payments"), and keep the `chooser_context` line.
- **A local decision model.** One CPU cross-encoder was tried 2026-10-10
  (`ms-marco-MiniLM-L-12-v2`, 99 literals, 206 gold queries): at best 84/142
  answerable hits with 7 wrong-confident, about 0.7 s per query. Not a
  replacement. Untried: a larger reranker (`bge-reranker-v2-m3`), NLI
  zero-shot (`deberta-v3` zeroshot), a small local model with constrained
  JSON-enum output via Ollama. Re-measure any candidate (latency, accuracy,
  50 to 500 options) before deciding whether pool-chopping is needed.

## Rejected

- **Vector score as the selector:** cannot express ties; top-k crowding and
  the cutoff make a miss possible for a well-written gist.
- **Keyword-stuffed gists:** pull a gist toward other intents.
- **A match threshold per literal** (semantic-router's fitted per-route
  thresholds): needs 10 to 20 labelled questions per literal; the gold sets
  have one to three. Raising the global threshold already did not help
  (section on measurements in how-it-works.md). At most a per-type
  threshold, if one type misbehaves.
- **Rewriting the query with Jev:** it cannot generate text.
- **A literal field type, a registry entity, an Annotations host, a
  `config_pages` host:** section 1.
- **Pools as bundles, per-pool permissions and `[literal:pool:key]`:**
  replaced by types and one access column (Phase 1 build; the column became the `restricted` flag 2026-10-10).
- **A literal scope, an `aim_scope_literal` bridge and a gist mirror in
  `aim_fact`:** a second copy of the gist with its own embedding, sync code
  on every save, delete and draft, and a standing duplicate invariant.
- **Form API `#pattern` for value patterns:** validates on submit only, so
  agents bypass it. Typed Data constraints instead.
- **Auto-applying regenerated gists, aliases or proposals, and letting
  agents create literal storage or values:** any can silently redirect the
  agent or poison a business-critical value.
- **Value in the fact text** (the status quo and retired ADR-0039's rejected option):
  drifts, embeds badly, gets consolidated.
- **A literal inside a token literal (nesting), decided 2026-10-12:** a
  literal is one exact value; composing several belongs to the consumer
  (fact text and the assistant already hold several tokens). Nesting adds
  recursion, a cache-metadata chain and a "whose access applies" question.
  Validation refuses it at save, and `TokenResolver::resolve()` strips any
  `[literal:...]` at resolve time (fail closed, resolves to nothing), so a
  value saved past validation cannot leak. Reversible: drop the check and
  the strip.
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
delete and read lines with id, key, type, restricted flag, uid and outcome;
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
