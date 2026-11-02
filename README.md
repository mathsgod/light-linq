# php-linq

An experimental LINQ-style query library for PHP 8.2+. Unlike callback-only
collection wrappers, a query is represented by an expression tree and executed
by a query provider.

## LINQ to Objects

`Enumerable` provides lazy, generator-based processing with ordinary PHP
callbacks:

```php
use PhpLinq\Enumerable;

$names = Enumerable::from($users)
    ->where(fn (User $user): bool => $user->active)
    ->orderBy(fn (User $user): int => $user->age)
    ->select(fn (User $user): string => $user->name)
    ->take(10)
    ->toArray();
```

Streaming operators include `where`, `select`, `selectMany`, `skip`, `take`,
`skipWhile`, `takeWhile`, `distinct`, `append`, `prepend`, `concat`, and
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

## Generic collections

Mutable collections modelled after `System.Collections.Generic` are available
under `PhpLinq\\Collections\\Generic`:

```php
use PhpLinq\Collections\Generic\GenericList;
use PhpLinq\Collections\Generic\Queue;
use PhpLinq\Collections\Generic\Stack;

$list = new GenericList([1, 2]);
$list->add(3);
$list->insert(0, 0);
$list->insertRange(1, [10, 20]);
$slice = $list->getRange(1, 2);
$list->removeRange(1, 2);

$stack = new Stack();
$stack->push('job');
$job = $stack->pop();

$queue = new Queue();
$queue->enqueue('job');
$job = $queue->dequeue();
```

PHP reserves the keyword `list`, so the .NET `List<T>` equivalent is named
`GenericList<T>`. All three collections implement `IteratorAggregate` and can
be passed directly to `Enumerable::from()`.

The generic collection contracts are organised as follows:

```text
IReadOnlyCollection<T>
├── ICollection<T>
│   ├── IList<T> → GenericList<T>
│   └── ISet<T>  → HashSet<T>
├── Stack<T>
└── Queue<T>

IReadOnlyCollection<KeyValuePair<TKey, TValue>>
└── IDictionary<TKey, TValue> → Dictionary<TKey, TValue>
```

`HashSet<T>` supports custom `EqualityComparer<T>` implementations and mutable
set operations including union, intersection, difference, symmetric
difference, subset/superset checks, overlap checks, and set equality.

## LINQ to XML

The `PhpLinq\\Xml` namespace provides a DOMDocument-backed XML object model:

```php
use PhpLinq\Xml\XDocument;
use PhpLinq\Xml\XElement;

$document = XDocument::load('users.xml');

$names = $document
    ->descendants('user')
    ->where(fn (XElement $user): bool =>
        $user->attribute('active')?->value() === 'true'
    )
    ->select(fn (XElement $user): ?string =>
        $user->element('name')?->value()
    )
    ->toArray();
```

`XDocument` supports parsing, file loading, root access, descendant queries,
and XML serialization. `XElement` provides direct-child and descendant element
queries plus attribute access. Expanded names such as `{urn:people}user` are
supported through `XName`. All XML sequence methods return lazy `IEnumerable`
instances.

```php
use PhpLinq\Expr;
use PhpLinq\InMemoryQueryProvider;
use PhpLinq\Queryable;

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

`Collections\\Generic\\Dictionary<TKey, TValue>` is a hash-table collection
with object-key support and pluggable equality semantics:

```php
use PhpLinq\Collections\Generic\Dictionary;

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

When upgrading, replace `PhpLinq\Sql` imports with `PhpEntity\Sql` and add a
`mathsgod/php-entity` dependency if you use SQL compilation or dialects.

Install development dependencies and run the PHPUnit test suite with:

```sh
composer install
composer test
```

## Query provider contract

`PhpLinq\IQueryProvider` is the provider interface owned by php-linq.
`Queryable` depends on this interface; `InMemoryQueryProvider` implements it
for iterable sources, and `PhpEntity\SqlQueryProvider` implements it for PDO
execution in php-entity. The former interface name `PhpLinq\QueryProvider`
has been renamed to `PhpLinq\IQueryProvider`; update custom providers and imports.

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
use PhpLinq\Expr;

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
