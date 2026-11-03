# Enumerable 集合查詢

[文件目錄](README.md) · [分組與 join](grouping-and-joins.md)

本篇使用 PHP callback，適用於記憶體內的 iterable。範例先載入專案 Composer autoloader。

## 建立序列：empty、range、repeat

```php
use Light\Linq\Enumerable;

$empty = Enumerable::empty()->toArray(); // []
$fallback = Enumerable::empty()->defaultIfEmpty('冇資料')->toArray(); // ['冇資料']
$integers = Enumerable::range(5, 3)->toArray(); // [5, 6, 7]
$negativeStart = Enumerable::range(-2, 4)->toArray(); // [-2, -1, 0, 1]
$scaled = Enumerable::range(1, 5)->select(fn ($n) => $n * 10)->toArray();
// [10, 20, 30, 40, 50]
$letters = Enumerable::repeat('A', 3)->toArray(); // ['A', 'A', 'A']
$nulls = Enumerable::repeat(null, 2)->toArray(); // [null, null]
```

| 靜態方法 | 用途 |
| --- | --- |
| `Enumerable::empty()` | 建立空序列 |
| `Enumerable::range($start, $count)` | 由 start 開始，每次加 1，共 count 個整數 |
| `Enumerable::repeat($value, $count)` | 將同一個值重複 count 次 |

`range()` 第二個參數是數量，不是終點，與 PHP 原生 `range()` 的參數意思不同。range／repeat 的 count 為 0 時回傳空序列，負數則在呼叫方法時立即拋出 `InvalidArgumentException`。range 使用 PHP 平台整數範圍（`PHP_INT_MIN` 至 `PHP_INT_MAX`），最後一個值超出上限亦會立即拋出 `InvalidArgumentException`，不會轉成 float。

三個方法都回傳可以重複列舉的 `IEnumerable`。range／repeat 逐個產生元素，不會預先建立大型 array，適合接 `take()` 等操作；`toArray()` 才會收集結果。

repeat 不會 clone 物件，以下三個元素指向同一個物件：

```php
$user = (object) ['name' => 'Ada'];
$users = Enumerable::repeat($user, 3)->toArray();
$users[0]->name = 'Bob';
// $users[1]->name 和 $users[2]->name 亦為 'Bob'
```

## 篩選、投影與攤平

```php
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
| `skipLast($count)` | 略過最後 N 個元素，保留前面元素 |
| `reverse()` | 反轉順序 |
| `chunk($size)` | 分成多個 array，最後一組可能較短 |
| `shuffle()` | 隨機排序；每次列舉可能不同 |

數量不可為負數；`chunk()` 的大小必須大於零。

### 略過尾部元素

```php
$withoutTail = Enumerable::range(1, 5)->skipLast(2)->toArray(); // [1, 2, 3]
$keepAll = Enumerable::range(1, 3)->skipLast(0)->toArray(); // [1, 2, 3]
$dropAll = Enumerable::range(1, 3)->skipLast(5)->toArray(); // []
```

`skipLast($count)` 延遲執行，保留來源順序，使用佇列緩衝尾部元素，不會預先將整個來源轉成 array。要產生第一個結果，必須先讀取 count + 1 個元素；第二個及其後的結果各需多讀一個元素。緩衝空間隨 count 增長，最多暫存 count + 1 個元素。

count 為 0 時直接逐個傳出來源元素；大於或等於來源長度時結果為空。負數會在呼叫方法時立即拋出 `InvalidArgumentException`。可重複列舉性仍取決於來源，與其他 Enumerable 查詢一樣。

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

## 空序列預設值與按位置取值

```php
$fallback = Enumerable::from([])->defaultIfEmpty(0)->toArray(); // [0]
$unchanged = Enumerable::from([null, false])->defaultIfEmpty(0)->toArray(); // [null, false]
$defaultNull = Enumerable::from([])->defaultIfEmpty()->toArray(); // [null]

