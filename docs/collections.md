# 集合類別

[文件目錄](README.md) · [Enumerable](enumerable.md)

類別位於 `Light\Linq\Collections\Generic`，實作可列舉集合介面，可直接交給 `Enumerable::from()`。泛型型別使用 PHPDoc 表達，不是 PHP 原生執行期泛型。

## GenericList：可變有序集合

```php
use Light\Linq\Collections\Generic\GenericList;
use Light\Linq\Enumerable;

$list = new GenericList([1, 2]);
$list->add(3);
$list->insert(0, 0);
$list->insertRange(1, [10, 20]);
$slice = $list->getRange(1, 2)->toArray(); // [10, 20]
$list->removeRange(1, 2);
$list->set(0, 5);
$first = $list->get(0); // 5
$filtered = Enumerable::from($list)->where(fn ($n) => $n > 1)->toArray();
```

PHP 保留 `list` 關鍵字，因此類別命名為 `GenericList`。也支援 array index 存取、`remove()`、`removeAt()`、`contains()`、`indexOf()`、`clear()`、`count()` 及 `toArray()`。索引由 0 開始。

## Dictionary：自訂 key 型別與比較

```php
use Light\Linq\Collections\Generic\Dictionary;

$scores = new Dictionary();
$scores->add('Ada', 10);
$scores['Ada'] = 15;
$added = $scores->tryAdd('Ada', 20); // false
$found = $scores->tryGetValue('Ada', $score); // true，$score 是 15
$names = $scores->keys(); // ['Ada']

foreach ($scores as $pair) {
    $key = $pair->key;
    $value = $pair->value;
}
```

`add()` 遇到重複 key 會拋出 `InvalidArgumentException`；`set()` 或 indexer 會新增或覆寫。`get()` 找不到 key 會拋出 `KeyNotFoundException`；可用 `getOrDefault($key, $default)` 或 `tryGetValue()`。

Dictionary 不接受 null key。列舉及 `toArray()` 回傳 `KeyValuePair`，不是 PHP associative array，因此能支援物件 key。另有 `containsKey()`、`containsValue()`、`values()`、`remove()`、`clear()` 及 `count()`。建構子可傳入 `EqualityComparer`。

## HashSet：唯一元素與原地集合運算

```php
use Light\Linq\Collections\Generic\HashSet;

$set = new HashSet([1, 2, 2]);
$inserted = $set->tryAdd(2); // false
$set->unionWith([2, 3]);
$set->exceptWith([1]);
$hasTwo = $set->contains(2); // true
$equal = $set->setEquals([2, 3]); // true
```

`unionWith()`、`intersectWith()`、`exceptWith()`、`symmetricExceptWith()` 會修改集合本身；Enumerable 的同類操作則產生查詢結果。另有子集／超集判斷、`overlaps()`、`remove()`、`clear()`、`count()` 及 `toArray()`。

預設相等比較區分型別，物件比較 identity。可在建構子的第二個參數傳入自訂 `EqualityComparer`；[分組指南](grouping-and-joins.md) 有完整 comparer 範例。

## Queue 與 Stack

```php
use Light\Linq\Collections\Generic\Queue;
use Light\Linq\Collections\Generic\Stack;

$queue = new Queue(['first', 'second']);
$queue->enqueue('third');
$next = $queue->dequeue(); // 'first'：先入先出

$stack = new Stack(['first', 'second']);
$stack->push('third');
$top = $stack->pop(); // 'third'：後入先出
```

兩者都有 `peek()`（只看不取）、`clear()`、`count()`、`toArray()`。空集合的取出及 peek 會拋出 `UnderflowException`；需要不拋例外的取出方式，可使用 `tryDequeue($value)` 或 `tryPop($value)`，以回傳 bool 判斷是否成功。
