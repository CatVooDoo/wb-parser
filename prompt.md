# Промпт для Qwen Code: добавить цену товара в WB Parser

## Контекст
Уже есть рабочий `index.php` — парсер карточки товара Wildberries.
Данные берутся с `wbbasket.ru` (card.json). Цены там нет.
Нужно **добавить получение цены** через второй запрос к `search.wb.ru`.

Не переписывай весь файл. Внеси точечные изменения в существующий код.

---

## Цель
Получить для товара:
- `price_basic` — базовая цена до скидки (руб)
- `price_sale` — цена со скидкой (руб)
- `sale_percent` — процент скидки

---

## Endpoint для получения цены

```
GET https://search.wb.ru/exactmatch/ru/common/v18/search
    ?appType=1
    &curr=rub
    &dest=-1257786
    &lang=ru
    &page=1
    &query={nmId}
    &resultset=catalog
    &sort=popular
    &spp=30
```

Передаём **числовой артикул** как текстовый `query`.
В ответе приходит массив `data.products[]`.
Нужно найти элемент где `product.id === nmId`.

### Структура цены в ответе:
```json
{
  "id": 154109138,
  "sizes": [
    {
      "price": {
        "basic": 160000,
        "product": 89900,
        "logistics": 300,
        "return": 0
      }
    }
  ]
}
```

- `sizes[0].price.basic` — цена до скидки (делить на 100)
- `sizes[0].price.product` — цена со скидкой (делить на 100)
- Процент скидки: `round((basic - product) / basic * 100)`

---

## Что добавить в PHP-класс WbParser

### Новый метод `fetchPrice(int $nmId): array`

```php
private function fetchPrice(int $nmId): array
{
    // Случайная задержка 1-3 секунды — обязательно!
    // Без неё search.wb.ru вернёт 429
    $delay = random_int(1000000, 3000000); // микросекунды
    usleep($delay);

    $url = 'https://search.wb.ru/exactmatch/ru/common/v18/search';

    $params = [
        'appType'    => '1',
        'curr'       => 'rub',
        'dest'       => '-1257786',
        'lang'       => 'ru',
        'page'       => '1',
        'query'      => (string)$nmId,
        'resultset'  => 'catalog',
        'sort'       => 'popular',
        'spp'        => '30',
    ];

    // Retry-логика: до 3 попыток
    $attempts = 3;
    $lastException = null;

    for ($i = 0; $i < $attempts; $i++) {
        try {
            $response = $this->client->get($url, [
                'query' => $params,
                'headers' => [
                    // Важно: другой User-Agent чем для wbbasket
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/121.0.0.0 Safari/537.36',
                    'Accept'          => 'application/json, text/plain, */*',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Referer'         => 'https://www.wildberries.ru/',
                    'Origin'          => 'https://www.wildberries.ru',
                    'sec-ch-ua'       => '"Not_A Brand";v="8", "Chromium";v="121"',
                    'sec-fetch-dest'  => 'empty',
                    'sec-fetch-mode'  => 'cors',
                    'sec-fetch-site'  => 'same-site',
                ],
            ]);

            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            $products = $data['data']['products'] ?? [];

            // Ищем товар по id
            foreach ($products as $product) {
                if ((int)$product['id'] === $nmId) {
                    $sizes = $product['sizes'] ?? [];
                    $priceData = $sizes[0]['price'] ?? [];

                    $basic   = isset($priceData['basic'])   ? (int)$priceData['basic']   : 0;
                    $saleRaw = isset($priceData['product']) ? (int)$priceData['product'] : 0;

                    if ($basic === 0) {
                        return ['price_basic' => null, 'price_sale' => null, 'sale_percent' => null];
                    }

                    $priceBasic = round($basic / 100, 2);
                    $priceSale  = round($saleRaw / 100, 2);
                    $salePct    = (int)round(($basic - $saleRaw) / $basic * 100);

                    return [
                        'price_basic'  => $priceBasic,
                        'price_sale'   => $priceSale,
                        'sale_percent' => $salePct,
                    ];
                }
            }

            // Товар не найден в результатах — не ошибка, просто нет цены
            return ['price_basic' => null, 'price_sale' => null, 'sale_percent' => null];

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 429 — ждём и повторяем
            if ($e->getResponse()->getStatusCode() === 429) {
                usleep(random_int(2000000, 4000000)); // ещё 2-4 сек
                $lastException = $e;
                continue;
            }
            // Другая 4xx — не повторяем
            break;

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $lastException = $e;
            usleep(1000000);
            continue;

        } catch (\Exception $e) {
            $lastException = $e;
            break;
        }
    }

    // Все попытки провалились — логируем, но не ломаем парсер
    error_log("WbParser fetchPrice failed for nmId={$nmId}: " . ($lastException?->getMessage() ?? 'unknown'));
    return ['price_basic' => null, 'price_sale' => null, 'sale_percent' => null];
}
```