$names = Enumerable::from(['a' => 'Ada', 'b' => 'Bob', 'c' => 'Cat']);
$secondName = $names->elementAt(1); // 'Bob'
$missingName = $names->elementAtOrDefault(5, default: 'Unknown'); // 'Unknown'
```

`defaultIfEmpty($default = null)` 只在來源完全沒有元素時補上一個元素。它回傳延遲執行的 `IEnumerable`，可以繼續接 `select()` 等方法；已有 null、false 或 0 都不會觸發補值。

`elementAt($index)` 立即執行，以 0 開始的序列位置取值，忽略來源 array keys，取得所需位置便停止列舉。負數或超出長度會拋出 `OutOfBoundsException`。

`elementAtOrDefault($index, $default = null)` 遇到負數或超出長度則回傳 default；負數不會讀取來源。實際元素為 null／false 時會保留原值，不會被 default 取代。

## 按位置配對與比較序列

```php
$paired = $names->zip(
    [90, 80],
    fn ($name, $score) => ['name' => $name, 'score' => $score],
)->toArray();
// [['name' => 'Ada', 'score' => 90], ['name' => 'Bob', 'score' => 80]]

$same = Enumerable::from([1, 2, 3])->sequenceEqual([1, 2, 3]); // true
$differentOrder = Enumerable::from([1, 2, 3])->sequenceEqual([3, 2, 1]); // false
$differentLength = Enumerable::from([1, 2])->sequenceEqual([1, 2, 3]); // false
$differentType = Enumerable::from([1])->sequenceEqual(['1']); // false
```

`zip($second, $resultSelector)` 按位置配對，忽略兩邊 iterator keys；回傳延遲執行的序列，以較短來源為限。result selector 接收兩個元素，必須明確提供。空來源產生空結果，第一個來源為空時不讀取第二個。列舉時會先推進第一個來源，再推進第二個，因此第二個較短時可能讀到第一個來源的一個未配對元素。

`sequenceEqual($second, $comparer = null)` 立即執行，內容、順序及長度均相同才回傳 true，兩條空序列亦為 true。預設使用 `DefaultEqualityComparer`：嚴格區分型別、物件按 identity 比較，NaN 視為相等。可傳入 `EqualityComparer` 自訂比較，只使用 `equals()`，不需要 hashing。第一個差異出現後便停止比較，不會先緩衝完整來源。

這些方法不會替來源增加快取能力；如需重複列舉 Generator，請參考[延遲執行與來源重播](getting-started.md)。這批 API 屬於 `Enumerable`；provider 查詢可先 `asEnumerable()` 再使用。

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

## 按 key 做集合運算

```php
$users = Enumerable::from([
    ['id' => 1, 'name' => 'Ada', 'city' => 'HK'],
    ['id' => 2, 'name' => 'Bob', 'city' => 'Tokyo'],
    ['id' => 3, 'name' => 'Cat', 'city' => 'HK'],
]);
$keySelector = fn ($user) => $user['id'];

$excluded = $users->exceptBy([1, 3], $keySelector)->toArray(); // 只有 Bob
$included = $users->intersectBy([1, 3], $keySelector)->toArray(); // Ada、Cat
$merged = $users->unionBy([
    ['id' => 2, 'name' => 'Updated Bob', 'city' => 'Osaka'],
    ['id' => 4, 'name' => 'Dan', 'city' => 'HK'],
], $keySelector)->toArray(); // Ada、Bob、Cat、Dan；保留原本的 Bob
```

| 方法 | 第二個來源的內容 | 結果 |
| --- | --- | --- |
| `exceptBy($second, $keySelector, $comparer = null)` | 要排除的 key | 第一個來源中不在 key 集合內的完整元素 |
| `intersectBy($second, $keySelector, $comparer = null)` | 要保留的 key | 第一個來源中在 key 集合內的完整元素 |
| `unionBy($second, $keySelector, $comparer = null)` | 完整元素，與第一個來源套用相同 selector | 合併兩個來源，按 key 去重 |

三者每個 key 最多輸出一個元素，保留先出現者；不是更新或覆寫資料。exceptBy／intersectBy 依第一個來源順序輸出，unionBy 先讀第一個來源，再讀第二個來源。

三者均延遲執行。exceptBy／intersectBy 開始列舉時先將第二個來源的 keys 載入 HashSet，再逐個處理第一個來源。unionBy 逐個處理資料並記錄已見 keys，只有需要時才讀第二個來源。

## countBy：每個 key 計數

```php
$counts = $users->countBy(fn ($user) => $user['city'])
    ->select(fn ($pair) => [$pair->key, $pair->value])
    ->toArray();
