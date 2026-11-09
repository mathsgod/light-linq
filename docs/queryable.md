# Queryable 與運算式

[文件目錄](README.md) · [Enumerable](enumerable.md)

`Queryable` 將操作組成 expression tree，由 `IQueryProvider` 執行。
`where()`、`select()`、`any()`、`skipWhile()`、`takeWhile()` 及下述六個 `By` 方法的 selector
接受 `ValueExpression` 或受限制的 PHP closure。
Closure 透過原始碼 AST 翻譯，不會像 `Enumerable` 的 callback 一樣逐筆呼叫。
其他方法仍以各自簽名為準，例如 `orderBy()` 接受 `ValueExpression`。
詳見 [Closure translation](closure-translation.md) 的支援語法及限制。

```php
$customers->where(fn($c) => str_starts_with($c->name, 'a'));
$orders->where(fn($o) => $o->total + $o->tax >= 30);
```

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
| `coalesce($left, $right)` | NULL fallback；右側可為 expression 或常數 |
| `condition($test, $ifTrue, $ifFalse)` | 條件分支；分支可為 expression 或常數 |
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

## Join、leftJoin、rightJoin 與 selectMany

`rightJoin()` 保留所有右邊（inner）元素，未匹配時 `outer` 為 null。
key selector 相對各自來源，result selector 仍使用 `outer.*`／`inner.*`：

```php
$right = $customers->rightJoin(
    $orders,
    Expr::field('id'),
    Expr::field('customerId'),
    Expr::projection(['customer' => 'outer.name', 'orderId' => 'inner.id']),
)->toArray();
```

兩個查詢必須使用同一 provider 實例。記憶體 provider 依右邊來源順序輸出，
每筆右邊元素的匹配依左邊來源順序輸出；保留重複資料及所有匹配組合。
沿用 Queryable join 的關聯式 key 比較：區分 PHP 型別，null 不匹配，
複合 key 任何成員為 null 亦不匹配。

建立查詢不執行 provider；列舉時串流右邊，第一次遇到右邊元素才讀取並緩衝左邊一次。
右邊為空時不執行左邊查詢；左邊為空時，每筆右邊元素仍產生結果。
`take()` 可提早停止右邊列舉。SQL／自訂 provider 需要另外支援 `rightJoin` expression，
沒有自動 client-side fallback；可先 `asEnumerable()` 再使用 Enumerable 的 `rightJoin()`。
以上輸出順序描述適用於記憶體 provider，資料庫結果順序仍須明確排序。

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

若要繼續組合 SQL join，來源請使用具名 row 投影，避免純值投影。`groupJoin()` 的記憶體 provider 行為見下一節；SQL provider 尚未翻譯此操作。

## groupJoin、zip 與 chunk（依 provider 支援）

這三個方法保留在 Queryable expression tree，並非自動轉成 PHP callback。
`InMemoryQueryProvider` 支援它們；light-entity 的 SQL provider 尚未支援直接翻譯。

```php
$groups = $customers->groupJoin(
    $orders,
    Expr::field('id'),
    Expr::field('customerId'),
    Expr::projection(['name' => 'outer.name', 'orders' => 'inner']),
)->toArray();
// 每筆 customer 一個結果，orders 為 IEnumerable，即使沒有匹配也保留空序列。
foreach ($groups as $group) {
    $orderIds = $group['orders']->select(fn ($order) => $order['id'])->toArray();
}

$pairs = $customers->orderBy(Expr::field('id'))->zip(
    $orders->orderBy(Expr::field('id')),
    Expr::projection(['name' => 'outer.name', 'orderId' => 'inner.id']),
)->toArray();

$batches = $orders->orderBy(Expr::field('id'))->chunk(2)->toArray();
// 回傳 list<list<row>>，最後一批可能不足 2 筆。
```

