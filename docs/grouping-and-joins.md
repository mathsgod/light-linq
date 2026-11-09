# 分組與關聯查詢

[文件目錄](README.md) · [Enumerable](enumerable.md)

本篇是 `Enumerable` 的 callback API；`Queryable` 使用不同的 [Expr API](queryable.md)。

## 範例資料

```php
use Light\Linq\Enumerable;

$customers = [
    ['id' => 1, 'name' => 'Ada'],
    ['id' => 2, 'name' => 'Bob'],
    ['id' => 3, 'name' => 'Cat'],
];
$orders = [
    ['id' => 101, 'customerId' => 1, 'total' => 100],
    ['id' => 102, 'customerId' => 2, 'total' => 50],
    ['id' => 103, 'customerId' => 1, 'total' => 200],
];
```

## groupBy：將單一來源分組

可選的 `resultSelector` 接收 `(key, IEnumerable $members)`，直接將每組投影成結果：

```php
$summary = Enumerable::from($orders)->groupBy(
    keySelector: fn ($order) => $order['customerId'],
    resultSelector: fn ($customerId, $members) => [
        'customerId' => $customerId,
        'count' => $members->count(),
        'total' => $members->sum(fn ($order) => $order['total']),
    ],
)->toArray();
```

簽名為 `groupBy($keySelector, ?EqualityComparer $comparer = null, ?callable $resultSelector = null)`；
comparer 保留第二個參數位置，建議以 named argument 傳入 result selector。
省略或傳入 null 時維持回傳 `Grouping`。提供 selector 時，members 是可重複列舉的
`IEnumerable`，可使用 `count()`、`sum()`、`select()` 等方法，亦可在 callback 外保留使用。
群組及成員沿用原有順序，自訂 comparer 相等的 key 使用首次出現的 key。
所有處理延遲至列舉；先緩衝完整來源，再按需求對每組執行 selector，空來源不呼叫 selector。
每次重新列舉會重新分組並投影；selector 例外直接傳出。
此 callback 版本屬於 Enumerable；Queryable 請先 `asEnumerable()` 再使用。

```php
$groups = Enumerable::from($orders)->groupBy(fn ($order) => $order['customerId']);
$summaries = $groups->select(fn ($group) => [
    'customerId' => $group->key,
    'count' => count($group),
    'total' => Enumerable::from($group)->sum(fn ($order) => $order['total']),
])->toArray();
// [
//     ['customerId' => 1, 'count' => 2, 'total' => 300],
//     ['customerId' => 2, 'count' => 1, 'total' => 50],
// ]
```

回傳元素是 `Grouping`：`key` 是分組 key，`items` 是組內元素 array；也可直接 `foreach ($group as $item)` 或 `count($group)`。Grouping 本身不是 `IEnumerable`，需要查詢方法時使用 `Enumerable::from($group)`。

群組按 key 首次出現排序，組內保持來源順序。建立查詢不會立即執行，但開始取得群組時會先讀完整個來源。空來源沒有群組；null 可以作為分組 key。

## join：每一對匹配產生一個結果

```php
$joined = Enumerable::from($customers)->join(
    $orders,
    fn ($customer) => $customer['id'],
    fn ($order) => $order['customerId'],
    fn ($customer, $order) => [$customer['name'], $order['id']],
)->toArray();
// [['Ada', 101], ['Ada', 103], ['Bob', 102]]
```

參數依次為 inner 來源、outer key selector、inner key selector、result selector，以及可選的比較器。沒有匹配的 Cat 不會出現在結果中。

## groupJoin：每個外層元素帶一組匹配資料

```php
$grouped = Enumerable::from($customers)->groupJoin(
    $orders,
    fn ($customer) => $customer['id'],
    fn ($order) => $order['customerId'],
    fn ($customer, $matches) => [
        'name' => $customer['name'],
        'orderIds' => $matches->select(fn ($order) => $order['id'])->toArray(),
        'total' => $matches->sum(fn ($order) => $order['total']),
    ],
)->toArray();
// [
//     ['name' => 'Ada', 'orderIds' => [101, 103], 'total' => 300],
//     ['name' => 'Bob', 'orderIds' => [102], 'total' => 50],
//     ['name' => 'Cat', 'orderIds' => [], 'total' => 0],
// ]
```

