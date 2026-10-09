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

- If a module already holds it (Site Settings, Custom Tokens, core tokens),
  the literal points at it with the **token** or **entity** resolver and adds the
  gist and the audience rules. Nothing is copied.
- If nothing holds it, the literal stores it itself with the **text** or
  **url** resolver, so no second module is needed to start.

So `literals` is not another place to keep site-wide values. It is the layer
that finds the right one from a description and decides who sees it.

## Modules

- `literals` - the entity, types, resolvers, audience rules and admin UI. No AI
  needed.
- Search, in `literals` itself - plain search over names, keys and gists
  (every typed word must appear), used by the tool. No AI, no scoring: an agent
  picks from the matches.
- `literals_finder` - find by question: an access filter, an outcome cache,
  then a Decision API choice. Needs `drupal/ai` and a default decision
  provider. Optional: without it the gist is only read by plain word search,
  so a clear name is enough. With it, literals is a small fuzzy-matching
  memory that runs on its own, with no `aim` and no chat model: describe the
  value in words and it finds the exact one.
- `literals_tool` - the `literals:lookup` Tool API / MCP tool: by key, by
  search words or by question (with the finder).
  Needs `tool`.

## More

Design and decisions live in the `aim` module's ADRs, starting at
[ADR-0040](adr/0040-literals-probabilistic-lookup-of-exact-values.md)
and [ADR-0047](adr/0047-literal-candidates-and-tracked-gists.md).
[CLAUDE.md](CLAUDE.md) and [DEVELOPING.md](DEVELOPING.md) have the current build state, rules and commands.

## Drush commands

ddev drush tool:run literals:lookup --uid=1 --input=question="how do I ring the library" --json
ddev drush tool:run literals:lookup --uid=1 --input=search=phone --json
ddev drush tool:run literals:lookup --uid=1 --input=key=main_phone --json
