<?php
/* Приём заказа с сайта: проверка → пересчёт цен по каталогу → промокод → сохранение → уведомления. */

declare(strict_types=1);
date_default_timezone_set('Europe/Moscow');
require dirname(__DIR__) . '/_private/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gd_json(['ok' => false, 'error' => 'Заказ отправляется только из формы на сайте.'], 405);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    gd_json(['ok' => false, 'error' => 'Не удалось прочитать заказ. Обновите страницу и попробуйте ещё раз.'], 400);
}

// Ловушка для роботов: это поле скрыто от людей. Роботу отвечаем «принято», но ничего не делаем.
if (!empty($in['website'])) {
    gd_json(['ok' => true, 'id' => 0]);
}

$c = gd_config();
$fail = fn(string $msg, string $field = '') => gd_json(['ok' => false, 'error' => $msg, 'field' => $field], 422);

/* ── контакты ── */
if (empty($in['consent'])) { $fail('Нужно согласие на обработку персональных данных.', 'consent'); }
$name = gd_clean((string)($in['name'] ?? ''), 80);
if (mb_strlen($name) < 2) { $fail('Напишите, как к вам обращаться.', 'name'); }

$phone = gd_phone((string)($in['phone'] ?? ''));
if ($phone === null) { $fail('Проверьте номер телефона: например, 8 999 123-45-67.', 'phone'); }

$address = gd_clean((string)($in['address'] ?? ''), 300);
if (mb_strlen($address) < 5) { $fail('Укажите адрес доставки: улица, дом, квартира или ЖК.', 'address'); }

$wishDate = '';
if (!empty($in['date'])) {
    $d = DateTime::createFromFormat('!Y-m-d', (string)$in['date']);
    if (!$d || $d < new DateTime('today')) { $fail('Выберите дату не раньше сегодняшней.', 'date'); }
    $wishDate = $d->format('d.m.Y');
}
$slots = ['', 'утро (9–12)', 'день (12–16)', 'вечер (16–21)'];
$wishTime = in_array($in['time'] ?? '', $slots, true) ? (string)$in['time'] : '';
$comment = gd_clean((string)($in['comment'] ?? ''), 500);


/* ── корзина: цены берём только из каталога на сервере ── */
$catalog = gd_catalog();
$items = [];
$total = 0.0;
foreach ((array)($in['items'] ?? []) as $row) {
    $id = (string)($row['id'] ?? '');
    $q = (int)($row['q'] ?? 0);
    if (!isset($catalog[$id])) { continue; }
    $p = $catalog[$id];
    if ($p['unit'] === 'шт') {
        if ($q < 1 || $q > 10) { $fail("Количество «{$p['name']}» — от 1 до 10 шт."); }
        $sum = $p['price'] * $q;
    } else {
        if ($q < 100 || $q > 5000 || $q % 100 !== 0) { $fail("Вес «{$p['name']}» — от 100 г до 5 кг, с шагом 100 г."); }
        $sum = $p['price'] * $q / 1000;
    }
    $items[] = ['id' => $id, 'name' => $p['name'], 'unit' => $p['unit'], 'q' => $q, 'price' => $p['price'], 'sum' => round($sum, 2)];
    $total += $sum;
}
if (!$items) { $fail('Корзина пуста. Добавьте товары из меню.'); }
if (count($items) > 40) { $fail('Слишком много позиций в одном заказе. Разделите заказ или напишите менеджеру.'); }

/* ── антиспам ── */
$db = gd_db();
$ip = gd_ip_hash();
$digits = gd_phone_digits($phone);
$hourAgo = time() - 3600;
$st = $db->prepare('SELECT COUNT(*) FROM orders WHERE ip_hash = ? AND ts > ?');
$st->execute([$ip, $hourAgo]);
if ((int)$st->fetchColumn() >= (int)($c['max_orders_per_ip_hour'] ?? 5)) {
    gd_json(['ok' => false, 'error' => 'Слишком много заказов подряд. Попробуйте через час или позвоните нам: +7 (916) 055-15-44.'], 429);
}
$st = $db->prepare('SELECT COUNT(*) FROM orders WHERE phone_digits = ? AND ts > ?');
$st->execute([$digits, $hourAgo]);
if ((int)$st->fetchColumn() >= (int)($c['max_orders_per_phone_hour'] ?? 3)) {
    gd_json(['ok' => false, 'error' => 'С этого номера уже несколько заказов за последний час. Менеджер свяжется с вами.'], 429);
}

/* ── промокод: один раз на номер телефона ── */
$promoRaw = gd_clean((string)($in['promo'] ?? ''), 40);
$promo = '';
$promoStatus = '';
$discount = 0.0;
$promoMsg = '';
if ($promoRaw !== '') {
    $code = gd_promo_norm($promoRaw);
    $rule = $c['promo_codes'][$code] ?? null;
    $promo = $rule ? $code : $promoRaw;
    if (!$rule) {
        $promoStatus = 'не найден, скидка не применена';
        $promoMsg = 'Промокод не найден — заказ принят без скидки.';
    } else {
        $st = $db->prepare('SELECT COUNT(*) FROM orders WHERE phone_digits = ?');
        $st->execute([$digits]);
        $hadOrders = (int)$st->fetchColumn() > 0;
        $st = $db->prepare("SELECT COUNT(*) FROM orders WHERE phone_digits = ? AND promo = ? AND discount > 0");
        $st->execute([$digits, $code]);
        $usedCode = (int)$st->fetchColumn() > 0;
        if ($usedCode) {
            $promoStatus = 'уже использован с этого номера, скидка не применена';
            $promoMsg = 'Этот промокод уже использован с вашего номера — заказ принят без скидки.';
        } elseif (!empty($rule['first_order_only']) && $hadOrders) {
            $promoStatus = 'не первый заказ с этого номера, скидка не применена';
            $promoMsg = 'Промокод действует только на первый заказ — заказ принят без скидки.';
        } else {
            $discount = round($total * $rule['discount'] / 100, 2);
            $promoStatus = $rule['label'] . ' — применён (первый заказ с этого номера на сайте; проверьте, не заказывал ли покупатель через бота)';
            $promoMsg = 'Промокод применён: ' . $rule['label'] . '.';
        }
    }
}

/* ── сохраняем ── */
$now = date('d.m.Y H:i');
$st = $db->prepare('INSERT INTO orders (created_at, ts, name, phone, phone_digits, address, wish_date, wish_time, comment,
                    items_json, total, promo, promo_status, discount, ip_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$st->execute([$now, time(), $name, $phone, $digits, $address, $wishDate, $wishTime, $comment,
              json_encode($items, JSON_UNESCAPED_UNICODE), round($total, 2), $promo, $promoStatus, $discount, $ip]);
$id = (int)$db->lastInsertId();

$order = compact('id', 'name', 'phone', 'address', 'comment', 'items', 'total', 'promo', 'discount')
       + ['created_at' => $now, 'wish_date' => $wishDate, 'wish_time' => $wishTime, 'promo_status' => $promoStatus];

// Сначала отвечаем покупателю, потом рассылаем уведомления (чтобы он не ждал ответов ВК и MAX).
$response = json_encode(['ok' => true, 'id' => $id, 'total' => round($total), 'discount' => round($discount),
                         'promo_message' => $promoMsg], JSON_UNESCAPED_UNICODE);
ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Content-Length: ' . strlen($response));
echo $response;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_flush();
    flush();
}

$sent = gd_notify($order);
$db->prepare('UPDATE orders SET notified = ? WHERE id = ?')->execute([json_encode($sent, JSON_UNESCAPED_UNICODE), $id]);
