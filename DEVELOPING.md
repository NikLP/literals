# DEVELOPING - literals

Commands, API and runbooks. Rules and gotchas are in [CLAUDE.md](CLAUDE.md);
decisions and measurements are in
[ADR-0040](adr/0040-literals-probabilistic-lookup-of-exact-values.md).

## What is stored

One revisionable content entity, `literal`, with a published status. The
bundle is a **type** (config entity) whose **resolver** plugin validates the
value at save and resolves it at read (`text`, `url`, `token`, `content`,
`entity`, `field`; `entity` and `field` ship disabled).

| Field | Holds | Seen by a model? |
| --- | --- | --- |
| `name` | Human label, "Main phone number" | No |
| `id` (the key) | Machine name, unique, immutable | Yes, as the chooser's option ID |
| `gist` | Short description of what the value is | Yes (chooser) |
| `value` | The exact payload, read through its resolver | **Never** |
| `restricted` | Only `view restricted literals` holders see it | No: filters before any model |

Types, settings and the view are config; literals are content, editable on
production.

## Reading a literal (PHP)

| Call | Returns |
| --- | --- |
| `literals.reader` `read($key, $account)` | the resolved value string, or NULL (missing, draft and not visible are one identical NULL) |
| `literals.reader` `readItem($key, $account)` | a `ResolvedLiteral` (`value`, `label`, `kind` of url/phone/email/text) or NULL; `->href()` gives the safe link target (http(s), `tel:`, `mailto:`) or NULL |
| `$literal->resolveItem($account, $metadata)` | the same, from a loaded `Literal`. `@internal`: it skips the literal's own access check (`restricted`) and does not add the `user.permissions` context, so new code reads through `literals.reader` instead; `$metadata` is required |
| `literals.search` `search($text, $account, $limit)` | published, visible literals whose name, key or gist contain every typed word (up to 6); candidates, never a decision |
| `literals.lookup` `lookup($input, $account, $caller)` | the one tool-shaped lookup: `$input` has `key`, `question` (needs the finder) or `search`; returns `success`, `message` and `values` (`outcome` match/ambiguous/candidates/none, `key`, `candidates`, `value`, `label`, `kind`, `gist`). Both lookup tools (`literals:lookup`, aim's `aim_literal`) only wrap it |
| `literals_finder.finder` `find($question, $account, $context = NULL)` | a `LiteralFindResult`: `match` / `ambiguous` / `none` with `Literal` entities (no values), a tier and a reason |

A finder result holds entities, not values: the consumer calls
`resolveItem()` itself, skips a null or empty value, and downgrades a match
that resolves to nothing to `none`; `LiteralReader::resolveFound()` does
that, used by `LiteralLookup` and `LiteralsChatResponder`. Pass `CacheableMetadata` to
collect cache metadata; the reader bubbles `literal_list`, the entity's
tags and the access context (`user.permissions`).

## Tokens

- `[literal:key]` - the value as plain text (core escapes it; the caller
  controls the render).
- `[literal:key:link]` - HTML: `<a>` for url (label as text), phone
  (`tel:`) and email (`mailto:`) with the value as text; the text kind and
  an unknown form give the plain value or nothing. Returned as markup so
  the token service does not escape it.
- Both resolve for the asking account. A token-resolver literal cannot
  contain a literal: validation refuses it, and `TokenResolver::resolve()`
  strips any `[literal:...]` from a value saved past validation (fail
  closed, resolves to nothing). An unreadable literal gives nothing under `clear`.
- `hook_token_info` lists every published literal and its `:link` form;
  the list resets on every literal save or delete.

## The tool

`literals:lookup` (Tool API, so MCP through `mcp_server_tool_bridge`;
permission `use literal lookup tool`). Modes by precedence: `key`,
`question` (needs `literals_finder`), `search`. Outputs: `outcome` (match,
ambiguous, none, candidates), `key`, `value`, `label`, `kind` (empty unless
a match) and `candidates` ("key: name - gist" lines, never values). The
tool does not expose a `context` input (an MCP caller could steer the
chooser prompt). Try it:

```bash
ddev drush tool:run literals:lookup --uid=1 --input=key=main_phone --json
ddev drush tool:run literals:lookup --uid=1 --input=question="how do I ring the library" --json
ddev drush tool:run literals:lookup --uid=1 --input=search=phone --json
```

## The finder

`aim` uses the finder as a live recall source and replaces tokens in fact
text; that side is documented in aim's DEVELOPING.md ("Exact values from
`literals` in recall"). `LiteralReader::resolveFound()` and
`replaceTokens()` are the shared pieces.

Flow: candidates (published, the `restricted` filter in the query and
`access('view')` per entity) then the outcome cache (`cache.default`; key =
question + the visible `restricted` flags + a fingerprint of settings, context
and decision model; tag `literal_list`; `none` cached `miss_ttl` seconds;
errors never cached) then the chooser over the whole menu. No decision
model gives `none` / `no_backend`. Chooser rule: argmax `__none__` is none;
a lead over the best other literal under `choice_margin` is ambiguous;
below `match_threshold` is none. Settings are `literals_finder.settings`
(`match_threshold` 0.5, `choice_margin` 0.2, `miss_ttl`, `log_audit`, `log_verbose`,
`chooser_context`, `chooser_instructions`, `max_question_length` 300:
longer questions are cut in `find()`, for every caller). `chooser_context` states who is
asking and whose literals these are ("you" means the library); write it
positively, avoid "not X". A per-call `context` on `find()` replaces the
site default for that lookup. The context is a hint, not a filter: it
changes what "you" means, it never narrows the pool.

The chooser is the module's only model call (plus any model-backed
Guardrails on the gist at save). It is sent the question, the context and
each visible candidate's key and gist; never a value. A model or network
failure is `none` with reason `error`, never a guess.

