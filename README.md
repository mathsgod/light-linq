# php-linq

An experimental LINQ-style query library for PHP 8.3+. Unlike callback-only
collection wrappers, a query is represented by an expression tree and executed
by a query provider.

## 使用文件

繁體中文使用指南：[docs/README.md](docs/README.md)。包含安裝入門、Enumerable、分組與 join、Queryable／Expr、集合類別範例；XML 文件已移至 light-xml。

## LINQ to Objects

`Enumerable` provides lazy, generator-based processing with ordinary PHP
callbacks:

```php
use Light\Linq\Enumerable;

$names = Enumerable::from($users)
    ->where(fn (User $user): bool => $user->active)
    ->orderBy(fn (User $user): int => $user->age)
    ->select(fn (User $user): string => $user->name)
    ->take(10)
    ->toArray();
```

Streaming operators include `where`, `select`, `selectMany`, `skip`, `take`,
`skipWhile`, `takeWhile`, `skipLast`, `distinct`, `append`, `prepend`, `concat`, and
`chunk`. Operators which require a complete view of the sequence, such as
`orderBy`, `reverse`, and `takeLast`, buffer their input when enumerated.

The API also includes the .NET-style aggregate and set operators `min`, `max`,
`minBy`, `maxBy`, `except`, `intersect`, `union`, and `shuffle`. Relational
`join` combines matching outer and inner elements by key:

```php
$summaries = Enumerable::from($users)->join(
    $orders,
    fn (array $user): int => $user['id'],
    fn (array $order): int => $order['userId'],
    fn (array $user, array $order): array => [
        'name' => $user['name'],
        'total' => $order['total'],
    ],
);
```

Call `asEnumerable()` on an `IQueryable` to execute the provider-backed part
first and continue with callback-based, in-memory operators.

