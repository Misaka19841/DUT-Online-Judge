<?php
require __DIR__ . '/market_common.php';

market_require_login();

function market_send_buy_request_mail($seller_username, $buyer_username, $item_name) {
    $seller = DB::selectFirst([
        "select username, email from user_info",
        "where", ["username" => $seller_username]
    ]);
    if (!$seller || empty($seller['email'])) {
        return;
    }

    $esc_buyer = HTML::escape($buyer_username);
    $esc_item = HTML::escape($item_name);
    $html = <<<EOD
<p>{$esc_buyer} 希望购买您的商品：<strong>{$esc_item}</strong>。</p>
<p>请前往商品列表页面进行确认或拒绝。</p>
EOD;

    try {
        $mailer = UOJMail::noreply();
        $mailer->addAddress($seller['email'], $seller['username']);
        $mailer->Subject = "二手交易购买请求通知";
        $mailer->msgHTML($html);
        $mailer->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        UOJLog::error('market mail send failed: '.$mailer->ErrorInfo);
    }
}

function market_send_trade_confirm_mail($buyer_username, $seller_username, $item_name) {
    $buyer = DB::selectFirst([
        "select username, email from user_info",
        "where", ["username" => $buyer_username]
    ]);
    if (!$buyer || empty($buyer['email'])) {
        return;
    }

    $esc_seller = HTML::escape($seller_username);
    $esc_item = HTML::escape($item_name);
    $html = <<<EOD
<p>{$esc_seller} 同意了您的购买请求，交易已达成。</p>
<p>商品：<strong>{$esc_item}</strong></p>
EOD;

    try {
        $mailer = UOJMail::noreply();
        $mailer->addAddress($buyer['email'], $buyer['username']);
        $mailer->Subject = "二手交易成交通知";
        $mailer->msgHTML($html);
        $mailer->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        UOJLog::error('market mail send failed: '.$mailer->ErrorInfo);
    }
}

