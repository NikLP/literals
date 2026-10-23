# Context

A visitor asks the chat bot "what is the phone number?". The value exists in
exactly one right place and the agent has to find it. Without literals it can
only call `aim_recall`, which embeds the question and searches fact text. A
number is a poor target for an embedding: recall can miss it, return opening
hours instead, or return a stale copy that consolidation has merged or
rewritten. A missed recall is also invisible.

The idea: **the value is exact, the address is probabilistic.** A person or
agent describes what they want in words; the system works out which exact
value that means and returns it through an access-checked resolve. The value
is never embedded, paraphrased or consolidated.

Two facts shape everything below:

1. **The match comes from a typed decision model, not a vector score.** A
   decision model
   picks from a menu of options, or says "none" or "ambiguous". It cannot
   generate text, so it can choose among IDs it is given but never invent a
   value. Literals are a small, closed, human-vetted set, which is the easy
   case for a classifier.
2. **The semantic part is optional.** A literal is first a key and a value.
   Exact lookup by key (PHP, tokens, the tool) is a complete product with no
   AI at all; the finder is the optional layer on top.

**Is it worth its curation cost, or is Search API simply better?**

- For navigational queries Search API wins. A bill payer wants a landing
  page, search returns it, and nobody wrote a gist. Hand-writing a literal
  per popular destination is a second sitemap.
- What search cannot do: return one exact value (phone, token, link) or an
  honest "none" instead of nearest neighbours; resolve per viewer with an
  access check; serve consumers that are not chat (a form field asking for
  "our phone number", a `[literal:key]` token, an MCP tool).
- The model never sees values: the chooser sees gists and IDs only, and the
  value is resolved afterward. A hosted chat model doing recall would see it.

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

### 1. What literals is for, and what it is not

