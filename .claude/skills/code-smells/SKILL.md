---
name: code-smells
description: Hunt for code smells in PHP source using the canonical smell catalogs (Fowler's Refactoring, Kent Beck, Robert C. Martin's Clean Code, Wake's Refactoring Workbook). Use when asked to check for code smells, review code quality, or before committing. Reports findings by severity; does not auto-fix unless asked.
---

# Code Smell Hunter

Systematically inspect PHP code for the well-known code smells drawn from the
most-cited refactoring literature. Report findings; only refactor when the user
asks.

## When to run

- User asks to "check for code smells", "hunt smells", "review code quality".
- Invoked by the pre-commit gate (see below) — in that mode, scan only the
  files staged for commit and fail loudly on new **high**-severity smells.

## Scope

Scan `includes/` and `admin/` PHP files (the plugin source). In pre-commit
mode, restrict to staged `.php` files:

```
git diff --cached --name-only --diff-filter=ACM -- '*.php'
```

Skip `vendor/`, `tests/`, and generated files.

## The catalog

Group findings under these headings. For each, the trigger to look for:

### Bloaters
- **Long Method** — a method that does too much. Heuristic: > ~40 lines, or
  many local variables, or several distinct responsibilities. (Fowler)
- **Large Class** — a class with too many fields/methods/responsibilities.
- **Long Parameter List** — > 3–4 params; suggests Introduce Parameter Object.
- **Data Clumps** — the same group of variables passed around together.
- **Primitive Obsession** — primitives standing in for a concept (e.g. string
  status codes, arrays as ad-hoc records).

### Object-Orientation Abusers
- **Switch/Conditional Statements** — repeated `switch`/`if-elseif` on the same
  type field; consider polymorphism.
- **Temporary Field** — a field only set/used in some circumstances.
- **Refused Bequest** — subclass ignores most of what it inherits.

### Change Preventers
- **Divergent Change** — one class changed for many unrelated reasons.
- **Shotgun Surgery** — one change forces edits across many classes.
- **Parallel Inheritance Hierarchies**.

### Dispensables
- **Duplicated Code** — the #1 smell (Fowler/Beck). Copy-pasted blocks.
- **Dead Code** — unused methods, variables, parameters, `require`s.
- **Speculative Generality** — abstraction with no current use.
- **Comments (as deodorant)** — comments explaining bad code rather than code.
- **Data Class** — a class with only getters/setters and no behavior.

### Couplers
- **Feature Envy** — a method more interested in another class's data.
- **Inappropriate Intimacy** — classes reaching into each other's internals.
- **Message Chains** — `a->b()->c()->d()`.
- **Middle Man** — a class that only delegates.

### Clean Code (Martin) additions
- **Magic Numbers / Strings** — unexplained literals; extract constants.
- **Boolean/flag arguments** — a bool param that splits method behavior.
- **Poor names** — non-intention-revealing identifiers.
- **Deep nesting** — arrow-code; prefer guard clauses / early return.
- **Inconsistent abstraction levels** within a method.

## Procedure

1. Determine the file set (full scan or staged-only).
2. Read each file. Use `grep`/`awk` to measure: method lengths, param counts,
   nesting depth, duplicated blocks. Read the code to judge the semantic
   smells (Feature Envy, Divergent Change, etc.).
3. Rank each finding:
   - **high** — duplication, long method (>60 lines), long param list (>5),
     dead code, deep nesting (>4). Clear, mechanical, fixable.
   - **medium** — feature envy, primitive obsession, magic values, data clumps.
   - **low** — naming, comments, speculative generality.
4. Report as a table grouped by severity, each with `file:line`, the smell
   name, and a one-line suggested refactoring (name Fowler's refactoring where
   it applies, e.g. "Extract Method", "Introduce Parameter Object").
5. Do **not** modify code unless the user explicitly asks. If they do, make one
   refactoring at a time and re-run `vendor/bin/phpunit` after each.

## Pre-commit mode

Known-debt files listed in `EXEMPT` (in [bin/smell-lint.php](../../../bin/smell-lint.php))
are downgraded from blocking to `warn (known debt)` — currently only
`admin/class-spa-settings.php`, a large untested admin class awaiting a
dedicated refactor. Keep that list shrinking; remove a file once it's clean.

When run as the commit gate, output must be machine-simple:
- Print each **high**-severity finding as `SMELL <file>:<line> <name>`.
- Exit non-zero if any high-severity smell is found in staged files; otherwise
  print `no high-severity smells` and exit 0.
- medium/low findings are printed as warnings but do not block the commit.
