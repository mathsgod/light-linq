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
