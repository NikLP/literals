# SUGGESTIONS.md - open cleanups in literals

From a review on 2026-10-10. Most items were done the same day; what is
left is below, then a short record of what was decided against so it is
not proposed again.

## Open

1. **Say what the finder, tool and chat modules are for.** They serve two
   real cases: the one-shot question gate in front of aim, and literals as a
   standalone fuzzy value store. README.md and CLAUDE.md should present them
   as that, under "Extras", and list the base module's actual surface first
   (entity, resolvers, view access, reader, tokens). `literals_tool` and
   aim's `aim_literal` tool overlap; decide which one an aim site enables and
   say so in both READMEs.
2. **One `field` resolver instead of a bridge per module (deferred, to
   revisit).** Site Settings and Config Pages both store values as fields on
   entities. Extend the `entity` resolver (or add a `field` resolver) to
   accept `entity_type:id:field_name`, returning the field's string value
   with the field's own access check (`$item->access('view', $account)`).
   Access is the intersection: the literal's own view rule and the field's
   view access. Document that choice, since it is where the two access
   models disagree.
3. **How much revision machinery (design question).**
   `EditorialContentEntityBase` brings revisions, owner, changed, published
   status. Keep it if history or Content Moderation will really be used;
   otherwise a plain content entity with a `status` field is lighter.

## Decided against

- **Stop listing literals in `hook_token_info`.** The listing feeds the
  token browser on token-type literals, which is how one literal is
  composed from another. The leak concern (restricted names in the browser)
  went with the audience system: only editors use that form, and they see
  every literal anyway.
- **Strip newlines and backticks from Markdown link labels.** The href is
  built separately from a scheme-limited value, `[`, `]` and `\` are
  already escaped, and labels are one-line editor fields. Worst case is
  garbled Markdown, not injection.
- **Constructor injection in `LiteralForm`.** It does not use the resolver
  manager; setting `keyValueFactory` in `create()` is the standard pattern
  for entity forms. `Literal::getResolverPlugin()` stays a static call
  (entities cannot take constructor injection).
- **An opaque ID in tokens instead of the key.** The key is now the
  entity ID itself (a machine name, as core's Workspace does) and cannot
  change, so tokens stay readable and stable.