`groupJoin()` 的 key 相對於各自來源；result selector 的 `outer` 是目前外側元素，
`inner` 是可重複列舉的 `IEnumerable` 匹配集合。保留每筆 outer（包括重複 key 及未匹配），
以及 inner 的重複元素和順序。key 比較沿用 Queryable join 的關聯式規則：區分 PHP 型別，
null key 不匹配，複合 key 任何成員為 null 亦不匹配。每次執行最多讀取 inner 一次並緩衝，
outer 為空時不讀取 inner。這與 callback Enumerable 的預設 equality 規則不同。

`zip($second, $resultSelector)` 按列舉位置配對，與 key 無關；結果長度為較短序列的長度。
result selector 同樣使用 `outer`／`inner`。需要可預期的配對時，請先明確排序兩邊。
`groupJoin()`／`zip()` 的兩個查詢必須使用同一個 provider 實例，否則建立查詢時拋出
`InvalidArgumentException`。

`chunk($size)` 要求正整數，否則建立查詢時拋出 `InvalidArgumentException`。
記憶體 provider 每次只緩衝當前批次，捨棄來源 iterator keys；空來源不產生批次。
chunk 後每個元素是 array，可用 `skip()`／`take()` 分批，或 `asEnumerable()` 後用 callback 處理。
三個方法均在列舉或終結操作時執行；記憶體 provider 不會在查詢建立時讀取來源。

SQL 查詢請在這些操作之前明確切換到 Enumerable；切換後使用 callback，並在 PHP 執行：

```php
$batches = $orders->orderBy(Expr::field('id'))->asEnumerable()->chunk(100);
$groups = $customers->asEnumerable()->groupJoin(
    $orders->asEnumerable(),
    fn ($customer) => $customer['id'],
    fn ($order) => $order['customerId'],
    fn ($customer, $matches) => ['customer' => $customer, 'orders' => $matches->toArray()],
);
```

`chunk()` 分割已取得的結果，並非資料庫分頁或每批發出一次 SQL 查詢；
SQL provider 仍可能在切換前載入全部結果，先用 `where()`／`take()` 限制需要的資料。

## 按 key 去重、集合運算與極值

`distinctBy()`、`exceptBy()`、`intersectBy()`、`unionBy()` 按選出的 key 比較，
保留完整元素；`minBy()`／`maxBy()` 回傳 key 最小／最大的完整元素。
這些方法的 key selector 接受 `ValueExpression` 或受限制的單參數 closure。

```php
$unique = $people->distinctBy(fn ($person) => $person->id)->toArray();
$keys = $blockedPeople->select(Expr::field('id'));
$allowed = $people->exceptBy($keys, Expr::field('id'))->toArray();
$blocked = $people->intersectBy($keys, Expr::field('id'))->toArray();
$merged = $people->unionBy($otherPeople, fn ($person) => $person->id)->toArray();
$youngest = $people->minBy(fn ($person) => $person->age);
$oldest = $people->maxBy(Expr::field('age'));
```

`exceptBy()`／`intersectBy()` 的第二個查詢提供 **key 值**，通常先 `select()` 指定欄位；
`unionBy()` 的第二個查詢提供 **完整元素**，兩邊使用同一個 selector。
兩個查詢必須共用同一 provider 實例，否則建立查詢時拋出 `InvalidArgumentException`。

記憶體 provider 沿用 Enumerable 的 equality：區分 PHP 型別，null key 可相等，
array key 使用嚴格相等，物件 key 按 identity 比較。四個集合操作均按 key 去重，
保留首次出現的元素及順序；union 優先保留第一個來源的元素。
minBy／maxBy 使用 PHP `<=>` 比較 key（包括 null），相同 key 保留第一項；
空來源拋出 `UnderflowException`，不套用 SQL 聚合忽略 null 的規則。