- **A find-and-gate layer for things, not fields.** A literal is a thing a
  person would point at (a phone number, a login page, a "pay your council
  tax" entry point). A field is only where a value lives.
- **Not justified by chat cost alone.** Five reasons to adopt it, in order
  of defensibility: correct-or-none answers; values kept out of prompts;
  per-viewer access at resolve time; non-chat consumers (tokens, form
  fields, MCP); and, weakest, the cost gate (section 7).
- **No second sitemap.** Navigational destinations come from a menu and
  page candidate provider (main-menu links and nodes flagged as entry
  points; see "Candidates" under "Deferred and pinned"), not hand-typed
  literals.
- **Gists are written by a person.** Core metadata is too thin to describe
  things in the words people use, so harvesting gists from it does not work;
  a gist can be suggested from a token's description, then rewritten.

### 2. A standalone `literal` content entity

A `literal` is a revisionable content entity with a published status, owner
and timestamps; the bundle is the **type** (section 3). Values are content,
not config: editing a phone number on production never needs a config import.
Only the types are config.

It is an entity and not a field type because the
fields must live together in one place with revisions, access and a list UI.

### 3. The parts, settled (mechanics live in the docs)

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
- **Resolvers return a pair, not a string.** `ResolvedLiteral` (value,
  label, kind) through `resolveItem()` and `LiteralReader::readItem()`, so a
  bare `/node/5` becomes a usable link. Literals supplies the data (the
  literal's name, or the entity title); the caller (chat, block, aim's gate)
  renders it.
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
  data-handling decision first. Latency and the context-line findings are
  under "Measurements".
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
- **Tokens.** `[literal:key]` and `[literal:key:link]`, checked for view
  access on every resolve, published revision only, escaping left to the
  caller like core tokens. Callers must pass and bubble `CacheableMetadata`
  (`LiteralReader` does), or per-user text is cached for the wrong user.
  Rejected: a semantic token (`[literal:ask:...]`), because token rendering
  sits on hot cached paths and a model call there is slow, nondeterministic
  and uncacheable.
- **A token literal resolves for the asking account** (the former ADR-0048,
  built 2026-10-05). `resolve($literal, $account)` once ignored `$account`,
  so `[current-user:*]` resolved for the session user (an agent, queue
  worker or Drush call acting for B inside session A got A's data cached
  under B's context), and access to the data a token reveals was never
  checked. Now `[user:...]` always means the asking account (`resolve()`
  passes it and adds the `user` cache context), and `validate()` rejects
  `current-user` and any token type whose token info sets `needs-data`,
  other than `user` (the token system's own flag, not a hardcoded list). A
  token literal can only reveal global values (`site`, `current-date`) and
  the asking account's own data, by construction rather than author trust.
  `AccountSwitcherInterface::switchTo()` was rejected: it makes the session
  lie and leaves the data-access gap open. Entity data goes through the
  `entity`, `field` and `content` resolvers, each with its own view-access
  check, not a token target (see "Rejected").

### 4. Guardrails at save

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

### 5. Growing and linking the gist

- **Wording grows by observed misses, not hand-kept aliases.** The pinned
  design: a question that returned `none` or `ambiguous`, which a person
  points at the right literal, is folded into the gist by a model that
  proposes a refined gist, a verifier that checks it still names one value
  and does not drift toward a neighbour, and a person who approves a draft
  revision. Same shape as aim's consolidation UPDATE. Needs opt-in query-text
  logging (question text is personal data). Risk: widening a gist pulls the
  literal toward vague neighbours (a wider alias list created a false
  positive in a trial); the neighbour check is the guard.

### 6. The `aim` relationship

A literal is an exact
value with a fuzzy address, not a fact, and a pointer-only fact has no text
to embed. `entity` scope stays ([ADR-0027](../../aim/adr/resolved/0027-entity-scope.md)):
a fact about a literal is an ordinary `scope=entity` fact with
`target_type=literal`.

`aim` depends on `literals` (hard) but not on `literals_finder`, and stores
nothing of a literal. Built 2026-10-08 to 10-15:

- `aim` replaces `[literal:key]` and `[literal:key:link]` in fact text at
  recall as the viewing account; a fact naming an unreadable literal is
  withheld whole.
- The chat assistant looks values up with `aim_literal`, a wrapper over
  `literals.lookup` exposed through `tool_ai_connector`. It stays for MCP
  and external agents, which have no gate in front of them.
- **The finder is the gate, ahead of the chat model** (`AimGateProcessor`, a
  `ChatProcessor` in aim_chatbot, live 2026-10-15; open points in aim's
  TODO.md). Measured 2026-10-12 (`drush aim:chatbot-eval`, numbers under
  "Measurements"): letting the chat model decide to
  call `aim_literal` cost two hosted calls plus the finder and still
  under-called the tool (20/51 on the 101-literal set, against the finder's
  50/51 on the same questions). Putting the finder first and handing the
  question to the chat model only on `none` or `ambiguous` used a fifth to a
  quarter of the hosted tokens at 101 literals, was the fastest, and kept
  per-viewer visibility. Stuffing the prompt with every readable value
  scaled with the pool (3x tokens at 101 literals) and cannot serve two
  audiences. A hit skips the hosted model only; the Jev call stays, as it is
  the fuzzy match.
- `aim_recall` does **not** call the finder (section 7).

A parked option: an aim-stored gist backend (the gist lives only as a
`scope=entity` fact targeting the literal). The interface already allows it;
it is not built because it needs two storage modes, a literal form writing
another entity's text, no draft gists on a fact, a retire-on-delete link, and
a consolidation skip.

### 7. How aim could use literals further (unbuilt)

Design for aim's side, pulled out one at a time when a site needs it.

1. **Convert-a-fact retires the fact.** Promoting an extracted one-shot fact
   (an exact value) to a literal supersedes the fact through the existing
   non-destructive `superseded_by` edge, through a short form, never one
   click (mechanics under "Deferred and pinned"). The literal's gist is then
   the only description of the thing; the value lives once. Facts that
   merely mention a literal in a longer sentence stay facts and carry
   `[literal:key]`, so the value is never stale.
2. **Two ways to show a literal in recall.**
   - *Finder as a live source:* `aim_recall` asks the finder as a second
     source and returns the literal as a fact-shaped live result (value
     resolved for the viewer). Nothing stored in `aim`. The finder's failure
     mode is `none` (0 wrong-confident measured), not a wrong value.
   - *Pointer fact with a tracked gist:* a fact with no text of its own whose
     index entry is computed from the literal's gist. Only worth building if
     the live source proves insufficient. If both run, de-duplicate by
     literal key.
3. **Two answer modes, a setting.**
   - *Mode 1, literal only:* the reply is the fixed text and link; no large
     model, lowest cost, the value never reaches a model. (The gate's
     template reply today.)
   - *Mode 2, literal plus smoothing:* the question and the resolved literal
     go to the large model for a natural reply.
   - *Escalation:* mode 1 can show a "think harder" control that re-runs the
     question through mode 2 or the full recall path (a skill, wired later).
4. **Visibility decides what may reach a hosted model.** Default: mode 2 only
   for unrestricted values; restricted values stay mode 1, or go to a local
   model. A trade-off accepted until local reasoning models are available,
   not a settled principle. It assumes aim's standing rule that it is not for
   classified data (aim CLAUDE.md, Scope).
5. **Copy controls are front-end work.** The chat items already carry value,
   label and kind, so a "copy value" / "copy link" button shown by role or
   restriction needs nothing new in `literals`.
6. **An optional pre-gate** ("is this a lookup?" ahead of the finder), only
   if measured to pay.

## What is built

Modules and what each provides: [CLAUDE.md](../CLAUDE.md) "Modules"; tests:
[DEVELOPING.md](../DEVELOPING.md) "Tests". The finder's real chooser is
covered only by `drush literals:eval`.

**The model ladder.** Step 0, no model: exact key (PHP, tokens, the tool)
and plain search, a complete product; a question gets `none`/`no_backend`.
Step 1, one decision model: the finder, one chooser call per uncached
question. Step 2, large pools (staged choice or a vector index): unbuilt,
not needed so far. Adding a step is a module install and a provider setting.
With step 1, the question, the context and the visible candidates' keys and
gists leave the site; values never do.

## Measurements

All hosted Jev, all with gold sets written by Claude or an agent (in-sample,
smoke tests, not benchmarks). Site results and sweeps live with the site's
eval data (this site: `demo/literals-eval/RESULTS.md`).

| Date | Set | Pool | Answerable hit | Wrong-confident | Unanswerable correct | Per query |
| --- | --- | --- | --- | --- | --- | --- |
| 2026-10-05 | seed | 9 | 29/29 | 0 | 10/10 | 0.3 s |
| 2026-10-05 | synthetic `zz_` pools | 60 / 234 | 60/60 | 0 | 10/10 | 0.4 s |
| 2026-10-07 | paraphrase | 9 / 99 | 44/45 | 0 | 15/15, 14/15 | |
| 2026-10-07 | agent-written from the menu alone | 9 | 28/29 | 0 | 13/16 | |
| 2026-10-12 | blind (Claude-drafted) | site | 16/22 | 2 | | |
| 2026-10-22 | seven sets | 114 | 191/192 | 1 | | 0.3 s |

- **Keyword baseline** (seed set): the module's search 4/29 hits; a crude
  ranked variant 17/29 with 1 wrong. The chooser's lead is paraphrase and
  saying none.
- **Context line.** Without one, "what is your phone number" scored `none`
  (whose number?); with it, a match. One sentence (pick the library's own
  services, answer none if another organization is named) removed three
  near-topic false positives for 1-2 misses. Negated or long contexts
  over-steered toward none. Prompt rewording of the instructions did not help.
- **Thresholds.** Raising `match_threshold` to 0.7 removed one false
  positive, 0.85 started losing hits. The margin mattered not at all.
- **A bigger pool turns "I don't know" into "the closest thing"**: false
  positives at 99+ literals are gray-zone picks. Pool size did not hurt hits
  up to 234.
- **`ALSO` phrases** recovered misses (9/9 against 4/9 on invented names),
  written after seeing the misses; a category-level one ("payments") added a
  false positive.
- **Gist overlap (2026-10-22):** two gists sharing wording did not predict
  confusion: the one wrong pick in 192 shared no wording with its gist, and
  21 hits had a word-sharing runner-up and were still right. A warning was
  built and removed.
- **Embedding gate** (built and removed 2026-10-05): same hits and
  correct-none as the chooser alone at 9 and 234 literals, faster only at 9;
  alone it could not say none; about 450 lines, a queue and three drifting
  thresholds.

**The gate, end to end** (2026-10-12, `drush aim:chatbot-eval`, 39
agent-written questions; `tool`: the chat model calls `aim_literal`;
`prompt`: every readable value in the system prompt; `gate`: the finder
first, the chat model only on `none` or `ambiguous`):

| Mode | Hosted tokens, 11 / 101 literals | Seconds, 101 | Correct, agent set | Correct, 101-literal set |
| --- | --- | --- | --- | --- |
| `tool` | 3,422 / 3,360 | 4.2 | 20/23 | 20/51 |
| `prompt` | 1,874 / 5,599 | 2.6 | 22/23 | 37/51 |
| `gate` | 923 / 604 | 1.3 | 23/23 | 50/51 |

The chat model is a poor gate (it often never called the tool); the prompt
path scales with the pool and cannot serve two audiences (restricted values:
`tool` and `gate` 5/5 for the signed-in user, `prompt` 1/5, none leaked to
anonymous). "Correct" means the stored value appears in the reply, so
chat-model rephrasing counts as wrong.

**Jev limits** (TypeSafe docs, 2026-10-06): up to 255 options per Choice and
about 32k tokens for the whole menu; Jev leans to the first option, loses
accuracy on similar options with long descriptions and reads negation
literally. Open models are far tighter (`laya:en` about 125 options), so a
small local model needs a per-send gist budget or a paged menu.

**Not measured:** pools past 234, a local decision model on the chooser, a
second language, an independently written gold set, and live traffic (the
real miss rate).

## Deferred and pinned

None of these is built; each names its trigger. Measure before building.
Actionable next steps are in [BACKLOG.md](../BACKLOG.md).

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
- **Convert a fact to a literal** (section 7, item 1): a model splits the
  fact into gist and value, a human picks the type, the literal carries a
  `source_fact` back-reference, and the fact is retired after a cool-off.
  Depends on the agreed `retired`/`expires` split in aim.
- **Candidates** (folded in from the retired ADR-0047;
  nothing built). Candidates are suggestions, never entities: a provider
  (a curated YAML list of site tokens, a generic one over
  `Token::getInfo()` filtered to site-level types with `current-user`
  excluded, or a menu and page provider: main-menu links and nodes flagged
  as entry points, label from the menu title, value a path or entity)
  proposes a (source, key, gist) triple, a person edits and saves, and only
  then does a `literal` exist, so the count tracks use, not the token list
  (every literal costs a menu slot). Stores like `site_settings` and
  `token_custom` hold values but cannot find one from a question, gate it
  by viewer or carry a gist (checked 2026-10-05); literals is the
  find-and-gate layer over them. Gist suggestion prefills from the
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
- **A `literal_register` agent tool and the aim-stored gist backend**
  (sections 3 and 6).
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
  example phrasings per answer as their main lever. Re-test tracked in
  [BACKLOG.md](../BACKLOG.md). Until then: add specific `ALSO`
  phrasings freely (ideally from real misses), never one-word or
  category-level ones ("payments"), and keep the `chooser_context` line.
- **Stacked questions in the chooser call.** The Decision API takes several
  questions per request: `one_shot`, `asks_for_value` or `sensitive`
  booleans could short-circuit to `none` before the choice is read, at no
  extra round trip. Add one only if a set shows false matches it would
  catch, and re-run the eval (it may shift the choice probabilities).
- **A local decision model.** One CPU cross-encoder was tried 2026-10-10
  (`ms-marco-MiniLM-L-12-v2`, 99 literals, 206 gold queries): at best 84/142
  answerable hits with 7 wrong-confident, about 0.7 s per query. Not a
  replacement. A local `tev1:4b` typed choice took 10 to 14 s on this
  CPU-only laptop (ADR-0038). Untried: a larger reranker (`bge-reranker-v2-m3`), NLI
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
  ("Measurements"). At most a per-type
  threshold, if one type misbehaves.
- **Rewriting the query with Jev:** it cannot generate text.
- **A literal field type, a registry entity, a
  `config_pages` host:** section 2.
- **Pools as bundles, per-pool permissions and `[literal:pool:key]`:**
  replaced by types and one access column (Phase 1 build; the column became the `restricted` flag 2026-10-10).
- **A literal scope, an `aim_scope_literal` bridge and a gist mirror in
  `aim_fact`:** a second copy of the gist with its own embedding, sync code
  on every save, delete and draft, and a standing duplicate invariant.
- **An entity target on token literals** (the former ADR-0048 Part B,
  dropped 2026-10-12): a `target` (`entity_type:id`) so `[node:title]` could
  resolve with an access check. The `entity`, `field` and `content`
  resolvers already reach entity data with their own view-access checks.
- **Harvesting gists from Drupal metadata alone:** too thin (section 1).
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
- A short fixed list, or a few verbatim facts with consolidation opted out
  ([ADR-0020](../../aim/adr/0020-verbatim-facts-consolidation-opt-out.md)),
  covers the "well known but unavailable" case on a typical site without a
  finder. The finder only earns its keep when such values are numerous or
  restricted.
- Reliability is bounded by the chooser's accuracy, measured per site. Misses
  surface as `none` or `ambiguous`. Most numbers are in-sample (gold sets
  written by Claude or an agent), so a set written by Nik, or real traffic,
  is still the fair test.
- The hosted decision model sees real gists. Demo data only until a local
  model or a data-handling decision exists.
- A token literal saved earlier with `current-user` fails validation on its
  next save.
- A site that adopts the proposer, convert-a-fact or the alias merge takes on
  a review queue someone must staff.
- Demo literals ship as default content in the `literals_demo_library`
  recipe (finder and chat demo) and aim's `aim_demo_library` recipe.

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
- Whether a local in-house Jev model changes any of the above (the finder
  cache fingerprint includes the model ID, so a swap re-asks). Local Jev
  latency on the gate is unmeasured.
- Whether a Jev-written sentence around the gate's value is worth one more
  in-house call.
- Does a menu and page candidate provider keep gist coverage high enough to
  matter?
- Whether `config_pages` and `dynamic_entity_reference` ever warrant a
  resolver of their own; whether the Token provider should declare dynamic
  tokens per literal or only the generic chain.
- Whether `literals` clashes on drupal.org (the check has not been run and is
  needed before release).
