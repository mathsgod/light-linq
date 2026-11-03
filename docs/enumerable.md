# Enumerable 集合查詢

[文件目錄](README.md) · [分組與 join](grouping-and-joins.md)

本篇使用 PHP callback，適用於記憶體內的 iterable。範例先載入專案 Composer autoloader。

## 篩選、投影與攤平

```php
use Light\Linq\Enumerable;

$numbers = Enumerable::from([1, 2, 3, 4]);
$doubled = $numbers->where(fn ($n) => $n % 2 === 0)
    ->select(fn ($n) => $n * 2)->toArray(); // [4, 8]

$flat = Enumerable::from([[1, 2], [3]])
    ->selectMany(fn ($items) => $items)->toArray(); // [1, 2, 3]
```

`select()` 每個元素產生一個結果；`selectMany()` 的 callback 必須回傳 iterable，會將這些序列攤平成一條序列。

## 排序與分頁

```php
$rows = Enumerable::from([
    ['city' => 'HK', 'age' => 30],
    ['city' => 'HK', 'age' => 20],
    ['city' => 'Tokyo', 'age' => 25],
]);
$sorted = $rows->orderBy(fn ($row) => $row['city'])
    ->thenByDescending(fn ($row) => $row['age'])
    ->skip(0)->take(2)->toArray();
// [['city' => 'HK', 'age' => 30], ['city' => 'HK', 'age' => 20]]
```

`orderByDescending()` 是主排序的降序版本。`thenBy()`／`thenByDescending()` 接在排序結果上；重新呼叫 `orderBy()` 會建立新的主排序。相同排序 key 保持原始順序。

| 方法 | 用途 |
| --- | --- |
| `skip($count)` / `take($count)` | 略過／取得前 N 個元素 |
| `skipWhile($predicate)` | 略過開頭連續符合條件的元素 |
| `takeWhile($predicate)` | 取得開頭連續符合條件的元素 |
| `takeLast($count)` | 取得最後 N 個元素 |
| `reverse()` | 反轉順序 |
| `chunk($size)` | 分成多個 array，最後一組可能較短 |
| `shuffle()` | 隨機排序；每次列舉可能不同 |

數量不可為負數；`chunk()` 的大小必須大於零。

## 判斷與取得元素

```php
$hasEven = $numbers->any(fn ($n) => $n % 2 === 0); // true
$allPositive = $numbers->all(fn ($n) => $n > 0); // true
$hasTwo = $numbers->contains(2); // true
$firstEven = $numbers->first(fn ($n) => $n % 2 === 0); // 2
$missing = $numbers->firstOrDefault(fn ($n) => $n > 10, default: -1); // -1
$only = $numbers->single(fn ($n) => $n === 3); // 3
```

| 方法 | 沒有匹配元素 | 多於一個匹配元素 |
| --- | --- | --- |
| `first()` / `last()` | `UnderflowException` | 取得第一／最後一個 |
| `firstOrDefault()` / `lastOrDefault()` | 回傳 `$default`（預設 null） | 取得第一／最後一個 |
| `single()` | `UnderflowException` | `UnexpectedValueException` |
| `singleOrDefault()` | 回傳 `$default`（預設 null） | `UnexpectedValueException` |

以上取得元素的方法均接受可選 predicate。實際元素是 null 或 false 時不會改用 default。`contains()` 使用嚴格比較。空序列的 `any()` 是 false，`all()` 是 true。

## 計數與聚合

```php
$count = $numbers->count(); // 4
$evenCount = $numbers->count(fn ($n) => $n % 2 === 0); // 2
$sum = $numbers->sum(); // 10
$average = $numbers->average(); // 2.5
$minimum = $numbers->min(); // 1
$maximum = $numbers->max(); // 4
$oldest = $rows->maxBy(fn ($row) => $row['age']); // 回傳整筆 row
$youngest = $rows->minBy(fn ($row) => $row['age']);
$totalAge = $rows->sum(fn ($row) => $row['age']); // 75
$product = $numbers->aggregate(fn ($result, $n) => $result * $n, 1); // 24
```

`sum()`、`average()`、`min()`、`max()` 接受可選 selector。`min()`／`max()` 回傳選出的值；`minBy()`／`maxBy()` 回傳原始元素，並接受可選 comparer callback。

空序列的 `sum()` 是 0；`average()`、`min()`、`max()`、`minBy()`、`maxBy()` 會拋出 `UnderflowException`。`aggregate()` 有 seed 時以 seed 開始；未提供 seed 時使用第一個元素，空序列則拋出 `UnderflowException`。`average()` 不會像 SQL 一樣略過 null，應自行先篩選不適用的值。

## 集合運算與串接

```php
$a = Enumerable::from([1, 2, 2, 3]);
$unique = $a->distinct()->toArray(); // [1, 2, 3]
$union = $a->union([3, 4])->toArray(); // [1, 2, 3, 4]
$shared = $a->intersect([2, 4])->toArray(); // [2]
$onlyA = $a->except([2, 4])->toArray(); // [1, 3]
$combined = $a->concat([3, 4])->toArray(); // [1, 2, 2, 3, 3, 4]
$extended = $a->prepend(0)->append(4)->toArray(); // [0, 1, 2, 2, 3, 4]
$byCity = $rows->distinct(fn ($row) => $row['city'])->toArray();
```

`concat()` 保留重複值；`union()`、`intersect()`、`except()` 移除重複結果。後三者接受可選 `EqualityComparer`；`distinct()` 接受的是 key selector。預設比較區分 PHP 型別，物件以 identity 比較。操作的先後次序會影響結果，例如先 `take()` 再排序只會排序已取出的資料。
