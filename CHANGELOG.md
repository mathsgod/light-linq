# Changelog

## v1.3.0 — 2026-11-09

- Add Enumerable `order`, `orderDescending`, `asEnumerable`, `longCount`,
  `tryGetNonEnumeratedCount`, `cast`, `ofType`, `sequence` and `infiniteSequence`.
- Support indexed callbacks in `select`, `where`, `selectMany`, `skipWhile` and
  `takeWhile`, including Queryable closure translation through `IndexExpression`.
- Add Enumerable `selectMany` and `groupBy` result selectors.
- Expand Queryable with append/prepend, reverse, default-if-empty, while/tail
  operations, key-based set operations, min/max-by, right joins, identity ordering,
  element access, count-by, aggregation and grouped aggregation.
- Add `Queryable::asQueryable()` and `Enumerable::asQueryable()` for deferred
  in-memory queries, preserving an existing IQueryable and its provider.
- Translate closures for Queryable ordering, grouping, aggregate selectors,
  terminal predicates and join keys. Aggregation supports accumulator closures,
  explicit seeds and an optional final result selector.
- Match .NET paging behavior for nonpositive `skip` and `take` counts. Ignore null
  values in Enumerable averages; nonempty all-null sequences return null, while
  empty Enumerable averages continue to throw `UnderflowException`.
- Document PHP numeric/type semantics, closure restrictions and provider support.
  SQL/custom providers must implement the new operations and expression nodes or
  reject them explicitly; no automatic client-side fallback is introduced.
- Expand validation to 383 tests and 1,411 assertions, with CI on PHP 8.3–8.5.

Compatibility notes: custom implementations of `IEnumerable` and `IQueryable`
must implement the added methods. Enumerable `average()` now has a nullable return
type. `cast()` checks types without coercion and rejects null; sequence generators
use PHP `int|float` limits. `aggregateBy()` currently supports fixed seeds only.

## v1.2.0 — 2026-11-06

- Translate arithmetic `+ - * / %` into nested `BinaryExpression` nodes.
- Support `CoalesceExpression` (`??`) and `ConditionalExpression` (full ternary),
  including `Expr::coalesce()` and `Expr::condition()` helpers.
- Support literal starts-with, ends-with and contains operations through
  `StringMatchExpression`.
- Support `strtolower`, `strtoupper`, `trim`, `strlen`, `abs` and `round` through
  `ScalarFunctionExpression`, with validated positional signatures.
- Execute the new nodes in `InMemoryQueryProvider`, including short-circuiting,
  null propagation and correlated outer-field binding.
- Document the closure grammar, provider responsibilities and SQL/PHP semantic
  boundaries. Custom providers must implement new node kinds or reject them.
