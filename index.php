<?php
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

    public function getBasketServer(int $nmId): string
    {
        $vol = intdiv($nmId, 100000);

        if ($vol <= 143)   return 'basket-01';
        if ($vol <= 287)   return 'basket-02';
        if ($vol <= 431)   return 'basket-03';
        if ($vol <= 719)   return 'basket-04';
        if ($vol <= 1007)  return 'basket-05';
        if ($vol <= 1061)  return 'basket-06';
        if ($vol <= 1115)  return 'basket-07';
        if ($vol <= 1169)  return 'basket-08';
        if ($vol <= 1313)  return 'basket-09';
        if ($vol <= 1601)  return 'basket-10';
        if ($vol <= 1655)  return 'basket-11';
        if ($vol <= 1919)  return 'basket-12';
        if ($vol <= 2045)  return 'basket-13';
        if ($vol <= 2189)  return 'basket-14';
        if ($vol <= 2405)  return 'basket-15';
        if ($vol <= 2621)  return 'basket-16';
        if ($vol <= 2837)  return 'basket-17';
        return 'basket-18';
    }

    public function getBaseUrl(int $nmId): string
    {
        $vol = intdiv($nmId, 100000);
        $part = intdiv($nmId, 1000);
        $basket = $this->getBasketServer($nmId);

        return sprintf(
            'https://%s.wbbasket.ru/vol%d/part%d/%d',
            $basket,
            $vol,
            $part,
            $nmId
        );
    }

    public function fetchCard(int $nmId): array
    {
        $baseUrl = $this->getBaseUrl($nmId);
        $url = $baseUrl . '/info/ru/card.json';

        $response = $this->client->get($url);
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Не удалось разобрать ответ WB');
        }

        return $data;
    }

    public function getImageUrls(int $nmId, int $count = 5): array
    {
        $baseUrl = $this->getBaseUrl($nmId);
        $images = [];

        for ($i = 1; $i <= $count; $i++) {
            $images[] = $baseUrl . '/images/big/' . $i . '.webp';
        }

        return $images;
    }

    public function parse(int $nmId): array
    {
        $card = $this->fetchCard($nmId);

        $options = [];
        if (!empty($card['grouped_options'])) {
            foreach ($card['grouped_options'] as $group) {
                if (!empty($group['options'])) {
                    foreach ($group['options'] as $option) {
                        $options[] = [
                            'name' => $option['name'] ?? '',
                            'value' => $option['value'] ?? '',
                        ];
                    }
                }
            }
        }

        return [
            'nm_id'       => $card['nm_id'] ?? $nmId,
            'name'        => $card['imt_name'] ?? 'Без названия',
            'brand'       => $card['selling']['brand_name'] ?? '',
            'category'    => $card['subj_name'] ?? $card['subj_root_name'] ?? '',
            'description' => $card['description'] ?? '',
            'options'     => $options,
            'images'      => $this->getImageUrls($nmId, 5),
            'wb_url'      => 'https://www.wildberries.ru/catalog/' . $nmId . '/detail.aspx',
        ];
    }
}

