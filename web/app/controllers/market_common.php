<?php

function market_is_admin() {
    return isSuperUser(Auth::user());
}

function market_require_login() {
    if (!Auth::check()) {
        redirectToLogin();
    }
}

function market_parse_price($str, &$price) {
    if (!is_string($str)) {
        return false;
    }
    $str = trim($str);
    if ($str === '') {
        return false;
    }
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $str)) {
        return false;
    }
    $price = (float)$str;
    return $price >= 0;
}

function market_tables_ready() {
    return DB::checkTableExists('item')
        && DB::checkTableExists('orders')
        && DB::checkTableExists('pending_orders');
}

function market_nav($active) {
    $links = [
        'home' => ['/market', '首页'],
        'items' => ['/market/items', '商品列表'],
        'users' => ['/market/users', '用户列表'],
        'orders' => ['/market/orders', '订单列表']
    ];
    if (market_is_admin()) {
        $links['admin'] = ['/market/admin', '后台'];
    }
    echo '<ul class="nav nav-pills bottom-buffer-sm">';
    foreach ($links as $key => $cfg) {
        $class = $key === $active ? ' class="active"' : '';
        echo "<li{$class}><a href=\"{$cfg[0]}\">{$cfg[1]}</a></li>";
    }
    echo '</ul>';
}

function market_require_schema_or_hint() {
    if (market_tables_ready()) {
        return true;
    }
    echo '<div class="alert alert-warning">';
    echo '二手交易模块数据表不完整（需要 item、orders、pending_orders）。请联系数据库管理员执行建表 SQL。';
    echo '</div>';
    return false;
}