function market_send_trade_reject_mail($buyer_username, $seller_username, $item_name) {
    $buyer = DB::selectFirst([
        "select username, email from user_info",
        "where", ["username" => $buyer_username]
    ]);
    if (!$buyer || empty($buyer['email'])) {
        return;
    }

    $esc_seller = HTML::escape($seller_username);
    $esc_item = HTML::escape($item_name);
    $html = <<<EOD
<p>{$esc_seller} 拒绝了您的购买请求。</p>
<p>商品：<strong>{$esc_item}</strong></p>
EOD;

    try {
        $mailer = UOJMail::noreply();
        $mailer->addAddress($buyer['email'], $buyer['username']);
        $mailer->Subject = "二手交易拒绝通知";
        $mailer->msgHTML($html);
        $mailer->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        UOJLog::error('market mail send failed: '.$mailer->ErrorInfo);
    }
}

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'publish_item') {
    crsf_defend();
    if (!market_tables_ready()) {
        $err = '数据表未初始化。';
    } else {
        $name = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $price_raw = $_POST['price'] ?? '';
        $price = 0;
        if ($name === '' || $category === '') {
            $err = '商品名与类别不能为空。';
        } elseif (!market_parse_price($price_raw, $price)) {
            $err = '价格格式非法（最多两位小数）。';
        } else {
            DB::insert([
                "insert into item",
                "(item_name, category, price, seller_id, status, created_at)",
                "values", DB::tuple([$name, $category, $price, Auth::id(), 0, DB::now()])
            ]);
            $msg = '发布商品成功。';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_item_id'])) {
    crsf_defend();
    if (!market_tables_ready()) {
        $err = '数据表未初始化。';
    } else {
        $item_id_str = $_POST['buy_item_id'];
        if (!validateUInt($item_id_str)) {
            $err = '非法商品编号。';
        } else {
            $item_id = (int)$item_id_str;
            $buyer = Auth::id();
            $ok = true;
            $mail_seller = null;
            $mail_item_name = null;
            try {
                DB::transaction(function() use ($item_id, $buyer, &$ok, &$err, &$mail_seller, &$mail_item_name) {
                    $item = DB::selectFirst([
                        "select item_id, status, seller_id, item_name from item",
                        "where", ["item_id" => $item_id],
                        DB::for_update()
                    ]);
                    if (!$item) {
                        $ok = false;
                        $err = '商品不存在。';
                        return;
                    }
                    if ((int)$item['status'] !== 0) {
                        $ok = false;
                        $err = '该商品已售出，不能重复购买。';
                        return;
                    }
                    if ($item['seller_id'] === $buyer) {
                        $ok = false;
                        $err = '不能购买自己发布的商品。';
                        return;
                    }
                    $pending = DB::selectFirst([
                        "select request_id from pending_orders",
                        "where", ["item_id" => $item_id],
                        DB::for_update()
                    ]);
                    if ($pending) {
                        $ok = false;
                        $err = '该商品已有待确认购买请求，请稍后再试。';
                        return;
                    }
                    DB::insert([
                        "insert into pending_orders",
                        "(item_id, buyer_id, request_date)",
                        "values", DB::tuple([$item_id, $buyer, DB::now()])
                    ]);
                    $mail_seller = $item['seller_id'];
                    $mail_item_name = $item['item_name'];
                });
            } catch (Exception $e) {
                $ok = false;
                $err = '购买失败：' . HTML::escape($e->getMessage());
            }
            if ($ok) {
                $msg = '已提交购买请求，等待卖家确认。';
                market_send_buy_request_mail($mail_seller, $buyer, $mail_item_name);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'seller_confirm') {
    crsf_defend();
    if (!market_tables_ready()) {
        $err = '数据表未初始化。';
    } else {
        $item_id_str = $_POST['item_id'] ?? '';
        if (!validateUInt($item_id_str)) {
            $err = '非法商品编号。';
        } else {
            $item_id = (int)$item_id_str;
            $seller = Auth::id();
            $ok = true;
            $mail_buyer = null;
            $mail_item_name = null;
            try {
                DB::transaction(function() use ($item_id, $seller, &$ok, &$err, &$mail_buyer, &$mail_item_name) {
                    $item = DB::selectFirst([
                        "select item_id, status, seller_id, item_name from item",
                        "where", ["item_id" => $item_id],
                        DB::for_update()
                    ]);
                    if (!$item) {
                        $ok = false;
                        $err = '商品不存在。';
                        return;
                    }
                    if ($item['seller_id'] !== $seller) {
                        $ok = false;
                        $err = '只有卖家可以确认交易。';
                        return;
                    }
                    if ((int)$item['status'] !== 0) {
                        $ok = false;
                        $err = '商品状态不允许确认。';
                        return;
                    }
                    $pending = DB::selectFirst([
                        "select request_id, buyer_id from pending_orders",
                        "where", ["item_id" => $item_id],
                        DB::for_update()
                    ]);
                    if (!$pending) {
                        $ok = false;
                        $err = '没有待确认请求。';
                        return;
                    }
                    DB::insert([
                        "insert into orders",
                        "(item_id, buyer_id, order_date)",
                        "values", DB::tuple([$item_id, $pending['buyer_id'], DB::now()])
                    ]);
                    DB::update([
                        "update item",
                        "set", ["status" => 1],
                        "where", ["item_id" => $item_id]
                    ]);
                    DB::delete([
                        "delete from pending_orders",
                        "where", ["request_id" => $pending['request_id']]
                    ]);
                    $mail_buyer = $pending['buyer_id'];
                    $mail_item_name = $item['item_name'];
                });
            } catch (Exception $e) {
                $ok = false;
                $err = '确认失败：' . HTML::escape($e->getMessage());
            }
            if ($ok) {
                $msg = '卖家确认成功，交易已完成。';
                market_send_trade_confirm_mail($mail_buyer, $seller, $mail_item_name);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'seller_reject') {
    crsf_defend();
    if (!market_tables_ready()) {
        $err = '数据表未初始化。';
    } else {
        $item_id_str = $_POST['item_id'] ?? '';
        if (!validateUInt($item_id_str)) {
            $err = '非法商品编号。';
        } else {
            $item_id = (int)$item_id_str;
            $seller = Auth::id();
            $ok = true;
            $mail_buyer = null;
            $mail_item_name = null;
            try {
                DB::transaction(function() use ($item_id, $seller, &$ok, &$err, &$mail_buyer, &$mail_item_name) {
                    $item = DB::selectFirst([
                        "select item_id, seller_id, item_name from item",
                        "where", ["item_id" => $item_id],
                        DB::for_update()
                    ]);
                    if (!$item) {
                        $ok = false;
                        $err = '商品不存在。';
                        return;
                    }
                    if ($item['seller_id'] !== $seller) {
                        $ok = false;
                        $err = '只有卖家可以拒绝请求。';
                        return;
                    }
                    $pending = DB::selectFirst([
                        "select request_id, buyer_id from pending_orders",
                        "where", ["item_id" => $item_id],
                        DB::for_update()
                    ]);
                    if (!$pending) {
                        $ok = false;
                        $err = '没有待确认请求。';
                        return;
                    }
                    DB::delete([
                        "delete from pending_orders",
                        "where", ["request_id" => $pending['request_id']]
                    ]);
                    $mail_buyer = $pending['buyer_id'];
                    $mail_item_name = $item['item_name'];
                });
            } catch (Exception $e) {
                $ok = false;
                $err = '拒绝失败：' . HTML::escape($e->getMessage());
            }
            if ($ok) {
                $msg = '已拒绝该购买请求。';
                market_send_trade_reject_mail($mail_buyer, $seller, $mail_item_name);
            }
        }
    }
}

$all_items = [];
$all_categories = [];

$filter_seller = trim($_GET['seller_id'] ?? '');
$filter_category = trim($_GET['category'] ?? '');
$filter_status = $_GET['status'] ?? 'all';
$filter_min_price_raw = trim($_GET['min_price'] ?? '');
$filter_max_price_raw = trim($_GET['max_price'] ?? '');
$filter_min_price = null;
$filter_max_price = null;

if ($filter_seller !== '' && !validateUsername($filter_seller)) {
    $err = '卖家用户名不合法。';
}
if (!in_array($filter_status, ['all', 'available', 'pending', 'sold'], true)) {
    $filter_status = 'all';
}
if ($err === null && $filter_min_price_raw !== '' && !market_parse_price($filter_min_price_raw, $filter_min_price)) {
    $err = '最低价格格式非法（最多两位小数）。';
}
if ($err === null && $filter_max_price_raw !== '' && !market_parse_price($filter_max_price_raw, $filter_max_price)) {
    $err = '最高价格格式非法（最多两位小数）。';
}
if ($err === null && $filter_min_price !== null && $filter_max_price !== null && $filter_min_price > $filter_max_price) {
    $err = '最低价不能大于最高价。';
}

if (market_tables_ready()) {
    $all_categories = DB::selectAll([
        "select distinct category from item",
        "order by category asc"
    ]);

    $conds = [];
    if ($err === null) {
        if ($filter_seller !== '') {
            $conds[] = ['I.seller_id', '=', $filter_seller];
        }
        if ($filter_category !== '') {
            $conds[] = ['I.category', '=', $filter_category];
        }
        if ($filter_min_price !== null) {
            $conds[] = ['I.price', '>=', $filter_min_price];
        }
        if ($filter_max_price !== null) {
            $conds[] = ['I.price', '<=', $filter_max_price];
        }
        if ($filter_status === 'available') {
            $conds[] = ['I.status', '=', 0];
            $conds[] = ['P.buyer_id', 'is', null];
        } elseif ($filter_status === 'pending') {
            $conds[] = ['I.status', '=', 0];
            $conds[] = ['P.buyer_id', 'is not', null];
        } elseif ($filter_status === 'sold') {
            $conds[] = ['I.status', '=', 1];
        }
    }

    $all_items_q = [
        "select I.item_id, I.item_name, I.category, I.price, I.seller_id, I.status, I.created_at, P.buyer_id as pending_buyer",
        "from item I",
        "left join pending_orders P on P.item_id = I.item_id"
    ];
    if ($conds) {
        $all_items_q[] = "where";
        $all_items_q[] = $conds;
    }
    $all_items_q[] = "order by I.item_id desc";
    $all_items = DB::selectAll($all_items_q);
}
?>
<?php echoUOJPageHeader('商品列表') ?>
<h2 class="page-header">商品列表</h2>
<?php market_nav('items'); ?>
<?php market_require_schema_or_hint(); ?>

<?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif ?>

<?php if (market_tables_ready()): ?>
<div class="panel panel-default">
    <div class="panel-heading">发布商品（U/S 均可）</div>
    <div class="panel-body">
        <form method="post" class="form-inline">
            <?= HTML::hiddenToken() ?>
            <input type="hidden" name="action" value="publish_item" />
            <input class="form-control" name="item_name" placeholder="商品名" required />
            <input class="form-control" name="category" placeholder="类别" required />
            <input class="form-control" name="price" placeholder="价格" required />
            <button class="btn btn-success">发布</button>
        </form>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">筛选查询</div>
    <div class="panel-body">
        <form method="get" class="form-inline">
            <input class="form-control" name="seller_id" placeholder="卖家用户名" value="<?= HTML::escape($filter_seller) ?>" />
            <select class="form-control" name="category">
                <option value="">全部品类</option>
                <?php foreach ($all_categories as $row): ?>
                    <?php $cat = $row['category']; ?>
                    <option value="<?= HTML::escape($cat) ?>" <?= $cat === $filter_category ? 'selected' : '' ?>>
                        <?= HTML::escape($cat) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <input class="form-control" name="min_price" placeholder="最低价" value="<?= HTML::escape($filter_min_price_raw) ?>" />
            <input class="form-control" name="max_price" placeholder="最高价" value="<?= HTML::escape($filter_max_price_raw) ?>" />
            <select class="form-control" name="status">
                <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>全部状态</option>
                <option value="available" <?= $filter_status === 'available' ? 'selected' : '' ?>>未售出</option>
                <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>待确认</option>
                <option value="sold" <?= $filter_status === 'sold' ? 'selected' : '' ?>>已售出</option>
            </select>
            <button class="btn btn-primary">查询</button>
            <a class="btn btn-default" href="/market/items">重置</a>
        </form>
    </div>
</div>

<table class="table table-bordered table-hover">
    <thead>
        <tr>
            <th>ID</th><th>商品名</th><th>类别</th><th>价格</th><th>卖家</th><th>状态</th><th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($all_items as $row): ?>
        <tr>
            <td><?= (int)$row['item_id'] ?></td>
            <td><?= HTML::escape($row['item_name']) ?></td>
            <td><?= HTML::escape($row['category']) ?></td>
            <td><?= number_format((float)$row['price'], 2) ?></td>
            <td><?= getUserLink($row['seller_id']) ?></td>
            <td>
                <?php if ((int)$row['status'] === 1): ?>
                    已售出
                <?php elseif (!empty($row['pending_buyer'])): ?>
                    待卖家确认（买家：<?= getUserLink($row['pending_buyer']) ?>）
                <?php else: ?>
                    未售出
                <?php endif ?>
            </td>
            <td>
                <?php if ((int)$row['status'] === 0 && empty($row['pending_buyer'])): ?>
                    <form method="post" class="form-inline">
                        <?= HTML::hiddenToken() ?>
                        <input type="hidden" name="buy_item_id" value="<?= (int)$row['item_id'] ?>" />
                        <button class="btn btn-xs btn-primary">购买</button>
                    </form>
                <?php elseif ((int)$row['status'] === 0 && !empty($row['pending_buyer']) && $row['seller_id'] === Auth::id()): ?>
                    <form method="post" class="form-inline">
                        <?= HTML::hiddenToken() ?>
                        <input type="hidden" name="action" value="seller_confirm" />
                        <input type="hidden" name="item_id" value="<?= (int)$row['item_id'] ?>" />
                        <button class="btn btn-xs btn-success">确认成交</button>
                    </form>
                    <form method="post" class="form-inline top-buffer-sm">
                        <?= HTML::hiddenToken() ?>
                        <input type="hidden" name="action" value="seller_reject" />
                        <input type="hidden" name="item_id" value="<?= (int)$row['item_id'] ?>" />
                        <button class="btn btn-xs btn-warning">拒绝请求</button>
                    </form>
                <?php else: ?>
                    <span class="text-muted">不可购买</span>
                <?php endif ?>
            </td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>
<?php endif ?>

<?php echoUOJPageFooter() ?>

