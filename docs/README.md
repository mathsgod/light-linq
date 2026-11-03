# light-linq 使用指南

light-linq 是 PHP 的 LINQ 風格查詢函式庫，支援集合查詢、expression tree、query provider及泛型風格集合。本指南以專案目前實作為準，並非所有 Microsoft LINQ API 都適用。

## 閱讀順序

1. [安裝與入門](getting-started.md)：環境、第一個查詢、延遲執行。
2. [Enumerable 集合查詢](enumerable.md)：篩選、投影、排序、分頁、聚合及集合運算。
3. [分組與關聯查詢](grouping-and-joins.md)：groupBy、join、groupJoin、自訂 key 比較。
4. [Queryable 與運算式](queryable.md)：provider、Expr、分組聚合及查詢組合。
5. [集合類別](collections.md)：GenericList、Dictionary、HashSet、Queue、Stack。


XML 功能已移至獨立套件 [light-xml](../../light-xml/README.md)，使用 `Light\Xml\Linq` namespace。

## 應該用哪一種查詢？

| 情況 | API |
| --- | --- |
| 已有 PHP array、iterable 或物件集合 | `Enumerable::from()`，使用 PHP callback |
| 需要交由 provider 執行或翻譯查詢 | `Queryable::from()`，使用 `Expr` |
| provider 查詢後需要一般 PHP 處理 | `asEnumerable()` 後接 callback 操作 |

SQL 編譯及 PDO provider 位於獨立套件 `mathsgod/php-entity`，不屬於 light-linq 的資料庫執行功能。

[返回專案 README](../README.md)