## Commands

| Command | Does |
| --- | --- |
| `drush literals:find "question" [--uid=N]` | runs the finder as a user (default anonymous), prints outcome, tier and keys, never values |
| `drush literals:eval [file] [--dump=FILE] [--check]` | scores a gold set (default `eval/gold.example.yml`, for the `literals_demo_library` recipe): hit, miss, ambiguous, wrong-confident, unanswerable, time, and each query's chooser scores (top, runner-up, none). `expect` is a key, `none`, `ambiguous` or a list of acceptable answers (`none` in a list counts as acceptable); `as` is a user ID or name (default 0; an unknown name skips the query with a warning). A set's `requires` names the pools (`pools`) and extra site literals (`site`) it needs; eval warns when they are missing, and when a question's only answers are literals the asker cannot see. `--check` only checks the labels, with no model calls: every expected key must exist on the site or in a pool file in `pools/` next to the set, and a bare pool key needs its pool in `requires` (exit 1 on a problem). Clears the cache first. `--dump` writes each row (question, expected, scores, verdict, expected keys the asker cannot see) as JSON |
| `drush literals:sweep DUMPS [--against=DUMPS] [--drop-stale] [--grid]` | replays `--dump` files (a file, or a directory read as one run) through `LiteralChooser::rule()` at each `match_threshold` and `choice_margin` step (`--thresholds`/`--margins`, `from:to:step`), no model calls: right / wrong / false positive / lost / none ok per step, a replay check against the live verdicts, likely stale gold labels (confident picks, of `--pool` prefix keys if given, where the set expects none, an unseen key or an `--older` prefix key; and questions whose only answer the asker cannot see), and calibration (top probability against how often that pick was right). `--against` compares a second run for stability; `--drop-stale` sweeps without the suspects. See "Tuning the thresholds" |

The module ships one gold set, `modules/literals_finder/eval/gold.example.yml`
(for the demo recipe). Other gold sets
and test pools are site data, kept outside the module (this site:
`demo/literals-eval/`). A pool file lists `literals` (id, type, name, gist,
value, optional restricted); `drush literals:pool load|clean FILE` loads or
removes it (runs as user 1; removes exactly the keys the file lists).
Drush runs from the docroot, so give absolute
paths. Synthetic sets are fixtures for regression runs, not a basis for
tuning a real site. All numbers so far are
in-sample (written by the module's author or an agent, not independently);
a set written by someone else is the fair test.

### Tuning the thresholds

`match_threshold` (how sure the chooser must be of its top pick, else
`none`) and `choice_margin` (how far it must lead the runner-up, else
`ambiguous`) depend on the site's own literals and questions; there is no
universal setting, and a value tuned on someone else's set (including
`gold.example.yml`) is not a basis for another site.

