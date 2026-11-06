# Closure translation

[文件目錄](README.md) · [Queryable 與運算式](queryable.md)

`Queryable::where()`、`select()` 及 `any()` 接受受限制的 PHP closure。
Translator 讀取 closure 所在的 PHP 原始檔，解析 AST，再建立 expression tree。
它不會呼叫 closure 來推斷查詢，也不會自動把 provider 查詢改成 PHP 逐筆篩選。
建立查詢時進行翻譯；列舉或終結操作才交給 provider 執行。

## 寫法與 expression tree

```php
$orders->where(fn($o) => $o->total + $o->tax >= 30);
$customers->where(fn($c) => str_starts_with($c->name, 'a'));
$customers->select(fn($c) => ['id' => $c->id, 'name' => $c->name]);
```

| PHP 寫法 | Expression |
| --- | --- |
| `$c->name` | `FieldExpression` |
| 字面值、捕獲的 scalar/null 變數 | `ConstantExpression` |
| `+ - * / %`、`== != > >= < <=` | `BinaryExpression` |
| `$c->name ?? 'Guest'` | `CoalesceExpression` |
| `$o->total >= 100 ? 'large' : 'small'` | `ConditionalExpression` |
| `&& || and or` | `LogicalExpression` |
| `!` | `NotExpression` |
| `str_starts_with`、`str_ends_with`、`str_contains` | `StringMatchExpression` |
| `strtolower`、`strtoupper`、`trim`、`strlen`、`abs`、`round` | `ScalarFunctionExpression` |
| 具明確字串 key 的非空投影陣列 | `ProjectionExpression` |
| 捕獲的 query 上的 `contains()` / `any()` | `ContainsExpression` / `AnyExpression` |

算術保留 PHP AST 的優先次序及括號。字串函式接受兩個 positional arguments，
可以是欄位、字面值或捕獲變數；不支援 named arguments 或 argument unpacking。
`%`、`_` 是搜尋字串的字面字元，不是 wildcard。

```php
$needle = '%';
$customers->where(fn($c) => str_contains($c->name, $needle));
$customers->where(fn($c) => str_contains($c->name, $c->search_term));
```

## Provider 負責執行或翻譯

`InMemoryQueryProvider` 直接執行這些節點；字串比對使用 PHP 的大小寫敏感函式。
Null 字串或算術 operand 會產生 null；字串比對的 null 在否定及邏輯運算下維持
UNKNOWN，`where()` 不會保留這些 row。空 needle 匹配所有非 null 字串。

SQL 翻譯屬於 `mathsgod/light-entity`。例如：

```php
$db->set(Customers::class)
    ->where(fn($c) => str_starts_with($c->name, 'a'));
```

概念上對應 `WHERE name LIKE 'a%'`；實際透過 `LikeExpression` 輸出
`WHERE (name LIKE :p0 ESCAPE :p1)`，其中 `p0 = 'a%'`、`p1 = '!'`。
SQL provider 負責 escaping、參數、方言及資料庫的 collation/NULL 規則。
SQL 大小寫比較、整數除法及除以零的行為可能與記憶體 provider 不同。
自訂 provider 必須處理新增的節點及運算子，或明確拒絕。

## Closure 限制

- 支援 arrow function，或只有一個 `return` expression 的 anonymous function。
  必須只有一個普通 row parameter；anonymous function 使用 `use` 捕獲外部值。
- 支援 property paths、scalar/null 字面值及捕獲變數；不支援動態 property。
- `===`、`!==`、任意函式／方法呼叫、賦值及迴圈不在支援範圍內。
- `contains()`、`any()` 的 receiver 必須是捕獲的 `IQueryable`，且與外層共用
  provider。先建立子查詢，再放入 closure；不支援 closure 內的 query-building chain。
- 原始檔必須可讀；不支援 `eval` 產生的 closure。同一行不要放多個參數相同的
  closure，避免 reflection 的行號範圍無法唯一定位。
- 捕獲值在每次翻譯時讀取，而不是列舉時讀取。快取 AST 不會快取舊的捕獲值。

不支援的語法會在建立查詢時拋出 `ClosureTranslationException`，沒有自動 client-side
fallback。一般 PHP callback 請放在 `Enumerable` 或 `asEnumerable()` 之後。
`orderBy()` 等仍使用 `ValueExpression`，不能推斷所有 Queryable 方法都接受 closure。

## Null fallback 與條件投影

```php
$orders->select(fn($o) => [
    'customer' => $o->customer_name ?? 'Guest',
    'size' => $o->total >= 100 ? 'large' : 'small',
]);
```

`??` 只在左側為 null 時取右側；`0`、`false`、空字串都會保留。
欄位仍須存在於 row/schema；這不是繞過欄位驗證的方式。
完整三元運算及 `??` 可以巢狀使用；記憶體 provider 只執行選中的分支。
條件為 null 時選擇 false 分支。簡寫 `value ?: fallback` 不支援，會明確報錯。

顯式建樹可用 `Expr::coalesce(Expr::field('name'), 'Guest')` 及
`Expr::condition(Expr::gte(Expr::field('total'), 100), 'large', 'small')`。
它們接受 expression；fallback／分支亦接受常數。
SQL provider 將這些節點輸出為 `COALESCE` 及 `CASE WHEN`，SQL 條件須是 provider
支援的 predicate，分支型別亦須符合資料庫規則。

## Scalar functions

```php
$customers->where(fn($c) => strtolower(trim($c->name)) == 'alice');
$customers->select(fn($c) => strlen($c->name));
$orders->select(fn($o) => round(abs($o->balance), 2));
```

以上六個函式各接受一個 positional argument，`round` 可再接受常數整數 precision。
函式可以巢狀組合，亦可出現在投影、條件及關聯子查詢內。使用顯式 API 時可以建立
`new ScalarFunctionExpression('abs', [Expr::field('balance')])`。

記憶體 provider 使用對應的 PHP 函式，但 NULL input 會保留為 NULL。
`strlen` 計算 bytes；例如 UTF-8 `strlen('中')` 為 3，而不是 1。
`strtolower`／`strtoupper` 使用 PHP 的 ASCII 大小寫規則；`trim` 使用 PHP 預設空白集。
`round` 預設 precision 為 0，使用 `PHP_ROUND_HALF_UP`；支援負 precision。

不支援 `trim` 自訂 character mask、`round` 第三個 mode argument、欄位運算 precision、
named arguments 或 unpacking；這些寫法在 translation 時明確拋出
`ClosureTranslationException`。捕獲的整數 precision 支援，例如
`fn($o) => round($o->balance, $precision)`。

SQL provider 負責函式方言；SQL 大小寫規則、儲存編碼、數值精度、overflow 及回傳型別
可能不同。light-entity 的 `strlen` 使用 byte-length SQL，而非 character count；
SQL Server 的 NVARCHAR 等不同儲存編碼，不保證等於 PHP 中 UTF-8 字串的 byte 數。
詳見 light-entity 的 closure translation 文件。
