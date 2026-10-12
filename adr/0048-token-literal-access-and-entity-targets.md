# ADR-0048: Token literals - resolve for the asking account, and name an entity target for entity data

**Status:** Resolved 2026-10-12. Part A built 2026-10-05. Part B (a target
for entity tokens) dropped: entity data is reached through the `entity`,
`field` and `content` resolvers instead, each with its own view-access check.
**Date:** 2026-10-05
Relates to [ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md)
("The parts, settled").

## Context

The `token` literal kind stored a string such as `[site:name]` and replaced
it at read time. Two problems:

1. `resolve($literal, $account)` ignored `$account`: `Token::replace()` ran
   with no data, so `[current-user:*]` (which core loads from
   `\Drupal::currentUser()`, not from token data) resolved for the session
   user. An agent, queue worker or Drush call acting for account B inside
   session A would get A's data cached under B's context. Resolution was not
   a pure function of its arguments.
2. Access to the data a token reveals was never checked. It did not bite
   only because no entity data was passed, so `[node:*]` and `[user:*]`
   resolved empty. The `entity` and `url` kinds check view access on their
   target; `token` did not. The `restricted` flag gates the *viewer against the
   literal*, not the *viewer against the data*, so an author with
   `edit literals` could publish whatever a token reveals to any viewer.

`AccountSwitcherInterface::switchTo()` would have fixed 1 by making the
session lie. Rejected: it hides the dependency instead of removing it, and
leaves 2 untouched.

## Decision

### Part A (built): resolution is a function of `($literal, $account)`

- `[user:...]` always means the asking account: `resolve()` passes
  `['user' => User::load($account->id())]` and adds the `user` cache context.
- `validate()` rejects `current-user` (session-bound) and any token type
  whose token info sets `needs-data`, other than `user` (the kind cannot
  supply the entity). The test is the token system's own flag, not a
  hardcoded list.
- Net effect: a token literal can only reveal global values (`site`,
  `current-date`) and the asking account's own data. Nothing crosses an
  account boundary, by construction rather than by author trust.

### Part B (dropped): entity data via an explicit target on the token kind

Proposed: a `target` (`entity_type:id`) on token literals so `[node:title]`
could resolve with an access check, with three options for where the target
lives (a second base field, a composite value, a per-type setting). Not
built, and no longer needed: the `entity` resolver returns a link to an
entity, the `field` resolver returns one field's value from one entity
(`entity_type:id:field_name`, access is the intersection of the literal's
rule and the field's view access), and the `content` resolver covers content
entities. The token kind stays limited to global values and the asking
account's own data; `validate()` still rejects the rest.

## Consequences

- Part A needs no migration; the one existing token literal (`[site:name]`)
  is unaffected. `current-user` literals saved earlier would now fail
  validation on next save and resolve without that token's data.
- Callers must pass and bubble `CacheableMetadata`, or per-user resolved text
  can be cached for the wrong user. `LiteralReader` does; a new caller of
  `Literal::resolve()` must too.