1. Write a gold set of real questions, each with the key it should find or
   `none`. Keep a blind set apart. Format: `gold.example.yml`.
2. `drush literals:eval SET --check` (labels only, no model calls), then
   `drush literals:eval SET --dump=DIR/SET.json` per set.
3. `drush literals:sweep DIR`: replays the recorded scores at each setting.
   Use the sweep, not repeated eval runs: it holds the scores fixed, so a
   difference is the setting, not the model's run-to-run noise. The replay
   check must reproduce every live verdict. Re-label the stale-label
   suspects it flags (expectations only, never the question wording) before
   reading the wrong and false-positive counts.
4. Pick a value inside a plateau of right answers, not at its edge. Prefer
   fewer wrong-confident answers over fewer misses.
5. Check calibration, repeat the run once (`--against`) to see which rows
   flip, and re-check on the blind set. Never tune gists or facts to pass a
   set. Real questions (the `Find:` line with `log_verbose`) beat written ones.

On the synthetic library most "wrong" answers were stale labels, the
threshold mattered little either side of 0.5 and the margin not at all.

## Logging

`literals.settings:log_audit` (off by default) logs writes and reads: key,
type, restricted flag, uid and outcome, never the gist or value.
`literals_finder.settings:log_audit` (on) logs finder outcomes (the
`Find:` line); `log_verbose` (off) adds the chooser's top pick, runner-up
and none probabilities and the model ID (`Scores: top a 0.92, next b 0.04,
none 0.007, model m`; a cached outcome repeats its original scores). Keys
and numbers only. Channel `logger.channel.literals`. Chooser calls are
tagged `literals_choose`; add the tag to the site's
`ai_logging.settings:prompt_logging_tags` to keep their prompts and replies
in `ai_log` (literals does not write that config).

## Tests

Run commands are in [CLAUDE.md](CLAUDE.md) "Lint and tests".
`LiteralsKernelTestBase` installs the six types, grants `view literals` to
anonymous and authenticated, makes `member`, `restrictedViewer` and `admin`,
and has `createLiteral()` and `createPage()`.

| Area | Test | Covers |
| --- | --- | --- |
| Links | `Unit/ResolvedLiteralTest` | `href()` allows only http(s), `tel:` and `mailto:` |
| Access | `LiteralAccessTest` | `restricted` matrix, drafts, `edit literals`, query alter |
| Reader | `LiteralReaderTest` | published only, indistinguishable misses, cache metadata, token cycles, audit lines without values, `replaceTokens()`, `resolveFound()` |
| Resolvers | `LiteralResolversTest` | every resolver's validation, access and kind |
| Tokens | `LiteralTokenTest` | `[literal:key]` and `:link`, escaping, restricted, drafts, bubbling |
| Key, Guardrails | `LiteralKeyAndGuardrailsTest` | key rules (unique, pattern, immutable, reserved); a value never reaches model guardrails |
| Search | `LiteralSearchTest` | all words, LIKE escaping, access, limit |
| Moderation | `LiteralModerationTest` | a draft of a published literal is not served |
| Install | `LiteralInstallTest` | shipped config, recipes, clean uninstall, uninstall guard |
| Admin UI | `Functional/LiteralAdminTest` | access, form, type delete guard, list, revisions |
| Submodules | `literals_finder`, `literals_tool`, `literals_chat` | finder cache and outcomes over a fake chooser, gist Guardrails form, tool modes, chat responder |
| aim | aim's `AimRecallLiteralTokensTest`, `aim_tool`'s `AimToolPermissionsTest` | recall reads tokens per viewer; `aim_literal` through `literals.lookup` |

The real chooser is covered only by `drush literals:eval`, on purpose: do
not mock it into a PHPUnit pass.

## Drush scripts

Scripts run as anonymous: set
`\Drupal::currentUser()->setAccount(User::load(1))` first.
`drush php:script` only sees files inside the project (the container
cannot read `/tmp`): put scratch scripts in the site root and delete them.
Do not name a function `t()`.
