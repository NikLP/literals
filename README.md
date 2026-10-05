# Literals

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
- `literals_finder` - find by question: an access filter, an optional
  embedding gate, then a Decision API choice. Needs `drupal/ai`.

## More

Design and decisions live in the `aim` module's ADRs, starting at
[ADR-0040](../aim/adr/0040-literals-probabilistic-lookup-of-exact-values.md)
and [ADR-0047](../aim/adr/0047-literal-candidates-and-tracked-gists.md).
[HANDOFF-literals.md](HANDOFF-literals.md) has the current build state.
