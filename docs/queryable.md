# Queryable 與運算式

[文件目錄](README.md) · [Enumerable](enumerable.md)

`Queryable` 將操作組成 expression tree，由 `IQueryProvider` 執行。與 `Enumerable` 不同，條件及投影使用 `Expr`，不接受一般 PHP callback 作為查詢運算式。

## 建立 provider 與查詢

```php
use Light\Linq\Expr;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;

$provider = new InMemoryQueryProvider([
    'customers' => [
        ['id' => 1, 'name' => 'Ada', 'active' => true],
        ['id' => 2, 'name' => 'Bob', 'active' => false],
    ],
    'orders' => [
        ['id' => 101, 'customerId' => 1, 'amount' => 100],
        ['id' => 102, 'customerId' => 1, 'amount' => 200],
    ],
]);
$customers = Queryable::from($provider, 'customers');
$orders = Queryable::from($provider, 'orders');

$names = $customers->where(Expr::eq(Expr::field('active'), true))
    ->orderBy(Expr::field('name'))
    ->select(Expr::field('name'))->toArray(); // ['Ada']
```

`where()`、`select()` 等建立新的查詢；列舉及終結操作才交由 provider 執行。範例 provider 使用記憶體資料，不需要資料庫。

## Expr 速查

| 方法 | 用途 |
| --- | --- |
| `field('name')` | 欄位；可用點分隔的欄位路徑 |
| `value($value)` | 常數 |
| `eq` / `neq` / `gt` / `gte` / `lt` / `lte` | 比較；左側是 expression，右側可為值或 expression |
| `both($left, $right)` / `either($left, $right)` | AND／OR |
| `fields('id', 'name')` | 保留欄位末段名稱的具名投影 |
| `projection(['alias' => ...])` | 自訂別名或計算欄位 |
| `key()` / `key('member')` | 分組 key／複合 key 成員 |
| `count()` / `sum($field)` / `avg($field)` / `min($field)` / `max($field)` | 分組內聚合 expression |

```php
$selected = $customers->select(Expr::projection([
    'customerId' => 'id',
    'displayName' => Expr::field('name'),
    'enabled' => Expr::eq(Expr::field('active'), true),
]))->toArray();
```

`projection()` 中的字串代表欄位路徑；常數字串需要寫成 `Expr::value('固定文字')`。單一 `field()` 投影回傳純值序列；`fields()`／`projection()` 回傳具名 row，即使只有一個成員亦一樣。`fields()` 不接受重複別名。

## 排序、分頁與終結操作

```php
$first = $customers->orderBy(Expr::field('name'))
    ->thenByDescending(Expr::field('id'))
    ->skip(0)->take(10)->firstOrDefault(default: null);
$total = $orders->sum(Expr::field('amount')); // 300
$average = $orders->average(Expr::field('amount')); // 150
$minimum = $orders->min(Expr::field('amount')); // 100
$maximum = $orders->max(Expr::field('amount')); // 200
$hasOrders = $orders->any(); // true
$count = $orders->count(); // 2
$allPositive = $orders->all(Expr::gt(Expr::field('amount'), 0)); // true
$hasAda = $customers->select(Expr::field('name'))->contains('Ada'); // true
```

亦支援 `first`、`single`、`singleOrDefault`、`last`、`lastOrDefault`，以及它們的可選 predicate。沒有結果時，`first`／`single`／`last` 拋出 `UnderflowException`；OrDefault 版本回傳 default。single 的兩個版本有多個結果時均拋出 `UnexpectedValueException`。

`thenBy()` 必須接在排序操作之後。資料庫查詢要有確定順序時，請明確 `orderBy()`，尤其是分頁及取得最後一筆。

Queryable 終結 `sum()` 忽略 null，空或全 null 回傳 0；`average()`、`min()`、`max()` 忽略 null，空或全 null 回傳 null。這些規則與 `Enumerable` 並不完全相同。SQL provider 的數字結果亦可能是字串。

## 分組、HAVING 與去重

```php
$totals = $orders->groupBy(Expr::field('customerId'))
    ->where(Expr::gte(Expr::count(), 2))
    ->select(Expr::projection([
        'customerId' => Expr::key(),
        'orderCount' => Expr::count(),
        'total' => Expr::sum(Expr::field('amount')),
    ]))->toArray();
// [['customerId' => 1, 'orderCount' => 2, 'total' => 300]]

$ids = $orders->select(Expr::field('customerId'))->distinct()->toArray(); // [1]
```

複合 key 使用 `groupBy(Expr::fields('fieldA', 'fieldB'))`，再用 `Expr::key('fieldA')` 取成員。`Expr::count()` 計所有 row，`Expr::count($field)` 只計非 null 值。其他分組聚合忽略 null，全 null 時回傳 null。終結 `->count()` 則計算查詢目前產生的群組或 distinct 結果數。

## Join、leftJoin 與 selectMany

```php
$joined = $customers->join(
    $orders,
    Expr::field('id'),
    Expr::field('customerId'),
    Expr::projection(['name' => 'outer.name', 'orderId' => 'inner.id']),
)->toArray();

$left = $customers->leftJoin(
    $orders,
    Expr::field('id'),
    Expr::field('customerId'),
    Expr::projection(['name' => 'outer.name', 'orderId' => 'inner.id']),
)->toArray(); // 包括 Bob，orderId 是 null

$pairs = $customers->selectMany(
    $orders,
    Expr::projection(['name' => 'outer.name', 'orderId' => 'inner.id']),
    predicate: Expr::eq(Expr::field('outer.id'), Expr::field('inner.customerId')),
)->toArray();
```

兩個查詢必須來自同一個 provider 實例。Join 的 key 相對於各自來源；result selector 使用 `outer.*`／`inner.*`。null join key 不會匹配。`selectMany()` 沒有 predicate 時會回傳所有 outer／inner 配對；這個 API 接受明確的 inner 查詢，與 Enumerable 的 callback 攤平不同。

若要繼續組合 SQL join，來源請使用具名 row 投影，避免純值投影。`groupJoin()` 目前只在 Enumerable 提供。

## 集合運算與切換到 Enumerable

```php
$a = $customers->select(Expr::fields('id'));
$b = $orders->select(Expr::projection(['id' => 'customerId']));
$union = $a->union($b)->toArray();
$intersection = $a->intersect($b)->toArray();
$difference = $a->except($b)->toArray();
$concatenated = $a->concat($b)->toArray();

$upperNames = $customers->where(Expr::eq(Expr::field('active'), true))
    ->asEnumerable()
    ->select(fn ($row) => strtoupper($row['name']))
    ->toArray(); // ['ADA']
```

集合操作比較完整選出值／row，兩個來源亦必須共用 provider。`concat()` 保留重複，其他三者去重。切換後的 callback 在 PHP 端處理，不會再翻譯到 SQL。

## SQL provider 的邊界

`IQueryProvider`、`expression()`、`provider()` 提供自訂 provider 的整合點。SQL 編譯、方言及 `PhpEntity\SqlQueryProvider` 屬於 `mathsgod/php-entity`；本篇範例不代表每個 provider 都能翻譯所有組合。資料庫型別、collation 及 null 規則可能令結果與 PHP 嚴格比較不同。
