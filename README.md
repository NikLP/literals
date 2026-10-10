# Literals (experimental, probably doesn't work)

Tell the site what you want in words, and it finds the exact value, and
shows it only to the people allowed to see it. Semantic value lookup?

A literal is a small record: a **key**, an exact **value** (a phone number,
a URL, a token such as `[site:login-url]`, a reference to an entity), and a
**gist**, a plain-language description such as "the page where members sign
in". The gist is the only part that is ever matched. The value is returned
exactly as stored, never guessed, paraphrased or sent to a language model.

## Where it fits

Wherever you want to put a value somewhere and nothing readily hands it to
you. A form field that wants "our phone number", a block, a chat answer, a
document: whatever says what it wants in words is matched to the right
literal, and the value comes back if the viewer is allowed to see it.

The value does not have to live in `literals`:

- If a module already holds it (Site Settings, Config Pages, Custom Tokens,
  core tokens, a node field), the literal points at it with the **token**,
  **field** or **entity** resolver and adds the gist and the access rules.
  Nothing is copied. A **field** literal (`node:12:field_phone`) needs view
  access to the entity and to the field, as well as the literal's own rule.
- If nothing holds it, the literal stores it itself with the **text** or
  **url** resolver, so no second module is needed to start.

So `literals` is not another place to keep site-wide values. It is the layer
that finds the right one from a description and decides who sees it.

## Install

Walked through on a fresh Drupal 11.4 `standard` install on 2026-10-09. The
`literals` module itself needs no AI, no contrib module and no other custom
module; the finder and the tool are extras (see below).

**Requirements:** Drupal ^11, PHP ^8.3. The core modules `user` and
`views` are enabled for you when you enable `literals`.

1. **Enable it.**

   ```bash
   ddev drush en literals -y
   ```

   This installs the six literal types (`text`, `phone`, `url`, `token`,
   `entity`, `field`), `literals.settings` (audit logging, at
   `/admin/config/literals/settings`) and the admin list view. It grants
   nothing and creates no literals.

2. **Grant the view permissions.** `view literals` sees every literal not
   marked restricted; `view restricted literals` sees all of them (a
   literal's **Restricted** checkbox). Nothing is granted on install (a
   literal nobody can see fails closed), so choose deliberately. The
   `literals_base` recipe grants `view literals` to everyone and `view
   restricted literals` to signed-in users:

   ```bash
   ddev drush recipe modules/custom/literals/recipes/literals_base
   ```

   The path is relative to the `web` directory. Without DDEV:
   `php core/scripts/drupal recipe path/to/recipe`. Or grant the permissions
   by hand at `/admin/people/permissions/module/literals`.

3. **Optional: load the demo literals.** Eight literals for a fictional
   library (four phones, login, password reset, my account, site name),
   imported as default content. Re-applying skips ones that already exist.

   ```bash
   ddev drush recipe modules/custom/literals/recipes/literals_demo_library
   ```

4. **Check it.** As admin these should load: `/admin/content/literals`
   (the list), `/admin/structure/literal-types`. Anonymous gets 403 on
   both.
   A literal's own page, `/admin/content/literals/{id}`, is its edit form.

### literals_tool

**Not required by `aim`.** `aim` surfaces the lookup itself (its own
`aim_literal` tool and `[literal:key]` token replacement in `recall()`), so
it needs only base `literals`. Enable `literals_tool` to expose the lookup as
a standalone Tool API / MCP tool for callers that do not go through `aim`.

Walked through on a fresh site:

5. **Enable.** `ddev drush en literals_tool -y` (pulls in `tool` and core
   `serialization`).
6. **Grant.** The tool has its own permission, `use literal lookup tool`;
   nobody has it on install (only uid 1 and administrators). Grant it to
   the roles that should call the tool; the view permissions still apply
   on top.
