# TESTS.md - test gaps in literals

Handoff for whoever writes the missing tests. Reviewed 2026-10-10 by reading
the code and the test names; updated the same day after view access moved
to the `restricted` flag and keys became immutable.

## What exists

Kernel tests only, 74 methods. Base class `LiteralsKernelTestBase` installs
the five types, grants `view literals` to the anonymous and authenticated
roles, makes users (`member`, `restrictedViewer`, `admin`), and has
`createLiteral()` and `createPage()`.

| Area | File | Covers |
| --- | --- | --- |
| Access | `LiteralAccessTest` | `restricted` flag matrix, no view permission, drafts, `edit literals` covers create/update/delete, query alter |
| Reader | `LiteralReaderTest` | published only, indistinguishable misses, cache metadata, token cycles, audit logs, `replaceTokens()`, `resolveFound()` |
| Resolvers | `LiteralResolversTest` | text/phone validation, url access, entity incl. deleted target, token validation and per-account resolve |
| Tokens | `LiteralTokenTest` | `[literal:key]` and `:link`, restricted flag, drafts, bubbling, token info follows saves |
| Key, Guardrails | `LiteralKeyAndGuardrailsTest` | key as ID: uniqueness (validation and primary key), machine-name pattern, immutable, reserved keys, gist/value rejection, value never reaches model guardrails |
| Search | `LiteralSearchTest` | name/key/gist match, all words, edge input, access, limit |
| Submodules | `literals_finder`, `literals_tool`, `literals_chat` | finder cache and outcomes, tool modes, chat responder |

The real chooser (Decision API) is covered only by `drush literals:eval`.
That is deliberate; do not try to mock it into a PHPUnit "pass".

## Gaps, in priority order

### 1. Link and Markdown output (unit)

`ResolvedLiteral::href()` and the Markdown branch of
`LiteralReader::replaceTokens()` build links from stored text and have no
dedicated test. Add `tests/src/Unit/ResolvedLiteralTest.php` and extend
`LiteralReaderTest`:

- `href()`: phone strips everything but `+` and digits; email gets `mailto:`;
  url allows only `http(s)` (assert `javascript:`, `data:`, `//host` give
  NULL); text gives NULL.
- Markdown link: a label containing `[`, `]` or `\` cannot break out of the
  `[label](href)` form; an href with spaces and parentheses is
  percent-encoded. (Newlines and backticks were judged not worth stripping:
  labels are one-line editor fields, values are format-validated.)
- Injection: a resolved value containing `[literal:other]` is not scanned
  again (the docblock promises this).
- The `:link` HTML path in `LiteralsHooks::link()`: label and href are
  escaped (`"`, `<`, `&`).

### 2. Functional tests (the module has none)

Add `tests/src/Functional/`. Needs `BrowserTestBase`, theme `stark`.

- **Admin pages 403 for anonymous and a plain authenticated user, 200 for
  admin:** `/admin/content/literals`, `/admin/structure/literal-types`,
  `/admin/config/literals`. The README states this; nothing proves it.
- **Literal form:** create via `/admin/content/literals/add/text`; the key is
  generated from the name (machine name widget); a duplicate key and a
  reserved key (`url`, `name`) show a form error; the key field is disabled
  on edit; a form-level key error lands on the key element; a bad phone value shows the
  resolver message; the Guardrails error appears on the gist field.
- **Type form and type delete guard:** a type with literals shows the "used
  by N literals" message and no delete button.
- **List view:** a user holding only `view literals` sees no restricted
  literals in `/admin/content/literals` (needs a role with the admin-view
  access the view requires; check `views.view.literals`). Exercises the
  `views` branch of `queryAlter()`.
- **Revision UI:** `show_revision_ui` is on; one edit produces a second
  revision and the revisions tab loads.

### 3. Install, recipes, uninstall (Kernel or Functional)

The README says it was walked through by hand on a fresh `standard` install.
Nothing keeps that true.

- Enable `literals` on a minimal site; assert the five types,
  `literals.settings` and the view exist and the config validates against
  schema (`config.typed` `->validate()`, as CLAUDE.md describes).
- Apply `recipes/literals_base` and assert the role grants; apply
  `recipes/literals_demo_library` and assert eight literals; apply it twice
  and assert no duplicates ("skips ones that already exist").
- Uninstall: with literals present, `drush pmu` is expected to need the
  files; at least test that the module uninstalls cleanly once its
  literals are deleted.

### 4. Smaller Kernel gaps

- `TextResolver` `int` and `email` validation (only `phone` is tested).
- `EntityResolver`: unpublished target for an account without access gives
  NULL; a target type with no canonical link template gives NULL; label falls
  back to the literal name.
- `TokenResolver`: anonymous viewer (uid 0) with a `[user:...]` token (the
  `User::load(0)` path); an unsupported `[current-user:...]` token is rejected.
- `LiteralSearch`: `%`, `_` and a backslash in a word are matched literally,
  not as LIKE wildcards; a word that is a substring of the key only.
- `LiteralReader::resolveFound()`: ambiguous outcome keeps all resolvable
  items and drops only the unresolvable ones.
- `replaceTokens()` with `$withholdIfRedacted` and a mix of readable and
  unreadable tokens returns NULL, not a partial string.
- Content Moderation: the docs promise a draft can wait for promotion. Either
  test it with the `content_moderation` module enabled (draft revision not
  served, published revision still is) or remove the claim from the docs.
- `LiteralsHooks::literalInsert/Update/Delete` reset token info (the
  token-info tests cover part of this) and write audit lines with no gist or
  value (only update/delete audit is untested; `testAuditLogsOmitValues` may
  cover insert).
- Access handler memoization: CLAUDE.md warns about it. A short test that
  `resetCache()` is enough after a `restricted` change would document the
  workaround.

### 5. aim integration (belongs in aim's test suite)

`recall()` calling `LiteralReader::replaceTokens()` and the `aim_literal` tool
are aim code. Check aim's own tests cover: a fact with a token the viewer
cannot see is redacted or withheld per `show_redacted_facts`; the same fact
reads differently for two viewers; a rotated or deleted literal key does not
break `recall()`. If those live only in manual demo runs, add them there, not
here.

## Not worth testing

- `ginContentFormRoutes()` (a static list).
- The chooser's judgment (use `drush literals:eval`).
- Cosmetic list-builder columns.

## Running

```bash
ddev exec "cd /var/www/html && SIMPLETEST_DB='sqlite://localhost/sites/default/files/literals-test.sqlite' SIMPLETEST_BASE_URL=http://localhost vendor/bin/phpunit -c web/core web/modules/custom/literals/tests"
```

Functional tests need a reachable base URL under DDEV; if
`SIMPLETEST_BASE_URL=http://localhost` does not work from inside the web
container, use the project's `.ddev.site` URL. Run phpcs on the new files
before calling the work done.
