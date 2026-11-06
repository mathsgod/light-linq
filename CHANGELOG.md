# Changelog

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