---

## Изменить метод parse()

В существующий метод `parse()` добавить вызов `fetchPrice()` и включить результат в возвращаемый массив:

```php
// Добавить после получения card.json:
$priceData = $this->fetchPrice($nmId);

// В return добавить:
return [
    // ... существующие поля ...
    'price_basic'  => $priceData['price_basic'],
    'price_sale'   => $priceData['price_sale'],
    'sale_percent' => $priceData['sale_percent'],
];
```

---

## Изменить JSON-ответ AJAX

В PHP-секции где формируется ответ — убедиться что `price_basic`, `price_sale`, `sale_percent` попадают в `data`.

---

## Изменить фронтенд (JS + HTML)

### В функции `renderProduct(data)` добавить блок с ценой:

**Логика отображения:**

```
Если price_sale !== null:
    Показать блок цены

Если sale_percent > 0:
    Показать перечёркнутую базовую цену + цену со скидкой + бейдж скидки

Если sale_percent === 0 или null:
    Показать только одну цену (price_sale или price_basic)

Если оба null:
    Показать блок "Цена недоступна" с ссылкой на WB
```

### HTML-структура блока цены (вставить перед кнопкой "Открыть на WB"):

```html
<!-- Если есть скидка -->
<div class="price-block">
  <span class="price-sale">89 900 ₽</span>
  <span class="price-basic">160 000 ₽</span>
  <span class="price-badge">-44%</span>
</div>

<!-- Если скидки нет -->
<div class="price-block">
  <span class="price-sale">89 900 ₽</span>
</div>

<!-- Если цена недоступна -->
<div class="price-unavailable">
  ⚠️ Цена временно недоступна — <a href="{wb_url}" target="_blank">смотрите на WB</a>
</div>
```

### CSS для блока цены (добавить в `<style>`):

```css
.price-block {
    display: flex;
    align-items: baseline;
    gap: 12px;
    margin: 16px 0;
}

.price-sale {
    font-size: 28px;
    font-weight: 700;
    font-family: 'Unbounded', sans-serif;
    color: #f0f0f0;
}

.price-basic {
    font-size: 16px;
    color: #888;
    text-decoration: line-through;
}

.price-badge {
    background: #cb11ab;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    font-family: 'Unbounded', sans-serif;
}

.price-unavailable {
    font-size: 14px;
    color: #888;
    margin: 12px 0;
    padding: 10px 14px;
    border: 1px solid #2a2a2a;
    border-radius: 8px;
}

.price-unavailable a {
    color: #cb11ab;
    text-decoration: none;
}
```

---

## Важные нюансы

1. **Задержка обязательна** — без `usleep()` перед запросом к `search.wb.ru` будет 429 практически всегда
2. **Не ломать при отсутствии цены** — если `fetchPrice()` вернул `null`, карточка всё равно показывается, просто без цены
3. **Цены x100** — не забыть делить на 100 при парсинге
4. **Фильтрация по id** — искать в массиве `products` именно тот товар, у которого `id === nmId`, а не брать первый попавшийся
5. **Таймаут** для запроса к `search.wb.ru` — установить отдельно, не больше 8 секунд
6. **Не кидать исключение** при 429 — тихо логировать и возвращать `null` цены, UX важнее