四個集合操作延遲執行；`minBy()`／`maxBy()` 是立即執行的終結操作。
except／intersect 每次執行先緩衝第二來源的 keys，union 依次串流兩個來源。
沒有自訂 comparer 參數；如需要 PHP callback comparer，先 `asEnumerable()`。
SQL／自訂 provider 需要明確支援新 expression，否則應拒絕；不會自動切換到 PHP。
SQL 的型別、collation、null 及排序規則亦由 provider 決定。

## 序列開頭、尾部與條件分段

以下操作會建立新的 `IQueryable` expression，不會修改原查詢或寫入資料庫。
`InMemoryQueryProvider` 支援全部八個方法：

| 方法 | 行為 |
| --- | --- |
| `append($value)` | 將單一元素加到尾部，保留重複及 null |
| `prepend($value)` | 將單一元素加到開頭 |
| `reverse()` | 倒轉目前結果順序 |
| `defaultIfEmpty($default = null)` | 來源沒有任何元素時才產生一個預設元素；`[null]` 不視為空 |
| `skipWhile($predicate)` | 略過開頭連續符合條件的元素，第一次 false 後保留餘下所有元素 |
| `takeWhile($predicate)` | 取開頭連續符合條件的元素，第一次 false 即停止 |
| `skipLast($count)` | 略過最後 count 項 |
| `takeLast($count)` | 取最後 count 項，保留原順序 |

```php
$names = $customers->orderBy(Expr::field('id'))->select(Expr::field('name'));
$choices = $names->prepend('全部')->append('其他')->toArray();
$lastNames = $names->takeLast(2)->toArray();
$reversed = $names->reverse()->toArray();
$fallback = $names->defaultIfEmpty('沒有客戶')->toArray();

$initialSmallOrders = $orders->orderBy(Expr::field('id'))
    ->takeWhile(fn ($order) => $order->total < 100)->toArray();
$remaining = $orders->orderBy(Expr::field('id'))
    ->skipWhile(Expr::lt(Expr::field('total'), 100))->toArray();
```

While predicate 接受 `ValueExpression` 或受限制的單一 row 參數 closure，翻譯規則與
`where()` 相同，不支援 indexed callback。記憶體 provider 將 predicate 結果轉成 bool，
null 視為 false。`skipLast()`／`takeLast()` 要求非負 count，負數在建立查詢時拋出
`InvalidArgumentException`；零分別保留全部／回傳空序列，count 大於長度則回傳空／全部。

建立查詢不讀來源，列舉時才交給 provider 執行。記憶體 provider 的 `reverse()` 要緩衝
完整結果；`takeLast()` 讀完整個來源並保留尾部，`skipLast()` 緩衝尾部窗口。
`takeLast(0)` 不列舉來源，`prepend()` 可在讀原來源前產生新增元素。
來源是否能重播仍取決於原 iterable，不會替單次 Generator 增加快取能力。

SQL／自訂 provider 必須明確支援這些 expression，否則應拒絕，不會自動切換至 PHP。
需要 PHP 處理時可先 `asEnumerable()` 再呼叫同名方法。
涉及頭尾及順序時請先明確排序；新增元素亦須符合後續欄位投影及 predicate 的資料形狀。

## 集合運算與切換到 Enumerable

`longCount()` 沿用 provider 現有的 `count` 運算；可傳入 `ValueExpression` 或
受限制 closure 作為條件，例如 `->longCount(Expr::eq(Expr::field('active'), true))`。
它立即執行並回傳 PHP `int`，位元寬度及最大可計數範圍仍受 PHP 與 provider 限制。
`asEnumerable()` 則延遲至列舉時執行來源查詢，後續 callback 在 PHP 端處理。

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

`IQueryProvider`、`expression()`、`provider()` 提供自訂 provider 的整合點。SQL 編譯、方言及 `Light\Entity\SqlQueryProvider` 屬於 `mathsgod/light-entity`；本篇範例不代表每個 provider 都能翻譯所有組合。資料庫型別、collation 及 null 規則可能令結果與 PHP 嚴格比較不同。