// [['HK', 2], ['Tokyo', 1]]
```

`countBy($keySelector, $comparer = null)` 回傳 `IEnumerable<KeyValuePair<TKey, int>>`，每個 pair 有公開的 `key` 及 `value`。結果不是 PHP associative array，因此可以保留 null、物件及複合 key，亦不會將整數 1 與字串 '1' 自動合併。

## aggregateBy：每個 key 分別累加

```php
$orders = Enumerable::from([
    ['customerId' => 1, 'amount' => 100],
    ['customerId' => 2, 'amount' => 50],
    ['customerId' => 1, 'amount' => 200],
]);
$totals = $orders->aggregateBy(
    fn ($order) => $order['customerId'],
    0,
    fn ($total, $order) => $total + $order['amount'],
)->select(fn ($pair) => [$pair->key, $pair->value])->toArray();
// [[1, 300], [2, 50]]

$stats = $orders->aggregateBy(
    fn ($order) => $order['customerId'],
    ['count' => 0, 'total' => 0],
    fn ($acc, $order) => [
        'count' => $acc['count'] + 1,
        'total' => $acc['total'] + $order['amount'],
    ],
)->select(fn ($pair) => [$pair->key, $pair->value])->toArray();
// [[1, ['count' => 2, 'total' => 300]], [2, ['count' => 1, 'total' => 50]]]
```

`aggregateBy($keySelector, $seed, $accumulator, $comparer = null)` 回傳 `IEnumerable<KeyValuePair<TKey, TAccumulate>>`。每個新 key 由 seed 開始，第一筆資料亦會交給 accumulator；callback 接收目前累加值及原始元素，回傳新的累加值。

本版本接受 seed 值，未提供 seed factory overload。建議使用數字、字串或 array 等 seed；物件 seed 不會自動 clone，多個 key 會收到相同物件，直接修改它會共享狀態。需獨立物件時，accumulator 應自行建立新物件並回傳。

countBy／aggregateBy 均延遲執行，但在第一個結果輸出前需要讀完整個來源。它們只保留每個 key 的累加值，不會保留全部組內元素；結果依 key 首次出現排序，保留該 key 的第一個表示值。空來源回傳空序列，不會輸出 seed。

以上五個方法的 comparer 都是可選的 `EqualityComparer`。預設區分 PHP 型別、array 嚴格相等、物件 identity；null 可以作為 key。自訂 comparer 的相等 key 必須有相同 hash，不同 key 的 hash collision 會再用 `equals()` 分辨。重複列舉時會重新執行來源及 callback，來源須可重播；需要新 Generator 時用 `Enumerable::defer()`。


## 轉換為集合：toList、toHashSet、toDictionary

```php
$rows = Enumerable::from([
    ['id' => 1, 'name' => 'Ada'],
    ['id' => 2, 'name' => 'Bob'],
]);
$list = $rows->where(fn ($row) => $row['id'] > 0)->toList();
$set = Enumerable::from([1, 1, '1', null])->toHashSet(); // 3 個不同元素
$byId = $rows->toDictionary(fn ($row) => $row['id']);
$ada = $byId->get(1); // ['id' => 1, 'name' => 'Ada']
$names = $rows->toDictionary(fn ($row) => $row['id'], fn ($row) => $row['name']);
$name = $names->get(2); // 'Bob'
```

| 方法 | 回傳類別（`Light\Collections\Generic`） | 行為 |
| --- | --- | --- |
| `toList()` | `GenericList` | 保留順序、重複值及 null，捨棄來源 iterator keys |
| `toHashSet($comparer = null)` | `HashSet` | 依比較器去重，不保證列舉順序 |
| `toDictionary($keySelector, $elementSelector = null, $comparer = null)` | `Dictionary` | 由 selector 取得 key；未指定 element selector 時保留原元素 |

三者均即時執行、讀取來源一次，建立新的可修改集合；之後讀取集合不會重新執行查詢。
每次轉換均建立獨立集合，但元素內的物件不會深層複製。來源或 selector 的例外會直接傳出。
空來源回傳空集合，不會呼叫 selector。單次使用的 Generator 轉換後會被消耗。

`toHashSet()` 及 `toDictionary()` 可傳入 `EqualityComparer`。預設比較區分型別，
例如整數 `1` 和字串 `'1'` 不會合併；物件使用 identity 比較。
Dictionary 依比較器判斷重複 key，重複或 null key 會拋出 `InvalidArgumentException`，不會覆寫。
Dictionary 列舉產生 `KeyValuePair`，不是 PHP array 的 key/value entries。
只需自訂 key 比較器、保留原元素時，可用 `toDictionary($keySelector, comparer: $comparer)`。

Queryable 可先呼叫 `asEnumerable()`，再使用上述轉換方法，將 provider 查詢結果實體化為集合。
