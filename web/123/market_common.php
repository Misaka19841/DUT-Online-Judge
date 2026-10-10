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
    return DB::checkTableExists('item') && DB::checkTableExists('orders');
}

function market_create_schema() {
    DB::query(<<<SQL
CREATE TABLE IF NOT EXISTS item (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    seller_id VARCHAR(20) NOT NULL,
    status TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_item_status CHECK (status IN (0, 1)),
    CONSTRAINT fk_item_seller FOREIGN KEY (seller_id) REFERENCES user_info(username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
    );

    DB::query(<<<SQL
CREATE TABLE IF NOT EXISTS orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL UNIQUE,
    buyer_id VARCHAR(20) NOT NULL,
    order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_item FOREIGN KEY (item_id) REFERENCES item(item_id),
    CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES user_info(username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
    );
}

function market_create_views() {
    DB::query(<<<SQL
CREATE OR REPLACE VIEW sold_items_view AS
SELECT I.item_id, I.item_name, O.buyer_id
FROM item I
JOIN orders O ON O.item_id = I.item_id
WHERE I.status = 1
SQL
    );

    DB::query(<<<SQL
CREATE OR REPLACE VIEW unsold_items_view AS
SELECT item_id, item_name, category, price, seller_id
FROM item
WHERE status = 0
SQL
    );
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
    echo '二手交易模块数据表尚未初始化。';
    if (market_is_admin()) {
        echo '请到 <a href="/market/admin">后台</a> 点击“初始化表和视图”。';
    } else {
        echo '请联系管理员初始化。';
    }
    echo '</div>';
    return false;
}
