<?php
/* Общие функции приёма заказов GASTRODOM. Подключается из api/order.php и api/check.php. */

declare(strict_types=1);

const GD_PRIVATE = __DIR__;

function gd_config(): array {
    static $c = null;
    if ($c === null) {
        $c = require GD_PRIVATE . '/config.php';
    }
    return $c;
}

function gd_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function gd_log(string $file, string $line): void {
    @file_put_contents(GD_PRIVATE . '/' . $file, date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/* ── данные покупателя ─────────────────────────────────── */

/** Телефон в любом виде → «+7 999 123-45-67». null — не похоже на российский мобильный/городской номер. */
function gd_phone(string $raw): ?string {
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 11 && ($d[0] === '8' || $d[0] === '7')) {
        $d = substr($d, 1);
    }
    if (strlen($d) !== 10) {
        return null;
    }
    return '+7 ' . substr($d, 0, 3) . ' ' . substr($d, 3, 3) . '-' . substr($d, 6, 2) . '-' . substr($d, 8, 2);
}

function gd_phone_digits(string $pretty): string {
    return substr(preg_replace('/\D+/', '', $pretty), -10);
}

/** «gastro-welcom», «Гастровелком»… → «GASTROWELCOM»: регистр, пробелы, дефисы и похожие русские буквы не важны. */
function gd_promo_norm(string $raw): string {
    $s = mb_strtoupper(preg_replace('/[\s\-_.,!?:;"\'«»()]+/u', '', $raw), 'UTF-8');
    return strtr($s, ['А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O',
                      'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X']);
}

function gd_clean(string $s, int $max): string {
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s));
    return mb_substr($s, 0, $max, 'UTF-8');
}

function gd_money(float $v): string {
    return number_format(round($v), 0, ',', ' ') . ' ₽';
}

function gd_weight(int $g): string {
    return $g < 1000 ? $g . ' г' : rtrim(rtrim(number_format($g / 1000, 1, ',', ''), '0'), ',') . ' кг';
}

/* ── каталог ───────────────────────────────────────────── */

function gd_catalog(): array {
    $raw = json_decode((string)file_get_contents(dirname(GD_PRIVATE) . '/api/catalog.json'), true);
    $by = [];
    foreach (($raw['items'] ?? []) as $it) {
        $by[$it['id']] = $it;
    }
    return $by;
}

/* ── хранилище заказов: SQLite в закрытой папке ─────────── */

function gd_db(): PDO {
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . GD_PRIVATE . '/orders.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        ts INTEGER NOT NULL,
        name TEXT, phone TEXT, phone_digits TEXT,
        address TEXT, wish_date TEXT, wish_time TEXT, comment TEXT,
        items_json TEXT NOT NULL, total REAL NOT NULL,
        promo TEXT, promo_status TEXT, discount REAL DEFAULT 0,
        ip_hash TEXT, notified TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS orders_phone ON orders(phone_digits)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS orders_ip ON orders(ip_hash, ts)');
    return $pdo;
}

function gd_ip_hash(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = trim(explode(',', $ip)[0]);
    return substr(hash('sha256', 'gastrodom|' . $ip), 0, 32);   // сам IP не храним
}

/* ── отправка уведомлений ──────────────────────────────── */

/** POST-запрос. Возвращает [код ответа, тело, ошибка]. */
function gd_http_post(string $url, $body, array $headers = []): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $resp === false ? '' : (string)$resp, $err];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
        'header' => implode("\r\n", $headers),
        'content' => is_array($body) ? http_build_query($body) : $body,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; }
    }
    return [$code, $resp === false ? '' : (string)$resp, $resp === false ? 'нет соединения' : ''];
}

function gd_http_get(string $url, array $headers = []): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $resp === false ? '' : (string)$resp, $err];
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'header' => implode("\r\n", $headers)]]);
    $resp = @file_get_contents($url, false, $ctx);
    return [$resp === false ? 0 : 200, $resp === false ? '' : (string)$resp, $resp === false ? 'нет соединения' : ''];
}