`leftJoin()` retains every left element, supplying null for an unmatched right
element. `rightJoin()` retains every right element, supplying null for an
unmatched left element. Both accept the same selectors and optional
`EqualityComparer` as `join()`; the result selector always receives left then
right. See the [outer join examples](docs/grouping-and-joins.md#leftjoin-與-rightjoin保留未匹配資料).

### Grouping and grouped joins

`Enumerable::groupBy($keySelector, $comparer = null)` returns iterable,
countable `Grouping` objects with public `key` and `items` properties:

```php
$groups = Enumerable::from($orders)
    ->groupBy(fn (array $order) => $order['customerId']);

foreach ($groups as $group) {
    $customerId = $group->key;
    $total = Enumerable::from($group)->sum(fn (array $order) => $order['total']);
}
```

Groups appear in first-key-occurrence order, and elements within each group
retain source order. Execution is deferred; the source is buffered completely
before the first group is returned. An empty source produces no groups.

`groupJoin()` produces one result per outer element, including duplicates and
elements with no matches. Its result selector receives the outer element and
an `IEnumerable` of matching inner elements, in inner-source order:

```php
$customersWithOrders = Enumerable::from($customers)->groupJoin(
    $orders,
    fn (array $customer) => $customer['id'],
    fn (array $order) => $order['customerId'],
    fn (array $customer, $matches) => [
        'customer' => $customer,
        'orders' => $matches->toArray(),
    ],
);
```

Unmatched elements receive an empty sequence. Execution is deferred; the inner
source is indexed once per enumeration, when the first outer element is read,
and outer elements are streamed. Empty outer sources do not read the inner.
Both operators accept an optional `EqualityComparer` (the final argument).
Default key comparison follows the existing Enumerable equality rules: types
are distinct, arrays compare strictly and objects compare by identity. Null
keys form a group and match null keys in grouped joins, as in Enumerable
`join()`; provider-backed SQL joins have different null semantics.
Re-enumerating rebuilds the groups/index and requires replayable sources;
use `Enumerable::defer()` to create fresh generators when needed.

### Skipping trailing elements

```php
$trimmed = Enumerable::range(1, 5)->skipLast(2)->toArray(); // [1, 2, 3]
```

`skipLast($count)` streams with a queue holding at most `$count + 1` elements.
It reads ahead by `$count` elements before yielding results. Zero preserves the
whole sequence; a count at least as large as the source length produces an empty
result. Negative counts throw `InvalidArgumentException` immediately.

### Creating sequences

```php
$empty = Enumerable::empty()->toArray(); // []
$integers = Enumerable::range(5, 3)->toArray(); // [5, 6, 7]
$repeated = Enumerable::repeat('A', 3)->toArray(); // ['A', 'A', 'A']
```

`range($start, $count)` takes a count, not an end value. Both `range()` and
`repeat()` generate elements lazily and support repeated enumeration. A zero
count returns an empty sequence; a negative count throws
`InvalidArgumentException` immediately. Ranges exceeding `PHP_INT_MAX` are
also rejected immediately. `repeat()` reuses the supplied value, including
object identity; it does not clone objects.

### Sequence defaults, indexing, pairing and equality

```php
$fallback = Enumerable::from([])->defaultIfEmpty(0)->toArray(); // [0]
$names = Enumerable::from(['Ada', 'Bob', 'Cat']);
$second = $names->elementAt(1); // 'Bob'
$missing = $names->elementAtOrDefault(5, default: 'Unknown'); // 'Unknown'
$pairs = $names->zip([90, 80], fn ($name, $score) => [$name, $score])->toArray();
// [['Ada', 90], ['Bob', 80]]
$equal = Enumerable::from([1, 2])->sequenceEqual([1, 2]); // true
```

`defaultIfEmpty()` supplies one fallback element only when the source is empty;
its default fallback is null. `elementAt()` uses a zero-based sequence position,
ignoring source keys, and throws `OutOfBoundsException` for a negative or missing
index. `elementAtOrDefault()` returns its default instead, including for negative
indices. Actual null and false elements are preserved.

`zip()` is deferred and requires a result selector; it stops at the shorter
source. `sequenceEqual()` executes immediately and checks values, order and
length using the existing default equality comparer, or an optional
`EqualityComparer`. These methods operate on `Enumerable`; use `asEnumerable()`
to continue a provider-backed query with them.

### Key-based set operations and aggregation

```php
$users = Enumerable::from([
    ['id' => 1, 'city' => 'HK'],
    ['id' => 2, 'city' => 'Tokyo'],
    ['id' => 3, 'city' => 'HK'],
]);
$key = fn ($user) => $user['id'];
$excluded = $users->exceptBy([1, 3], $key)->toArray(); // user 2
$included = $users->intersectBy([1, 3], $key)->toArray(); // users 1 and 3
$merged = $users->unionBy([['id' => 4, 'city' => 'HK']], $key)->toArray();

$counts = $users->countBy(fn ($user) => $user['city'])
    ->select(fn ($pair) => [$pair->key, $pair->value])->toArray();
// [['HK', 2], ['Tokyo', 1]]
$totals = $users->aggregateBy(
    fn ($user) => $user['city'],
    0,
    fn ($sum, $user) => $sum + $user['id'],
)->select(fn ($pair) => [$pair->key, $pair->value])->toArray();
// [['HK', 4], ['Tokyo', 2]]
```

`exceptBy()` and `intersectBy()` take a second sequence of **keys**;
`unionBy()` takes a second sequence of **elements**. All three keep at most
one element per key, preserving the first occurrence and source order.

`countBy()` and `aggregateBy()` return `KeyValuePair` sequences in first-key
order, preserving key types without PHP array-key conversion. They defer
execution but consume the entire source before returning the first result,
retaining one accumulator per key. Empty sources return no pairs.
`aggregateBy()` accepts a seed value, not a seed factory; object seeds are
shared unless the accumulator creates new objects instead of mutating them.
All five operators accept an optional final `EqualityComparer` argument.
See the [Enumerable guide](docs/enumerable.md) for execution and equality rules.

## Generic collections

Collection types now live in the standalone [mathsgod/light-collections](https://github.com/mathsgod/light-collections)
package under `Light\Collections\Generic`. php-linq requires `^1.0` of this package;
collections do not depend on LINQ. Replace old `Light\Linq\Collections\Generic`
imports with `Light\Collections\Generic`.

`GenericList`, `Dictionary`, `HashSet`, `Queue`, `Stack`, collection interfaces,
`EqualityComparer`, and `KeyValuePair` are provided by light-collections. All
collections can be queried through `Enumerable::from($collection)`. See the
[collection guide](https://github.com/mathsgod/light-collections/blob/v1.0.0/docs/collections.md) for usage.

### Materialize query results

```php
$list = Enumerable::from([3, 1, 3])->toList(); // GenericList: [3, 1, 3]
$set = Enumerable::from([3, 1, 3])->toHashSet(); // HashSet: unique values
$usersById = Enumerable::from($users)->toDictionary(fn ($user) => $user['id']);
$namesById = Enumerable::from($users)->toDictionary(
    fn ($user) => $user['id'],
    fn ($user) => $user['name'],
);
```

These methods immediately consume the source and return new mutable
`Light\Collections\Generic` collections. `toDictionary()` rejects null and
duplicate keys; omitted element selectors retain the original element.
`toHashSet($comparer)` and `toDictionary($keySelector, $elementSelector, $comparer)`
accept an optional `EqualityComparer`. For provider queries, call
`asEnumerable()` before these conversions.

## LINQ to XML migration

XML support now lives in the separate [mathsgod/php-xml](https://github.com/mathsgod/light-xml)
package. Install that package and replace `Light\Linq\Xml` imports with
`Light\Xml\Linq`. The old XML classes are removed; php-linq no longer requires
`ext-dom`. Existing XML querying APIs are available in php-xml together with
XML construction, editing and saving. See the [XML querying guide](https://github.com/mathsgod/light-xml/blob/main/docs/xml.md).

## Provider-backed queries

```php
use Light\Linq\Expr;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;

$provider = new InMemoryQueryProvider([
    'users' => [
        ['name' => 'Ada', 'active' => true, 'age' => 36],
        ['name' => 'Bob', 'active' => false, 'age' => 22],
    ],
]);

$names = Queryable::from($provider, 'users')
    ->where(Expr::eq(Expr::field('active'), true))
    ->orderBy(Expr::field('name'))
    ->select(Expr::field('name'))
    ->toArray();
```

Select multiple fields with a projection. `fields()` keeps the field names,
while `projection()` allows aliases and computed expressions:

```php
$summaries = $users->select(Expr::fields('id', 'name'))->toArray();

$summaries = $users->select(Expr::projection([
    'userId' => Expr::field('id'),
    'displayName' => Expr::field('name'),
    'isAdult' => Expr::gte(Expr::field('age'), 18),
]))->toArray();
```

`select(Expr::field('name'))` returns a scalar sequence. `fields()` and
`projection()` always return associative rows keyed by their aliases, even when
they contain only one member.

The expression tree is provider-independent. `InMemoryQueryProvider` executes
it against arrays or other iterables; the SQL provider in `mathsgod/php-entity`
translates the same nodes into parameterized SQL.

Supported query operators: `where`, `select`, `orderBy`, `orderByDescending`,
`skip`, `take`, `count`, `any`, and `first`.

## Generic dictionary

`Light\Collections\Generic\Dictionary<TKey, TValue>` is a hash-table collection
with object-key support and pluggable equality semantics:

```php
use Light\Collections\Generic\Dictionary;

/** @var Dictionary<string, int> $scores */
$scores = new Dictionary();
$scores->add('Ada', 10);       // throws when the key already exists
$scores['Ada'] = 15;           // indexer-style insert or replace
$scores->tryGetValue('Ada', $score);
```

It provides `add`, `set`, `get`, `tryAdd`, `tryGetValue`, `containsKey`,
`containsValue`, `remove`, `clear`, `keys`, and `values`. Iteration returns
`KeyValuePair<TKey, TValue>` objects so keys are not restricted to PHP's
integer and string iterator-key types.

## SQL compilation

SQL compilation, query plans and database dialects live in the separate
`mathsgod/php-entity` package under `PhpEntity\Sql`. PDO execution is provided
by `PhpEntity\SqlQueryProvider`. This package supplies database-independent
LINQ operators, expression trees and the `IQueryProvider` contract.

When upgrading, replace `Light\Linq\Sql` imports with `PhpEntity\Sql` and add a
`mathsgod/php-entity` dependency if you use SQL compilation or dialects.

Install development dependencies and run the PHPUnit test suite with:

```sh
composer install
composer test
```

## Query provider contract

`Light\Linq\IQueryProvider` is the provider interface owned by php-linq.
`Queryable` depends on this interface; `InMemoryQueryProvider` implements it
for iterable sources, and `PhpEntity\SqlQueryProvider` implements it for PDO
execution in php-entity. The former interface name `Light\Linq\QueryProvider`
has been renamed to `Light\Linq\IQueryProvider`; update custom providers and imports.

## Joining query sources

`Queryable::join($inner, $outerKey, $innerKey, $resultSelector)` and
`leftJoin(...)` compose two queries from the same provider instance. Keys are
relative to their respective source; the result selector sees `outer` and
`inner`. Both operators execute in `InMemoryQueryProvider`; SQL translation
belongs to `mathsgod/php-entity`.

```php
$query = $orders->leftJoin(
    $customers,
    Expr::field('customer_id'),
    Expr::field('id'),
    Expr::projection(['id' => 'outer.id', 'customer' => 'inner.name']),
);
$rows = $query->where(Expr::eq(Expr::field('customer'), null))->toArray();
```

INNER JOIN emits each matching pair; LEFT JOIN also emits unmatched outer rows
with null inner fields. Null keys never match. In-memory key matching uses
strict PHP equality; database comparisons follow the database's types and
collation. Composite keys use named `Expr::projection()` members. SQL queries
require named row projections as join sources; use named projections rather
than scalar selections when composing subsequent joins.

### Grouping, aggregates and distinct values

```php
use Light\Linq\Expr;

$totals = $orders
    ->groupBy(Expr::field('CustomerId'))
    ->where(Expr::gte(Expr::count(), 5))
    ->select(Expr::projection([
        'CustomerId' => Expr::key(),
        'OrderCount' => Expr::count(),
        'TotalAmount' => Expr::sum(Expr::field('Amount')),
    ]))
    ->toArray();

$countries = $customers->select(Expr::field('Country'))->distinct()->toArray();
```

Use `Expr::fields('Country', 'City')` as a composite grouping key and
`Expr::key('Country')` to reference a key member. Aggregates include `count()`,
`sum($field)`, `avg($field)`, `min($field)` and `max($field)`.
`Expr::count()` counts rows; `Expr::count($field)` counts non-null values.
Other aggregates ignore null values and return null when all values are null.
The terminal query method `->count()` counts the resulting groups or distinct rows.
`distinct()` compares the complete selected value or row. Pagination and distinct
operations are applied in their specified order; SQL results need an explicit
`orderBy()` when deterministic ordering is required.

The in-memory provider returns iterable `Grouping` objects with `key`, `items`,
and `count()` when a grouping is enumerated directly. Distinct values and grouping
keys use strict PHP equality, including array member order and object identity.
SQL providers use database equality and collation rules instead.

### Secondary ordering and terminal query operators

`Queryable` supports secondary ordering and these terminal operations:

```php
$sorted = $query->orderBy(Expr::field('Country'))
    ->thenBy(Expr::field('Name'))
    ->thenByDescending(Expr::field('Id'));

$first = $sorted->firstOrDefault(default: null);
$unique = $query->single(Expr::eq(Expr::field('Id'), 10));
$allPaid = $query->all(Expr::eq(Expr::field('IsPaid'), true));
$hasHK = $query->select(Expr::field('Country'))->contains('HK');
```

`thenBy()` and `thenByDescending()` must follow `orderBy()` or another secondary
ordering. A new `orderBy()` starts a new primary ordering. In-memory sorting is
stable for equal keys. `firstOrDefault($predicate, $default)` returns the default
only for an empty result; null and false are valid results. `single($predicate)`
throws `UnderflowException` for zero matches and `UnexpectedValueException` for
multiple matches. `all($predicate)` is true for an empty sequence. Terminal
operations apply to the sequence produced by earlier operators, including paging.

### Terminal sum

```php
$total = $query
    ->where(Expr::eq(Expr::field('Status'), 'Paid'))
    ->sum(Expr::field('Amount'));
```

`Queryable::sum($selector)` executes immediately and sums the selected values.
Null values are ignored; empty or all-null results return zero. This terminal
method differs from `Expr::sum($field)`, which builds a grouped aggregate
expression. The in-memory provider returns an int or float.

### Terminal average, minimum and maximum

```php
$average = $query->average(Expr::field('Amount'));
$minimum = $query->min(Expr::field('Amount'));
$maximum = $query->max(Expr::field('Amount'));
```

These Queryable operations ignore null values and return null for an empty or
all-null result. They preserve preceding filters, pagination, distinct rows and
named projections, just like terminal `sum()`. This nullable behavior follows
SQL aggregates; it differs from callback-based Enumerable aggregates, whose
empty-sequence and null behavior follows their existing implementations.

### Flattening query sources with selectMany

```php
$rows = $customers->selectMany(
    $orders,
    Expr::projection([
        'CustomerId' => 'outer.Id',
        'OrderId' => 'inner.Id',
    ]),
    predicate: Expr::eq(Expr::field('outer.Id'), Expr::field('inner.CustomerId')),
)->toArray();
```

`Queryable::selectMany($inner, $resultSelector, $predicate = null)` flattens the
matching inner sequence for every outer element. Both queries must share the
same provider instance. The result selector and optional predicate reference
`outer.*` and `inner.*` fields. Without a predicate, every outer/inner pair is
returned. Empty inner sequences produce no rows; duplicates are preserved.
Filter, order and page each source before flattening, or use a named result
projection to compose further field-based query operations afterwards.

This is an explicit expression-based API rather than C#'s collection-selector
lambda overloads. `Enumerable::selectMany()` continues to accept a PHP callback
for flattening nested iterables. In-memory Queryable predicates use relational
null comparisons: nullable field-to-field comparisons do not match nulls;
explicit null constants perform null tests. Other comparison behavior follows
PHP types; SQL providers use database types and collations.

### Single-or-default and last elements

```php
$one = $query->singleOrDefault(Expr::eq(Expr::field('Id'), 10), default: null);
$last = $query->orderBy(Expr::field('CreatedAt'))->thenBy(Expr::field('Id'))->last();
$optionalLast = $query->orderBy(Expr::field('Id'))->lastOrDefault(default: null);
```

`singleOrDefault()` returns the default for zero matches and throws
`UnexpectedValueException` for multiple matches. `last()` throws
`UnderflowException` when no element matches; `lastOrDefault()` returns the
default instead. Null and false are valid results and do not trigger defaults.
The in-memory provider uses sequence order and supports the same predicates and
defaults as the existing callback-based Enumerable methods.

### Queryable set operations

```php
$first = $firstQuery->select(Expr::fields('CustomerId'));
$second = $secondQuery->select(Expr::fields('CustomerId'));

$allRows = $first->concat($second);       // keep duplicates
$combined = $first->union($second);      // distinct values from either query
$shared = $first->intersect($second);    // distinct values present in both
$onlyFirst = $first->except($second);    // distinct values absent from second

$ids = $combined->orderBy(Expr::field('CustomerId'))->toArray();
```

Both sources must share a provider. Set operations compare complete selected
values or rows, not just primary keys. In-memory queries use the existing
Enumerable equality rules and first-occurrence order. `concat()` preserves
all duplicates; the other three operations remove duplicates. Source operators
remain before the set operation, and subsequent operators apply to its result.


### Provider sequence operators

`Queryable::groupJoin()`, `zip()`, and `chunk()` are supported by
`InMemoryQueryProvider`. Group joins retain one result per outer row and expose
matching rows as `inner` (`IEnumerable`) to the result expression. Zip pairs rows
by position to the shorter sequence; chunk returns arrays of up to the requested
positive size. Binary operations require the same provider instance.
See the [Queryable guide](docs/queryable.md) for expressions and examples.

The light-entity SQL provider does not translate these three operations. Call
`asEnumerable()` **before** the operation to run it in PHP with callbacks.
This is an explicit client evaluation boundary; chunking fetched results does
not introduce database pagination or limit the SQL provider's fetch buffering.