7. **Check it** with the Drush commands below. As uid 1, `key=main_phone`
    returns the value and `search=phone` returns four candidates. An
    anonymous caller with the permission sees three (the restricted
    `staff_line` is filtered out) and `key=staff_line` returns `none`.
    Without the permission the call fails with `access_denied`.

### literals_finder

8. **Enable.** `ddev drush en literals_finder -y` (pulls in `ai` and `key`).
9. **Provider.** The finder needs a default `decision` provider. Here that
    is Typesafe Jev (`drupal/ai_provider_typesafeai`, hosted: demo data
    only): enable `ai_provider_typesafeai`, create a `key` entity that reads
    the API key from a file outside the docroot, set it as the provider's
    `api_key`, and set `ai.settings` `default_providers.decision` to
    `typesafeai` / `jev-latest`. Any other Decision API provider works.
10. **Check it** (as uid 1): `question="how do I ring the library"` returns
    `main_phone`, `"who do I call about a fine"` returns `accounts_phone`,
    `"book a study room by phone"` returns `reservations_phone`, and
    `"what is the capital of France"` returns `none`.

### MCP

11. **Enable.** `ddev drush en mcp_server mcp_server_tool_bridge -y`.
    `literals_tool` ships the `literals_lookup` MCP tool config, so there is
    nothing to add.
12. **Grant** `access mcp server` and `use literal lookup tool` to the
    callers' role.
13. **Check it.** `POST /mcp` needs a session: `initialize`, take the
    `Mcp-Session-Id` header, then `tools/list` lists
    `tool_api__literals_lookup` and `tools/call` on that name returns the
    same results as Drush, filtered by what the caller may see. Without
    auth it answers "Authentication required". Remote clients without a
    Drupal session need OAuth (see `aim`'s DEVELOPING.md, "MCP OAuth");
    that part was not walked through here.

## Modules

`literals` itself needs no AI and no contrib module. It provides:

- the `literal` entity (key, value, gist, a **Restricted** checkbox) and its
  types, each read by a resolver (`text`, `url`, `token`, `entity`,
  `field`); every edit makes a revision, and Content Moderation can drive
  drafts;
- view access: `view literals` and `view restricted literals`;
- the reader and the `[literal:key]` / `[literal:key:link]` tokens;
- plain search over names, keys and gists (every typed word must appear;
  no scoring, an agent picks from the matches);
- the `literals.lookup` service: one lookup by key, search words or
  question, which every lookup tool wraps;
- the admin UI.

### Extras

They serve two real cases: a one-shot question gate in front of `aim`, and
literals as a standalone fuzzy value store.

- `literals_finder` - find by question: an access filter, an outcome cache,
  then a Decision API choice. Needs `drupal/ai` and a default decision
  provider. Without it the gist is only read by plain search, so a clear
  name is enough. With it, literals is a small fuzzy-matching memory that
  runs on its own, with no `aim` and no chat model: describe the value in
  words and it finds the exact one.
- `literals_tool` - the `literals:lookup` Tool API / MCP tool, for sites
  running `literals` **without** `aim`. An aim site uses `aim_tool`'s
  `aim_literal` instead: the same `literals.lookup` service under aim's
  name, so the two never both need enabling.
- `literals_chat` - a chat responder over the finder; a demo surface, likely
  to be scrapped.

## More

Design and decisions live in the `aim` module's ADRs, starting at
[ADR-0040](adr/0040-literals-probabilistic-lookup-of-exact-values.md)
and [ADR-0047](adr/0047-literal-candidates-and-tracked-gists.md).
[CLAUDE.md](CLAUDE.md) and [DEVELOPING.md](DEVELOPING.md) have the current build state, rules and commands.

## Drush commands

These need `literals_tool` and `tool` enabled; the `question` mode also needs
`literals_finder`.

ddev drush tool:run literals:lookup --uid=1 --input=question="how do I ring the library" --json
ddev drush tool:run literals:lookup --uid=1 --input=search=phone --json
ddev drush tool:run literals:lookup --uid=1 --input=key=main_phone --json