// AJAX-роутинг
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    || isset($_GET['ajax']);

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    $nmId = isset($_GET['nm']) ? (int) $_GET['nm'] : (isset($_POST['nm']) ? (int) $_POST['nm'] : 0);

    if ($nmId < 1) {
        echo json_encode(['success' => false, 'error' => 'Некорректный артикул'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $parser = new WbParser();
        $data = $parser->parse($nmId);
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    } catch (\GuzzleHttp\Exception\ConnectException $e) {
        echo json_encode(['success' => false, 'error' => 'Сервер WB не отвечает, попробуйте позже'], JSON_UNESCAPED_UNICODE);
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        if ($e->getResponse()->getStatusCode() === 404) {
            echo json_encode(['success' => false, 'error' => 'Товар не найден (артикул: ' . $nmId . ')'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ошибка при запросе к WB'], JSON_UNESCAPED_UNICODE);
        }
    } catch (\RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Произошла неизвестная ошибка'], JSON_UNESCAPED_UNICODE);
    }

    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WB Parser — Парсер карточки товара Wildberries</title>
    <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@400;700&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:        #0d0d0d;
            --surface:   #1a1a1a;
            --border:    #2a2a2a;
            --accent:    #cb11ab;
            --accent-2:  #ff4081;
            --text:      #f0f0f0;
            --muted:     #888;
            --error:     #ff4444;
            --success:   #00c851;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 40px 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Unbounded', sans-serif;
            font-weight: 700;
            font-size: 24px;
            color: white;
        }

        .logo-text {
            font-family: 'Unbounded', sans-serif;
            font-weight: 700;
            font-size: 28px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .subtitle {
            color: var(--muted);
            font-size: 14px;
            font-weight: 300;
        }

        /* Search Form */
        .search-form {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
        }

        .search-input {
            flex: 1;
            padding: 14px 20px;
            font-size: 16px;
            font-family: 'Inter', sans-serif;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .search-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(203, 17, 171, 0.15);
        }

        .search-input::placeholder {
            color: var(--muted);
        }

        .search-btn {
            padding: 14px 28px;
            font-size: 16px;
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            border: none;
            border-radius: 12px;
            color: white;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(203, 17, 171, 0.3);
        }

        .search-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Error Block */
        .error-block {
            background: rgba(255, 68, 68, 0.1);
            border: 1px solid var(--error);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--error);
            margin-bottom: 30px;
            animation: fadeInUp 0.3s ease;
        }

        .error-icon {
            font-size: 24px;
        }

        /* Result Card */
        .result-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            animation: fadeInUp 0.4s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-body {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 30px;
            padding: 30px;
        }

        /* Gallery */
        .gallery {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .main-image {
            width: 100%;
            aspect-ratio: 1;
            background: var(--bg);
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .main-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-placeholder {
            font-size: 48px;
            color: var(--muted);
        }

        .thumbnails {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 4px;
        }

        .thumbnail {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color 0.2s, transform 0.2s;
            flex-shrink: 0;
        }

        .thumbnail:hover {
            transform: scale(1.1);
        }

        .thumbnail.active {
            border-color: var(--accent);
        }

        .thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Product Info */
        .product-info {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .product-header h2 {
            font-family: 'Unbounded', sans-serif;
            font-weight: 700;
            font-size: 22px;
            margin-bottom: 8px;
            line-height: 1.3;
        }

        .product-meta {
            color: var(--muted);
            font-size: 14px;
            margin-bottom: 16px;
        }

        .product-meta span {
            color: var(--text);
        }

        .wb-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            border: none;
            border-radius: 10px;
            color: white;
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            width: fit-content;
        }

        .wb-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(203, 17, 171, 0.3);
        }

        /* Description */
        .description {
            background: var(--bg);
            border-radius: 12px;
            padding: 20px;
        }

        .description-title {
            font-family: 'Unbounded', sans-serif;
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 12px;
        }

        .description-text {
            color: var(--text);
            line-height: 1.6;
            font-size: 14px;
        }

        .description-text.collapsed {
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .expand-btn {
            background: none;
            border: none;
            color: var(--accent);
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            cursor: pointer;
            margin-top: 12px;
            padding: 0;
            transition: opacity 0.2s;
        }

        .expand-btn:hover {
            opacity: 0.8;
        }

        /* Characteristics */
        .characteristics {
            border-top: 1px solid var(--border);
            padding: 30px;
        }

        .characteristics-title {
            font-family: 'Unbounded', sans-serif;
            font-weight: 700;
            font-size: 18px;
            margin-bottom: 20px;
        }

        .options-table {
            width: 100%;
            border-collapse: collapse;
        }

        .options-table tr {
            border-bottom: 1px solid var(--border);
        }

        .options-table tr:last-child {
            border-bottom: none;
        }

        .options-table td {
            padding: 12px 0;
            font-size: 14px;
        }

        .options-table td:first-child {
            color: var(--muted);
            width: 40%;
        }

        .options-table td:last-child {
            color: var(--text);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .card-body {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .search-form {
                flex-direction: column;
            }

            .search-btn {
                justify-content: center;
            }

            .logo-text {
                font-size: 22px;
            }

            .logo-icon {
                width: 40px;
                height: 40px;
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="logo">
                <div class="logo-icon">W</div>
                <div class="logo-text">WB Parser</div>
            </div>
            <p class="subtitle">Получи данные о любом товаре Wildberries</p>
        </header>

        <form class="search-form" id="searchForm">
            <input 
                type="text" 
                class="search-input" 
                id="nmInput" 
                placeholder="Введите артикул товара, например: 154109138"
                pattern="[0-9]{5,12}"
                autocomplete="off"
            >
            <button type="submit" class="search-btn" id="searchBtn">
                <span>Найти товар</span>
            </button>
        </form>

        <div id="resultContainer"></div>
    </div>

    <script>
        const searchForm = document.getElementById('searchForm');
        const nmInput = document.getElementById('nmInput');
        const searchBtn = document.getElementById('searchBtn');
        const resultContainer = document.getElementById('resultContainer');

        let currentImages = [];

        searchForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const nmId = nmInput.value.trim();

            if (!/^\d{5,12}$/.test(nmId)) {
                renderError('Некорректный артикул: введите число от 5 до 12 цифр');
                return;
            }

            await searchProduct(parseInt(nmId, 10));
        });

        async function searchProduct(nmId) {
            searchBtn.disabled = true;
            searchBtn.innerHTML = '<div class="spinner"></div><span>Загружаю...</span>';
            resultContainer.innerHTML = '';

            try {
                const response = await fetch('/index.php?ajax=1&nm=' + nmId);
                const result = await response.json();

                if (result.success) {
                    renderProduct(result.data);
                } else {
                    renderError(result.error);
                }
            } catch (error) {
                renderError('Произошла ошибка при запросе к серверу');
            } finally {
                searchBtn.disabled = false;
                searchBtn.innerHTML = '<span>Найти товар</span>';
            }
        }

        function renderError(message) {
            resultContainer.innerHTML = `
                <div class="error-block">
                    <span class="error-icon">⚠️</span>
                    <span>${escapeHtml(message)}</span>
                </div>
            `;
        }

        function renderProduct(data) {
            currentImages = data.images || [];
            const hasImages = currentImages.length > 0;
            const hasDescription = data.description && data.description.trim();
            const hasOptions = data.options && data.options.length > 0;

            const descriptionPreview = hasDescription 
                ? (data.description.length > 200 ? data.description.substring(0, 200) + '...' : data.description)
                : '';
            const needsExpand = hasDescription && data.description.length > 200;

            let optionsHtml = '';
            if (hasOptions) {
                optionsHtml = `
                    <div class="characteristics">
                        <h3 class="characteristics-title">Характеристики</h3>
                        <table class="options-table">
                            ${data.options.map(opt => `
                                <tr>
                                    <td>${escapeHtml(opt.name)}</td>
                                    <td>${escapeHtml(opt.value)}</td>
                                </tr>
                            `).join('')}
                        </table>
                    </div>
                `;
            }

            resultContainer.innerHTML = `
                <div class="result-card">
                    <div class="card-body">
                        <div class="gallery">
                            <div class="main-image" id="mainImage">
                                ${hasImages 
                                    ? `<img src="${escapeHtml(data.images[0])}" alt="${escapeHtml(data.name)}" onerror="this.parentElement.innerHTML='<span class=\\'image-placeholder\\'>🖼️</span>'">`
                                    : '<span class="image-placeholder">🖼️</span>'
                                }
                            </div>
                            ${hasImages ? `
                                <div class="thumbnails">
                                    ${data.images.map((img, idx) => `
                                        <div class="thumbnail ${idx === 0 ? 'active' : ''}" data-index="${idx}">
                                            <img src="${escapeHtml(img)}" alt="" onerror="this.parentElement.style.display='none'">
                                        </div>
                                    `).join('')}
                                </div>
                            ` : ''}
                        </div>

                        <div class="product-info">
                            <div class="product-header">
                                <h2>${escapeHtml(data.name)}</h2>
                                <p class="product-meta">
                                    ${data.brand ? `<span>${escapeHtml(data.brand)}</span> • ` : ''}
                                    ${data.category ? `<span>${escapeHtml(data.category)}</span>` : ''}
                                </p>
                                <a href="${escapeHtml(data.wb_url)}" target="_blank" class="wb-btn">
                                    🛒 Открыть на WB
                                </a>
                            </div>

                            ${hasDescription ? `
                                <div class="description">
                                    <h3 class="description-title">Описание</h3>
                                    <div class="description-text collapsed" id="descriptionText">
                                        ${escapeHtml(data.description)}
                                    </div>
                                    ${needsExpand ? `
                                        <button class="expand-btn" id="expandBtn" onclick="toggleDescription()">Показать полностью</button>
                                    ` : ''}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                    ${optionsHtml}
                </div>
            `;

            initGallery();
        }

        function initGallery() {
            const thumbnails = document.querySelectorAll('.thumbnail');
            const mainImage = document.getElementById('mainImage');

            thumbnails.forEach(thumb => {
                thumb.addEventListener('click', () => {
                    const index = parseInt(thumb.dataset.index, 10);
                    
                    thumbnails.forEach(t => t.classList.remove('active'));
                    thumb.classList.add('active');

                    if (currentImages[index]) {
                        mainImage.innerHTML = `<img src="${escapeHtml(currentImages[index])}" alt="" onerror="this.parentElement.innerHTML='<span class=\\'image-placeholder\\'>🖼️</span>'">`;
                    }
                });
            });
        }

        function toggleDescription() {
            const text = document.getElementById('descriptionText');
            const btn = document.getElementById('expandBtn');
            const isExpanded = !text.classList.contains('collapsed');

            if (isExpanded) {
                text.classList.add('collapsed');
                btn.textContent = 'Показать полностью';
            } else {
                text.classList.remove('collapsed');
                btn.textContent = 'Свернуть';
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    </script>
</body>
</html>
