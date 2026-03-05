# Промпт для Qwen Code: WB Parser — веб-приложение на PHP 8.4

## Задача
Создай веб-приложение на чистом PHP 8.4 — парсер карточки товара Wildberries.
Пользователь вводит артикул (nmID), нажимает кнопку, получает карточку товара с фото,
названием, ценой, рейтингом и характеристиками. Всё в одном файле `index.php`.

---

## Технический стек
- PHP 8.4
- Composer: `guzzlehttp/guzzle ^7.10`
- Один файл `index.php` — и бэкенд, и фронтенд
- Никаких фреймворков, никаких сторонних CSS-библиотек
- Чистый HTML5 + CSS3 + Vanilla JS (fetch API для AJAX)
- Строгая типизация PHP (`declare(strict_types=1)`)

---

## Как работает API Wildberries (без токена!)

Используется публичный CDN Wildberries — `wbbasket.ru`.

### Алгоритм по артикулу (nmID):

**Шаг 1 — вычислить VOL и PART:**
```
VOL  = (int)($nmID / 100000)
PART = (int)($nmID / 1000)
```

**Шаг 2 — определить номер сервера (XX) по таблице VOL:**
```
VOL <= 143   → basket-01
VOL <= 287   → basket-02
VOL <= 431   → basket-03
VOL <= 719   → basket-04
VOL <= 1007  → basket-05
VOL <= 1061  → basket-06
VOL <= 1115  → basket-07
VOL <= 1169  → basket-08
VOL <= 1313  → basket-09
VOL <= 1601  → basket-10
VOL <= 1655  → basket-11
VOL <= 1919  → basket-12
VOL <= 2045  → basket-13
VOL <= 2189  → basket-14
VOL <= 2405  → basket-15
VOL <= 2621  → basket-16
VOL <= 2837  → basket-17
иначе        → basket-18
```

**Шаг 3 — сформировать URL к card.json:**
```
https://basket-{XX}.wbbasket.ru/vol{VOL}/part{PART}/{nmID}/info/ru/card.json
```

**Пример для артикула 154109138:**
- VOL = 1541, PART = 154109, сервер = basket-10
- URL: `https://basket-10.wbbasket.ru/vol1541/part154109/154109138/info/ru/card.json`

**Шаг 4 — URL изображений:**
```
https://basket-{XX}.wbbasket.ru/vol{VOL}/part{PART}/{nmID}/images/big/1.webp
https://basket-{XX}.wbbasket.ru/vol{VOL}/part{PART}/{nmID}/images/big/2.webp
...до N штук (проверять через HEAD-запрос или брать первые 5)
```

### Структура ответа card.json:
```json
{
  "nm_id": 154109138,
  "imt_name": "Название товара",
  "subj_name": "Категория",
  "subj_root_name": "Родительская категория",
  "selling": {
    "brand_name": "Бренд",
    "supplier": "Поставщик"
  },
  "grouped_options": [
    {
      "group_name": "Основное",
      "options": [
        {"name": "Цвет", "value": "Белый"},
        {"name": "Состав", "value": "100% хлопок"}
      ]
    }
  ],
  "description": "Полное описание товара..."
}
```

> ⚠️ Цены и остатки в `card.json` **отсутствуют** — только мета-данные и характеристики.
> Цену можно попробовать взять через дополнительный запрос к `price.wb.ru` или отобразить
> ссылку на товар WB. Если цену получить не удастся — просто не показывай её, не ломай UX.

---

## Архитектура файла index.php

Файл работает в двух режимах:

### Режим 1 — AJAX-запрос (PHP бэкенд)
Если в запросе есть заголовок `X-Requested-With: XMLHttpRequest` **или** GET-параметр `ajax=1`:
- Получить `nm` из GET/POST
- Валидировать: nmID должен быть целым положительным числом
- Сделать запрос к `wbbasket.ru` через Guzzle с правильными заголовками
- Вернуть JSON: `{"success": true, "data": {...}}` или `{"success": false, "error": "..."}`

### Режим 2 — Обычный GET (HTML страница)
Отдать красивый HTML с формой ввода и JS-логикой.

---

## PHP-класс WbParser (внутри index.php)

```php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

class WbParser
{
    private \GuzzleHttp\Client $client;

    public function __construct()
    {
        $this->client = new \GuzzleHttp\Client([
            'timeout' => 10,
            'headers' => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                'Accept'          => 'application/json, text/plain, */*',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Referer'         => 'https://www.wildberries.ru/',
            ],
            'decode_content' => true,
        ]);
    }

    public function getBasketServer(int $nmId): string { /* таблица */ }
    public function getBaseUrl(int $nmId): string       { /* вычислить vol/part/basket */ }
    public function fetchCard(int $nmId): array         { /* GET card.json */ }
    public function getImageUrls(int $nmId, int $count = 5): array { /* массив URL */ }
    public function parse(int $nmId): array             { /* склеить всё + ссылка на WB */ }
}
```