`$matches` 是可重複列舉的 `IEnumerable`。沒有匹配時仍呼叫 result selector，傳入空序列；outer 的重複元素亦各自產生結果。

`groupJoin()` 延遲執行，讀到第一個 outer 元素時建立 inner 索引，之後逐個處理 outer。每次重新列舉都重建索引；來源需要可以重播，或使用 `Enumerable::defer()`。outer 為空時不讀取 inner。

## Key 比較與自訂 EqualityComparer

預設嚴格區分 `1` 和 `'1'`；array 依嚴格相等比較，物件依 identity 比較。`Enumerable` 的 join／groupJoin 會匹配 null key，與 provider-backed SQL join 的 null 規則不同。

兩個新方法均接受最後一個 `$comparer` 參數。以下忽略大小寫：

```php
use Light\Collections\Generic\EqualityComparer;

$ignoreCase = new class implements EqualityComparer {
    public function hash(mixed $value): string
    {
        return strtolower($value);
    }
    public function equals(mixed $left, mixed $right): bool
    {
        return strtolower($left) === strtolower($right);
    }
};

$letters = Enumerable::from(['A', 'b', 'a'])
    ->groupBy(fn ($value) => $value, $ignoreCase)->toArray();
// 兩組：key 'A'、items ['A', 'a']；key 'b'、items ['b']
```

這個 comparer 只供字串 key 使用。相等的 key 必須有相同 hash；不同 key 可以有相同 hash，函式庫會再用 `equals()` 分辨。


## leftJoin 與 rightJoin：保留未匹配資料

```php
$users = Enumerable::from([
    ['id' => 1, 'name' => 'Ada'],
    ['id' => 2, 'name' => 'Bob'],
]);
$orders = [
    ['id' => 101, 'userId' => 1],
    ['id' => 102, 'userId' => 3],
];
$left = $users->leftJoin(
    $orders,
    fn ($user) => $user['id'],
    fn ($order) => $order['userId'],
    fn ($user, $order) => ['name' => $user['name'], 'orderId' => $order['id'] ?? null],
)->toArray();
// [['name' => 'Ada', 'orderId' => 101], ['name' => 'Bob', 'orderId' => null]]

$right = $users->rightJoin(
    $orders,
    fn ($user) => $user['id'],
    fn ($order) => $order['userId'],
    fn ($user, $order) => ['name' => $user['name'] ?? null, 'orderId' => $order['id']],
)->toArray();
// [['name' => 'Ada', 'orderId' => 101], ['name' => null, 'orderId' => 102]]
```

兩者簽名均為 `($inner, $outerKeySelector, $innerKeySelector, $resultSelector, $comparer = null)`。
左邊是呼叫方法的 Enumerable，右邊是 `$inner`；即使是 `rightJoin()`，
result selector 的參數順序亦固定為 `(左邊元素, 右邊元素)`。
未匹配的一邊傳入 null，callback 應接受 nullable 值。

`leftJoin()` 依左邊來源順序輸出；每筆左邊元素的匹配依右邊來源順序輸出。
`rightJoin()` 依右邊來源順序輸出；每筆右邊元素的匹配依左邊來源順序輸出。
重複 key 不會去重，一筆匹配多筆會產生多個結果；未匹配時只產生一個結果。
來源 iterator keys 不參與配對。

操作均延遲執行。leftJoin 首次讀取結果時緩衝右邊，之後串流讀取左邊；
rightJoin 緩衝左邊，串流讀取右邊。需要保留的一邊為空時，不讀取另一邊或呼叫 selector。
緩衝每次列舉重新建立，因此重複執行需要可重播來源；Generator 可用 `Enumerable::defer()` 建立。
來源、比較器及 selector 的例外會直接傳出。

相等規則沿用 Enumerable 的 `join()`／`groupJoin()`，可傳入 `EqualityComparer`。
預設區分 PHP 型別，array 嚴格相等，物件依 identity 比較；null key 可以匹配 null，
與 Queryable 關聯式 join 的 null 規則不同。不同 key 的 hash collision 會再用 `equals()` 分辨。
