# 安裝與入門

[文件目錄](README.md) · [下一篇：Enumerable](enumerable.md)

## 環境與安裝

需要 PHP 8.3 或以上。在使用此函式庫的應用程式執行：

```sh
composer require mathsgod/php-linq
```

若正在開發此 repository，於專案根目錄執行 `composer install`。

## 第一個查詢

範例程式放在使用此套件的專案根目錄；其他章節的 PHP 範例亦需要先載入 Composer autoloader。每篇的程式區塊可由上至下閱讀及執行。

```php
require 'vendor/autoload.php';

use Light\Linq\Enumerable;

$users = [
    ['id' => 1, 'name' => 'Ada', 'age' => 36, 'active' => true],
    ['id' => 2, 'name' => 'Bob', 'age' => 22, 'active' => false],
    ['id' => 3, 'name' => 'Cat', 'age' => 28, 'active' => true],
];

$names = Enumerable::from($users)
    ->where(fn ($user) => $user['active'])
    ->orderBy(fn ($user) => $user['age'])
    ->select(fn ($user) => $user['name'])
    ->toArray();
// ['Cat', 'Ada']
```

`from()` 接受 `iterable`，例如 array、Generator、IteratorAggregate 和本套件的集合類別。結果實作 `IEnumerable`，可以繼續串接方法或用 `foreach` 列舉。`toArray()` 回傳從 0 開始的 list，不保留原始 iterator keys。

## 延遲執行與緩衝

`where()`、`select()` 等方法建立查詢時不讀取來源；`foreach`、`toArray()`、`count()`、`sum()` 等才會觸發執行。

`orderBy()`、`reverse()`、`groupBy()` 等需要先緩衝來源；`takeLast()` 亦會先讀完整個來源。延遲執行不代表每個方法都可以串流處理無限序列。

## 重複執行與 Generator

查詢不會自動快取結果。來源是 array 時通常可以重複列舉；同一個已消耗的 Generator 則不能保證重播。使用 `defer()`，在每次列舉時建立新的 Generator：

```php
$numbers = Enumerable::defer(static function (): iterable {
    yield 1;
    yield 2;
    yield 3;
});

$total = $numbers->sum(); // 6
$again = $numbers->toArray(); // [1, 2, 3]
```

若要固定結果供多次使用，可先 `toArray()`，再將該 array 傳入 `from()`。

## 開發測試

在 repository 根目錄安裝開發依賴後執行：

```sh
composer test
```