### Метод parse() возвращает:
```php
[
    'nm_id'        => 154109138,
    'name'         => 'Название товара',
    'brand'        => 'Бренд',
    'category'     => 'Категория',
    'description'  => 'Описание...',
    'options'      => [['name' => 'Цвет', 'value' => 'Белый'], ...],
    'images'       => ['https://...1.webp', 'https://...2.webp'],
    'wb_url'       => 'https://www.wildberries.ru/catalog/154109138/detail.aspx',
]
```

---

## Обработка ошибок

| Ситуация | Ответ |
|---|---|
| nmID не число / меньше 1 | `{"success": false, "error": "Некорректный артикул"}` |
| HTTP ошибка от wbbasket.ru | `{"success": false, "error": "Товар не найден (артикул: XXXX)"}` |
| Таймаут | `{"success": false, "error": "Сервер WB не отвечает, попробуйте позже"}` |
| Невалидный JSON от WB | `{"success": false, "error": "Не удалось разобрать ответ WB"}` |

---

## Дизайн фронтенда

Стиль: **тёмный, современный, минималистичный**. Без Bootstrap, без Tailwind.

### Цветовая палитра:
```css
--bg:        #0d0d0d;
--surface:   #1a1a1a;
--border:    #2a2a2a;
--accent:    #cb11ab;   /* фирменный пурпурный WB */
--accent-2:  #ff4081;
--text:      #f0f0f0;
--muted:     #888;
```

### Шрифты (Google Fonts):
```html
<link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@400;700&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
```
- Заголовок `h1` / лого — `Unbounded`
- Всё остальное — `Inter`

### Компоненты страницы:

**1. Header**
- Логотип: буква `W` в розовом квадрате + текст `WB Parser`
- Подзаголовок: `Получи данные о любом товаре Wildberries`

**2. Форма поиска**
```html
<input type="text" placeholder="Введите артикул товара, например: 154109138" />
<button>Найти товар</button>
```
- Поле и кнопка на одной строке, стилизованы под WB-бренд
- При клике кнопка показывает спиннер + текст `Загружаю...`
- Кнопка блокируется во время загрузки
- Нажатие Enter в поле тоже запускает поиск

**3. Блок результата (появляется после загрузки)**

Структура карточки:
```
┌─────────────────────────────────────────────────┐
│  [Галерея фото]    │  Название товара            │
│  (слева)           │  Бренд • Категория          │
│                    │                             │
│  [миниатюры]       │  [Кнопка: Открыть на WB]   │
│                    │                             │
│                    │  Описание (expandable)      │
├─────────────────────────────────────────────────┤
│  Характеристики (таблица: параметр | значение)  │
└─────────────────────────────────────────────────┘
```

**Галерея фото:**
- Главное фото крупно (350x350 или адаптивно)
- Под ним — миниатюры (50x50), при клике меняется главное фото
- Если изображение не загрузилось — показать плейсхолдер с иконкой 🖼️

**Описание:**
- Показать первые 200 символов
- Кнопка `Показать полностью` / `Свернуть`

**4. Блок ошибки** (если запрос не удался)
- Красный/розовый блок с иконкой ⚠️ и текстом ошибки

**5. Анимации:**
- Блок результата появляется с `fadeInUp` анимацией
- Загрузка: пульсирующий спиннер в цвете `--accent`
- Hover на миниатюрах: scale(1.1) + border accent

---

## JS-логика (Vanilla JS, внутри `<script>`)

```javascript
async function searchProduct(nmId) {
    // 1. Показать спиннер, скрыть старый результат
    // 2. fetch('/index.php?ajax=1&nm=' + nmId)
    // 3. Получить JSON
    // 4. Если success: true — отрисовать карточку через renderProduct(data)
    // 5. Если success: false — отрисовать блок ошибки
    // 6. Скрыть спиннер
}

function renderProduct(data) {
    // Динамически построить HTML карточки
    // Инициализировать галерею
    // Инициализировать expandable описание
}
```

---

## composer.json

```json
{
    "name": "wb-parser/web",
    "require": {
        "php": "^8.4",
        "guzzlehttp/guzzle": "^7.10"
    },
    "autoload": {
        "psr-4": {}
    }
}
```

---

## Что нужно сгенерировать

Один файл `index.php`, содержащий:
1. PHP-секцию вверху: класс `WbParser` + логика AJAX-роутинга + отдача JSON
2. HTML-страницу: форма + блок результата
3. CSS: весь стиль инлайн в `<style>`
4. JS: вся логика инлайн в `<script>`

Файл должен быть полностью рабочим после `composer install`.

---

## Важные нюансы

1. `wbbasket.ru` не требует авторизации — только правильный `User-Agent`
2. Изображения с `wbbasket.ru` можно показывать напрямую через `<img src="...">`
3. Не пытайся парсить цену — в `card.json` её нет, лучше просто показать кнопку `Открыть на WB`
4. На мобиле галерея должна адаптироваться: фото сверху, текст снизу
5. Валидировать артикул: только цифры, от 5 до 12 символов
6. CORS не нужен — всё на одном сервере