<?php
/*
 * Страница проверки приёма заказов. Открывается только с паролем check_key из _private/config.php:
 *   https://gastrodom-market.ru/api/check.php?key=ВАШ_ПАРОЛЬ
 * Показывает, всё ли готово, отправляет пробное уведомление, помогает узнать ID менеджера в MAX
 * и показывает последние заказы (на случай, если уведомление не дошло).
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Moscow');
require dirname(__DIR__) . '/_private/lib.php';

$c = gd_config();
$key = (string)($_GET['key'] ?? '');
if (($c['check_key'] ?? '') === '' || !hash_equals((string)$c['check_key'], $key)) {
    http_response_code(404);
    exit('Not found');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$k = urlencode($key);
$row = fn(string $name, bool $ok, string $note = '') =>
    '<tr><td>' . ($ok ? '✅' : '❌') . '</td><td>' . $h($name) . '</td><td>' . $h($note) . '</td></tr>';
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Проверка заказов GASTRODOM</title>
<style>body{font:15px/1.5 system-ui,Arial,sans-serif;max-width:900px;margin:0 auto;padding:16px;color:#222;background:#f6f1e7}
h1{font-size:22px}h2{font-size:18px;margin-top:28px}table{border-collapse:collapse;width:100%;background:#fff}
td,th{border:1px solid #ddd;padding:6px 8px;vertical-align:top;text-align:left}a.btn{display:inline-block;background:#2e4630;color:#fff;
padding:10px 16px;border-radius:8px;text-decoration:none;margin:4px 6px 4px 0}pre{white-space:pre-wrap;background:#fff;padding:10px;border:1px solid #ddd}</style>
</head><body>
<h1>Проверка приёма заказов</h1>
<p><a class="btn" href="?key=<?= $k ?>&amp;test=1">Отправить пробное уведомление</a>
<a class="btn" href="?key=<?= $k ?>&amp;max_updates=1">Узнать ID в MAX</a>
<a class="btn" href="?key=<?= $k ?>&amp;orders=1">Последние заказы</a></p>
<form method="get" style="margin:8px 0"><input type="hidden" name="key" value="<?= $h($key) ?>">
<label>Узнать числовой ID ВКонтакте по адресу страницы (например, <code>vk.ru/ivanova</code>):
<input name="vk" placeholder="адрес или короткое имя" style="padding:8px;min-width:240px"></label> <button style="padding:8px 14px">Узнать</button></form>

<h2>Готовность</h2>
<table>
<?php
$sqliteOk = extension_loaded('pdo_sqlite');
echo $row('PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '7.4', '>='), 'нужна версия 7.4 или новее');
echo $row('База заказов (SQLite)', $sqliteOk, $sqliteOk ? '' : 'на хостинге нет pdo_sqlite — напишите в поддержку Рег.ру');
echo $row('Папка _private доступна для записи', is_writable(GD_PRIVATE), 'нужна для сохранения заказов');
echo $row('Отправка запросов (curl)', function_exists('curl_init') || ini_get('allow_url_fopen'), 'нужна для ВК и MAX');
echo $row('Почта: адрес получателя', !empty(array_filter($c['email_to'] ?? [])), implode(', ', $c['email_to'] ?? []));
echo $row('ВКонтакте: ключ и ID менеджеров', !empty($c['vk_token']) && !empty($c['vk_user_ids']), 'ID: ' . implode(', ', $c['vk_user_ids'] ?? []));
echo $row('MAX: токен и ID менеджеров', !empty($c['max_token']) && !empty($c['max_user_ids']), 'ID: ' . implode(', ', $c['max_user_ids'] ?? []));
if ($sqliteOk) {
    try { $n = (int)gd_db()->query('SELECT COUNT(*) FROM orders')->fetchColumn(); echo $row('Заказов в базе', true, (string)$n); }
    catch (Throwable $e) { echo $row('База заказов открывается', false, $e->getMessage()); }
}
?>
</table>
<p>Проверьте, что закрытая папка действительно закрыта: ссылка
<a href="../_private/config.php" target="_blank">/_private/config.php</a> должна показывать ошибку 403 («Доступ запрещён»).</p>

<?php if (!empty($_GET['test'])):
    $text = "✅ Пробное уведомление с сайта gastrodom-market.ru\nЕсли вы это читаете, заказы с сайта будут приходить сюда.\n🕒 " . date('d.m.Y H:i');
    $res = ['Почта' => gd_send_email('Проверка уведомлений сайта GASTRODOM', $text), 'ВКонтакте' => gd_send_vk($text), 'MAX' => gd_send_max($text)]; ?>
<h2>Результат пробной отправки</h2>
<pre><?= $h(json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
<p>«ok» — отправлено. Письмо может попасть в «Спам» — отметьте его как «Не спам».
Для ВК менеджер должен разрешить сообщения от сообщества, для MAX — сначала написать боту.</p>
<?php endif; ?>

<?php if (!empty($_GET['max_updates'])): ?>
<h2>Кто писал боту в MAX</h2>
<?php if (empty($c['max_token'])): ?><p>Сначала впишите max_token в _private/config.php.</p>
<?php else:
    [$code, $body, $err] = gd_http_get(rtrim($c['max_api_base'], '/') . '/updates?limit=50&timeout=0', ['Authorization: ' . $c['max_token']]);
    $j = json_decode($body, true);
    $seen = [];
    foreach (($j['updates'] ?? []) as $u) {
        $s = $u['message']['sender'] ?? $u['user'] ?? null;
        if ($s && isset($s['user_id'])) { $seen[$s['user_id']] = trim(($s['first_name'] ?? $s['name'] ?? '') . ' ' . ($s['last_name'] ?? '')); }
    }
    if ($seen): ?>
<table><tr><th>ID для max_user_ids</th><th>Имя</th></tr>
<?php foreach ($seen as $uid => $nm): ?><tr><td><b><?= $h($uid) ?></b></td><td><?= $h($nm) ?></td></tr><?php endforeach; ?></table>
<?php else: ?><p>Пока никого не видно. Менеджер должен найти бота в MAX, нажать «Начать» и написать любое сообщение, затем обновите эту страницу.
<?= $err ? 'Ошибка: ' . $h($err) : ($code !== 200 ? 'Ответ MAX: ' . $h($code . ' ' . mb_substr($body, 0, 300)) : '') ?></p>
<?php endif; endif; endif; ?>

<?php if (!empty($_GET['vk'])):
    $sn = preg_replace('#^.*vk\.(?:ru|com)/#i', '', trim((string)$_GET['vk']));
    $sn = trim($sn, "/@ ");
    if (preg_match('/^id(\d+)$/', $sn, $m)) { $vkRes = 'ID: ' . $m[1]; }
    elseif (empty($c['vk_token'])) { $vkRes = 'Сначала впишите vk_token в _private/config.php.'; }
    else {
        [$code, $body, $err] = gd_http_post('https://api.vk.com/method/utils.resolveScreenName',
            ['access_token' => $c['vk_token'], 'v' => '5.199', 'screen_name' => $sn]);
        $j = json_decode($body, true);
        $r = $j['response'] ?? null;
        $vkRes = ($r && ($r['type'] ?? '') === 'user') ? 'ID: ' . $r['object_id']
               : ('Не удалось: ' . ($err ?: ($j['error']['error_msg'] ?? 'это не страница человека')));
    } ?>
<h2>ID ВКонтакте для «<?= $h($sn) ?>»</h2><p><b><?= $h($vkRes) ?></b> — впишите это число в vk_user_ids.</p>
<?php endif; ?>

<?php if (!empty($_GET['orders']) && $sqliteOk): ?>
<h2>Последние 30 заказов</h2>
<table><tr><th>№</th><th>Когда</th><th>Покупатель</th><th>Состав</th><th>Уведомления</th></tr>
<?php foreach (gd_db()->query('SELECT * FROM orders ORDER BY id DESC LIMIT 30') as $o):
    $lines = array_map(fn($i) => $i['name'] . ' — ' . ($i['unit'] === 'шт' ? $i['q'] . ' шт' : gd_weight((int)$i['q'])), json_decode($o['items_json'], true)); ?>
<tr><td><?= (int)$o['id'] ?></td><td><?= $h($o['created_at']) ?></td>
<td><?= $h($o['name']) ?><br><?= $h($o['phone']) ?><br><?= $h($o['address']) ?><br><?= $h(trim($o['wish_date'] . ' ' . $o['wish_time'])) ?>
<?= $o['comment'] !== '' ? '<br>💬 ' . $h($o['comment']) : '' ?><?= $o['promo'] !== '' ? '<br>🏷 ' . $h($o['promo'] . ' — ' . $o['promo_status']) : '' ?></td>
<td><?= $h(implode('; ', $lines)) ?><br>≈ <?= $h(gd_money((float)$o['total'])) ?></td>
<td><small><?= $h($o['notified']) ?></small></td></tr>
<?php endforeach; ?></table>
<?php endif; ?>
</body></html>
