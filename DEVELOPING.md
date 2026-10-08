# DEVELOPING - literals

Commands, API and runbooks. Rules and gotchas are in [CLAUDE.md](CLAUDE.md);
how it works and its measurements are in [adr/how-it-works.md](adr/how-it-works.md).

## Reading a literal (PHP)

| Call | Returns |
| --- | --- |
| `literals.reader` `read($key, $account)` | the resolved value string, or NULL (missing, draft and not visible are one identical NULL) |
| `literals.reader` `readItem($key, $account)` | a `ResolvedLiteral` (`value`, `label`, `kind` of url/phone/email/text) or NULL; `->href()` gives the safe link target (http(s), `tel:`, `mailto:`) or NULL |
| `$literal->resolveItem($account, $metadata)` | the same, from a loaded `Literal` |
| `literals.search` `search($text, $account, $limit)` | published, visible literals whose name, key or gist contain every typed word (up to 6); candidates, never a decision |
| `literals_finder.finder` `find($question, $account, $context = NULL)` | a `LiteralFindResult`: `match` / `ambiguous` / `none` with `Literal` entities (no values), a tier and a reason |

A finder result holds entities, not values: the consumer calls
`resolveItem()` itself, skips a null or empty value, and downgrades a match
that resolves to nothing to `none`. `LiteralsChatResponder::answer()` and
the tool's `byQuestion()` both do this today; a shared helper is a TODO
(aim's [TODO.md](../aim/TODO.md), "Literals"). Pass `CacheableMetadata` to
collect cache metadata; the reader bubbles `literal_list`, the entity's
tags and the access contexts (`user.permissions`,
`user.roles:authenticated`).

## Tokens

- `[literal:key]` - the value as plain text (core escapes it; the caller
  controls the render).
- `[literal:key:link]` - HTML: `<a>` for url (label as text), phone
  (`tel:`) and email (`mailto:`) with the value as text; the text kind and
  an unknown form give the plain value or nothing. Returned as markup so
  the token service does not escape it.
- Both resolve for the asking account (a token-resolver literal passes
  `$options['literals_account']` so a nested token is not evaluated for the
  session user). An unreadable literal gives nothing under `clear`.
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

Flow: candidates (published, audience filter in the query and
`access('view')` per entity) then the outcome cache (`cache.default`; key =
question + audience set + admin flag + a fingerprint of settings, context
and decision model; tag `literal_list`; `none` cached `miss_ttl` seconds;
errors never cached) then the chooser over the whole menu. No decision
model gives `none` / `no_backend`. Chooser rule: argmax `__none__` is none;
a lead over the best other literal under `choice_margin` is ambiguous;
below `match_threshold` is none. Settings are `literals_finder.settings`
(`match_threshold` 0.5, `choice_margin` 0.2, `miss_ttl`, `log_audit`,
`chooser_context`, `chooser_instructions`). `chooser_context` states who is
asking and whose literals these are ("you" means the library); write it
positively, avoid "not X". A per-call `context` on `find()` replaces the
site default for that lookup.

## Commands

| Command | Does |
| --- | --- |
| `drush literals:find "question" [--uid=N]` | runs the finder as a user (default anonymous), prints outcome, tier and keys, never values |
| `drush literals:eval [file]` | scores a gold set (default `eval/gold.seed.yml`): hit, miss, ambiguous, wrong-confident, unanswerable, time. `expect` is a key, `none`, `ambiguous` or a list of acceptable answers (`none` in a list counts as acceptable); `as` is a user ID (default 0). Clears the cache first |

Gold sets are in `modules/literals_finder/eval/`: `gold.seed.yml`,
`gold.paraphrase.yml`, `gold.blind.yml`, `gold.agentblind.yml` (written by
a cold agent from the menu alone), `gold.stress.yml`, `gold.pool100.yml`.
Pool seeders: `scale.gen.php.txt` (up to 225 synthetic `zz_` literals;
instructions in its header), `pool100.seed.php.txt` (90 `pl_*` literals;
`POOL_CLEAN=1` removes them), `stress.seed.php.txt`. All numbers so far are
in-sample (written by the module's author or an agent, not independently);
a set written by someone else is the fair test.

## Logging

`literals.settings:log_audit` (off by default) logs writes and reads: id,
key, type, audience, uid and outcome, never the gist or value.
`literals_finder.settings:log_audit` (on) logs finder outcomes. Channel
`logger.channel.literals`.

## Drush scripts

Scripts run as anonymous: set
`\Drupal::currentUser()->setAccount(User::load(1))` first.
`drush php:script` only sees files inside the project (the container
cannot read `/tmp`): put scratch scripts in the site root and delete them.
Do not name a function `t()`.