/** Письмо. true — почтовый сервер хостинга принял письмо. */
function gd_send_email(string $subject, string $text): array {
    $c = gd_config();
    $to = array_filter($c['email_to'] ?? []);
    if (!$to) {
        return ['skip' => 'не указан адрес'];
    }
    $from = $c['email_from'] ?? 'noreply@gastrodom-market.ru';
    $headers = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode('Сайт GASTRODOM') . "?= <$from>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ]);
    $ok = @mail(implode(', ', $to), '=?UTF-8?B?' . base64_encode($subject) . '?=',
                chunk_split(base64_encode($text)), $headers, '-f' . $from);
    return $ok ? ['ok' => true] : ['error' => 'функция mail() вернула ошибку'];
}

function gd_send_vk(string $text): array {
    $c = gd_config();
    $ids = array_filter(array_map('intval', $c['vk_user_ids'] ?? []));
    if (empty($c['vk_token']) || !$ids) {
        return ['skip' => 'не заполнены vk_token / vk_user_ids'];
    }
    $res = [];
    foreach ($ids as $uid) {
        [$code, $body, $err] = gd_http_post('https://api.vk.com/method/messages.send', [
            'access_token' => $c['vk_token'], 'v' => '5.199', 'user_id' => $uid,
            'random_id' => random_int(1, 2147483647), 'message' => $text, 'dont_parse_links' => 1,
        ]);
        $j = json_decode($body, true);
        if (isset($j['response'])) {
            $res[$uid] = 'ok';
        } else {
            $res[$uid] = $err ?: ('ВК: ' . ($j['error']['error_msg'] ?? "ответ $code"));
        }
    }
    return $res;
}

function gd_send_max(string $text): array {
    $c = gd_config();
    $ids = array_filter(array_map('intval', $c['max_user_ids'] ?? []));
    if (empty($c['max_token']) || !$ids) {
        return ['skip' => 'не заполнены max_token / max_user_ids'];
    }
    $base = rtrim($c['max_api_base'] ?? 'https://platform-api.max.ru', '/');
    $res = [];
    foreach ($ids as $uid) {
        [$code, $body, $err] = gd_http_post($base . '/messages?user_id=' . $uid,
            json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
            ['Authorization: ' . $c['max_token'], 'Content-Type: application/json']);
        $res[$uid] = ($code >= 200 && $code < 300) ? 'ok' : ($err ?: "MAX: ответ $code " . mb_substr($body, 0, 200));
    }
    return $res;
}

/** Текст заказа для менеджера (одинаковый для почты, ВК и MAX). */
function gd_order_text(array $o): string {
    $rows = ["🛒 Заказ №{$o['id']} с сайта gastrodom-market.ru", ''];
    foreach ($o['items'] as $it) {
        $qty = $it['unit'] === 'шт' ? $it['q'] . ' шт' : gd_weight((int)$it['q']);
        $rows[] = "• {$it['name']} — $qty ≈ " . gd_money($it['sum']);
    }
    $rows[] = '';
    $rows[] = 'Сумма ≈ ' . gd_money($o['total']) . ' (вес примерный, итог — после взвешивания)';
    if ($o['promo'] !== '') {
        $rows[] = "🏷 Промокод: {$o['promo']} — {$o['promo_status']}";
        if ($o['discount'] > 0) {
            $rows[] = 'Со скидкой ≈ ' . gd_money($o['total'] - $o['discount']);
        }
    }
    $rows[] = '';
    $rows[] = "👤 {$o['name']}";
    $rows[] = "📞 {$o['phone']}";
    $rows[] = "📍 {$o['address']}";
    $when = trim($o['wish_date'] . ' ' . $o['wish_time']);
    if ($when !== '') { $rows[] = "📅 Желаемая дата: $when"; }
    if ($o['comment'] !== '') { $rows[] = "💬 {$o['comment']}"; }
    $rows[] = '';
    $rows[] = '🕒 ' . $o['created_at'];
    return implode("\n", $rows);
}

function gd_notify(array $o): array {
    $text = gd_order_text($o);
    $result = [
        'email' => gd_send_email("Заказ №{$o['id']} с сайта GASTRODOM", $text),
        'vk'    => gd_send_vk($text),
        'max'   => gd_send_max($text),
    ];
    gd_log('notify.log', "order {$o['id']}: " . json_encode($result, JSON_UNESCAPED_UNICODE));
    return $result;
}
