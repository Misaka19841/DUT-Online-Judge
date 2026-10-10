<?php
require __DIR__ . '/market_common.php';

market_require_login();

function date_is_admin() {
    return Auth::id() === 'admin';
}

function date_tables_ready() {
    return DB::checkTableExists('date_user_profile')
        && DB::checkTableExists('date_review_log')
        && DB::checkTableExists('date_settings')
        && DB::checkTableExists('date_tag_cache');
}

function date_ticks_table_ready() {
    return DB::checkTableExists('date_slider_ticks');
}

function date_require_schema_or_hint() {
    if (date_tables_ready()) {
        if (!date_ticks_table_ready()) {
            echo '<div class="alert alert-info">';
            echo '提示：未检测到 date_slider_ticks 表，将使用内置滑块刻度文案。';
            echo '</div>';
        }
        return true;
    }
    echo '<div class="alert alert-warning">';
    echo '搭子匹配系统数据表不完整（需要 date_user_profile、date_review_log、date_settings、date_tag_cache）。请联系数据库管理员执行建表 SQL。';
    echo '</div>';
    return false;
}

function date_parse_float01($raw, &$out) {
    if (!is_string($raw) && !is_numeric($raw)) {
        return false;
    }
    $v = (float)$raw;
    if (!is_finite($v)) {
        return false;
    }
    if ($v < 0) $v = 0;
    if ($v > 1) $v = 1;
    $out = $v;
    return true;
}

function date_parse_uint_or_null($raw) {
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!validateUInt((string)$raw)) {
        return null;
    }
    return (int)$raw;
}

function date_vec_cosine_01($a, $b) {
    if (!is_array($a) || !is_array($b)) {
        return 0.0;
    }
    $n = min(count($a), count($b));
    if ($n <= 0) {
        return 0.0;
    }
    $dot = 0.0;
    $na = 0.0;
    $nb = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $x = (float)$a[$i];
        $y = (float)$b[$i];
        $dot += $x * $y;
        $na += $x * $x;
        $nb += $y * $y;
    }
    if ($na <= 0 || $nb <= 0) {
        return 0.0;
    }
    $cos = $dot / sqrt($na * $nb);
    if (!is_finite($cos)) {
        return 0.0;
    }
    if ($cos < -1) $cos = -1;
    if ($cos > 1) $cos = 1;
    return ($cos + 1.0) / 2.0;
}

function date_load_settings($keys) {
    $res = [];
    foreach ($keys as $k) {
        $res[$k] = null;
    }
    if (!date_tables_ready()) {
        return $res;
    }
    if (!$keys) {
        return $res;
    }
    foreach ($keys as $k) {
        $row = DB::selectFirst([
            "select `value` from date_settings",
            "where", [["`key`", "=", $k]]
        ]);
        if ($row && array_key_exists('value', $row)) {
            $res[$k] = $row['value'];
        }
    }
    return $res;
}

function date_trim_quotes($s) {
    if (!is_string($s)) {
        return $s;
    }
    $s = trim($s);
    if ($s === '') {
        return $s;
    }
    if (($s[0] === '`' && substr($s, -1) === '`') || ($s[0] === '"' && substr($s, -1) === '"') || ($s[0] === "'" && substr($s, -1) === "'")) {
        $s = substr($s, 1, -1);
    }
    return trim($s);
}

function date_set_setting($key, $value) {
    $existing = DB::selectFirst([
        "select `key` from date_settings",
        "where", [["`key`", "=", $key]]
    ]);
    if ($existing) {
        DB::update([
            "update date_settings",
            "set", ["value" => $value, "updated_at" => DB::now()],
            "where", [["`key`", "=", $key]]
        ]);
    } else {
        DB::insert([
            "insert into date_settings",
            "(`key`, `value`, `updated_at`)",
            "values", DB::tuple([$key, $value, DB::now()])
        ]);
    }
}

function date_send_mail_to_user($username, $subject, $html) {
    $user = DB::selectFirst([
        "select username, email from user_info",
        "where", ["username" => $username]
    ]);
    if (!$user || empty($user['email'])) {
        return;
    }
    try {
        $mailer = UOJMail::noreply();
        $mailer->addAddress($user['email'], $user['username']);
        $mailer->Subject = $subject;
        $mailer->msgHTML($html);
        $mailer->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        UOJLog::error('date mail send failed: '.$mailer->ErrorInfo);
    }
}

function date_send_mail_to_admin($subject, $html) {
    date_send_mail_to_user('admin', $subject, $html);
}

function date_http_post_json($url, $api_key, $payload, &$err) {
    $err = null;
    if (!$url) {
        $err = '接口未配置：请先在“LLM设置”填写 chat/completions 与 embeddings 的 Endpoint。';
        return null;
    }
    $ch = curl_init();
    if (!$ch) {
        $err = 'curl_init 失败。';
        return null;
    }
    $headers = ['Content-Type: application/json'];
    if ($api_key) {
        $headers[] = 'Authorization: Bearer '.$api_key;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        $err = $curl_err ? $curl_err : '请求失败。';
        return null;
    }
    if ($http < 200 || $http >= 300) {
        $err = 'HTTP '.$http;
        return null;
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        $err = '响应不是合法 JSON。';
        return null;
    }
    return $data;
}

function date_extract_json_object($s) {
    if (!is_string($s)) {
        return null;
    }
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    $m = [];
    if (preg_match('/```json\\s*([\\s\\S]*?)\\s*```/i', $s, $m)) {
        $cand = trim($m[1]);
        $obj = json_decode($cand, true);
        if (is_array($obj)) {
            return $obj;
        }
    }
    if (preg_match('/(\\{[\\s\\S]*\\})/m', $s, $m)) {
        $cand = trim($m[1]);
        $obj = json_decode($cand, true);
        if (is_array($obj)) {
            return $obj;
        }
    }
    if ($s[0] === '{' && substr($s, -1) === '}') {
        $obj = json_decode($s, true);
        if (is_array($obj)) {
            return $obj;
        }
    }
    return null;
}

function date_openai_chat_extract_tags($endpoint, $api_key, $model, $text, &$err) {
    $err = null;
    $endpoint = date_trim_quotes((string)$endpoint);
    if (!$endpoint) {
        $err = '未配置 chat/completions：请先在“LLM设置”填写 chat/completions Endpoint。';
        return null;
    }
    $model = trim((string)$model);
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }
    $system = '你是标签提取器。输入是一段中文/英文自我介绍文本。请只输出严格 JSON，不要输出任何额外文字、代码块标记或解释。输出格式：{"tags":{"标签名":0~1,...}}。规则：提取 5~30 个标签；标签名尽量短；权重反映确定性+重要性；不要输出敏感个人信息；若信息太少返回 {"tags":{}}。';
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => (string)$text]
        ],
        'temperature' => 0.2
    ];
    $resp = date_http_post_json($endpoint, $api_key, $payload, $err);
    if (!$resp) {
        return null;
    }
    $content = null;
    if (isset($resp['choices'][0]['message']['content'])) {
        $content = $resp['choices'][0]['message']['content'];
    } elseif (isset($resp['choices'][0]['text'])) {
        $content = $resp['choices'][0]['text'];
    }
    if (!is_string($content) || trim($content) === '') {
        if ($err === null) {
            $err = 'chat/completions 响应为空。';
        }
        return null;
    }
    $obj = date_extract_json_object($content);
    if (!$obj || !isset($obj['tags']) || !is_array($obj['tags'])) {
        if ($err === null) {
            $err = 'chat/completions 未返回合法 tags JSON。';
        }
        return null;
    }
    return $obj['tags'];
}

function date_openai_embed_one($endpoint, $api_key, $model, $text, &$err) {
    $err = null;
    $endpoint = date_trim_quotes((string)$endpoint);
    if (!$endpoint) {
        $err = '未配置 embeddings：请先在“LLM设置”填写 embeddings Endpoint。';
        return null;
    }
    $model = trim((string)$model);
    if ($model === '') {
        $model = 'text-embedding-3-small';
    }
    $payload = [
        'model' => $model,
        'input' => (string)$text
    ];
    $resp = date_http_post_json($endpoint, $api_key, $payload, $err);
    if (!$resp) {
        return null;
    }
    if (isset($resp['data'][0]['embedding']) && is_array($resp['data'][0]['embedding'])) {
        return $resp['data'][0]['embedding'];
    }
    if ($err === null) {
        $err = 'embeddings 响应格式不正确。';
    }
    return null;
}

function date_get_tag_embedding_cached_openai($tag, $endpoint, $api_key, $model, &$err) {
    $err = null;
    $tag = trim((string)$tag);
    if ($tag === '') {
        $err = '标签为空。';
        return null;
    }
    $cached = DB::selectFirst([
        "select embedding_json from date_tag_cache",
        "where", ["tag" => $tag]
    ]);
    if ($cached && $cached['embedding_json'] !== null && $cached['embedding_json'] !== '') {
        $vec = json_decode($cached['embedding_json'], true);
        if (is_array($vec) && count($vec) > 0) {
            return $vec;
        }
    }
    $vec = date_openai_embed_one($endpoint, $api_key, $model, $tag, $err);
    if (!$vec) {
        return null;
    }
    $existing = DB::selectFirst([
        "select tag from date_tag_cache",
        "where", ["tag" => $tag]
    ]);
    if ($existing) {
        DB::update([
            "update date_tag_cache",
            "set", ["embedding_json" => json_encode($vec), "updated_at" => DB::now()],
            "where", ["tag" => $tag]
        ]);
    } else {
        DB::insert([
            "insert into date_tag_cache",
            "(tag, embedding_json, updated_at)",
            "values", DB::tuple([$tag, json_encode($vec), DB::now()])
        ]);
    }
    return $vec;
}

function date_weighted_sum_normalized($tag_to_weight, $embed_endpoint, $api_key, &$err, $embed_model = null) {
    $err = null;
    if (!is_array($tag_to_weight) || !$tag_to_weight) {
        $err = '标签为空。';
        return null;
    }
    $sum = null;
    $norm2 = 0.0;
    foreach ($tag_to_weight as $tag => $w_raw) {
        $w = (float)$w_raw;
        if (!is_finite($w) || $w <= 0) {
            continue;
        }
        $e_err = null;
        $vec = date_get_tag_embedding_cached_openai($tag, $embed_endpoint, $api_key, $embed_model, $e_err);
        if (!$vec) {
            continue;
        }
        if ($sum === null) {
            $sum = array_fill(0, count($vec), 0.0);
        }
        $n = min(count($sum), count($vec));
        for ($i = 0; $i < $n; $i++) {
            $sum[$i] += $w * (float)$vec[$i];
        }
    }
    if ($sum === null) {
        $err = '无法获得任何标签向量。';
        return null;
    }
    for ($i = 0; $i < count($sum); $i++) {
        $norm2 += $sum[$i] * $sum[$i];
    }
    if ($norm2 <= 0) {
        $err = '标签向量为零。';
        return null;
    }
    $inv = 1.0 / sqrt($norm2);
    for ($i = 0; $i < count($sum); $i++) {
        $sum[$i] *= $inv;
    }
    return $sum;
}

function date_slider_specs() {
    return [
        ['key' => 'cf_rating', 'label' => 'CF分'],
        ['key' => 'regional_medal', 'label' => '区域赛奖牌'],
        ['key' => 'math_affinity', 'label' => '数学偏好'],
        ['key' => 'data_structure', 'label' => '数据结构能力'],
        ['key' => 'graph_theory', 'label' => '图论能力'],
        ['key' => 'dp_skill', 'label' => 'DP能力'],
        ['key' => 'string_skill', 'label' => '字符串能力'],
        ['key' => 'computational_geometry', 'label' => '计算几何能力'],
        ['key' => 'sleep_schedule', 'label' => '作息'],
        ['key' => 'code_cleanliness', 'label' => '代码洁癖'],
        ['key' => 'otaku_vs_normie', 'label' => '文化光谱（现充↔重宅）'],
        ['key' => 'economic_axis', 'label' => '经济维度（以下四项参考意识形态学测试，可忽略）'],
        ['key' => 'social_axis', 'label' => '社会维度'],
        ['key' => 'diplomatic_axis', 'label' => '外交维度'],
        ['key' => 'authoritarian_axis', 'label' => '威权维度'],
    ];
}

function date_tech_pref_specs() {
    return [
        ['slider_key' => 'math_affinity', 'label' => '数学'],
        ['slider_key' => 'data_structure', 'label' => '数据结构'],
        ['slider_key' => 'graph_theory', 'label' => '图论'],
        ['slider_key' => 'dp_skill', 'label' => 'DP'],
        ['slider_key' => 'string_skill', 'label' => '字符串'],
        ['slider_key' => 'computational_geometry', 'label' => '计算几何'],
    ];
}

function date_default_slider_ticks() {
    $generic_5 = [
        ['pos' => 0.00, 'label' => '很低'],
        ['pos' => 0.25, 'label' => '偏低'],
        ['pos' => 0.50, 'label' => '一般'],
        ['pos' => 0.75, 'label' => '偏高'],
        ['pos' => 1.00, 'label' => '很高'],
    ];
    $skill_like = [
        ['pos' => 0.00, 'label' => '不会且厌恶'],
        ['pos' => 0.25, 'label' => '会但不爱'],
        ['pos' => 0.50, 'label' => '一般'],
        ['pos' => 0.75, 'label' => '喜欢但一般'],
        ['pos' => 1.00, 'label' => '喜爱且擅长'],
    ];
    $ticks = [
        'gender_preference_k' => [
            ['pos' => 0.00, 'label' => '只找同性'],
            ['pos' => 0.50, 'label' => '都可'],
            ['pos' => 1.00, 'label' => '只找异性'],
        ],
        'sub_dom' => [
            ['pos' => 0.00, 'label' => 'Sub'],
            ['pos' => 0.50, 'label' => '中间'],
            ['pos' => 1.00, 'label' => 'Dom'],
        ],
        'cf_rating' => [
            ['pos' => 0.00, 'label' => '灰/绿'],
            ['pos' => 0.50, 'label' => '紫/橙'],
            ['pos' => 1.00, 'label' => '红/黑'],
        ],
        'regional_medal' => [
            ['pos' => 0.00, 'label' => '无/铁'],
            ['pos' => 0.50, 'label' => '银'],
            ['pos' => 1.00, 'label' => '金'],
        ],
        'sleep_schedule' => [
            ['pos' => 0.00, 'label' => '早睡'],
            ['pos' => 0.50, 'label' => '正常'],
            ['pos' => 1.00, 'label' => '通宵'],
        ],
        'code_cleanliness' => [
            ['pos' => 0.00, 'label' => '能跑就行'],
            ['pos' => 0.50, 'label' => '一般'],
            ['pos' => 1.00, 'label' => '必须重构'],
        ],
        'otaku_vs_normie' => [
            ['pos' => 0.00, 'label' => '现充/线虫'],
            ['pos' => 0.50, 'label' => '一般'],
            ['pos' => 1.00, 'label' => '重度宅'],
        ],
        'economic_axis' => [
            ['pos' => 0.00, 'label' => '平等分配'],
            ['pos' => 0.50, 'label' => '中间'],
            ['pos' => 1.00, 'label' => '市场自由'],
        ],
        'social_axis' => [
            ['pos' => 0.00, 'label' => '个人自由'],
            ['pos' => 0.50, 'label' => '中间'],
            ['pos' => 1.00, 'label' => '社会规范'],
        ],
        'diplomatic_axis' => [
            ['pos' => 0.00, 'label' => '全球主义'],
            ['pos' => 0.50, 'label' => '中间'],
            ['pos' => 1.00, 'label' => '民族主义'],
        ],
        'authoritarian_axis' => [
            ['pos' => 0.00, 'label' => '自由意志'],
            ['pos' => 0.50, 'label' => '中间'],
            ['pos' => 1.00, 'label' => '中央控制'],
        ],
        'math_affinity' => $skill_like,
        'data_structure' => $skill_like,
        'graph_theory' => $skill_like,
        'dp_skill' => $skill_like,
        'string_skill' => $skill_like,
        'computational_geometry' => $skill_like,
    ];
    foreach (date_slider_specs() as $sp) {
        $k = $sp['key'];
        if (!isset($ticks[$k])) {
            $ticks[$k] = $generic_5;
        }
    }
    return $ticks;
}

function date_get_slider_ticks($slider_key) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (date_ticks_table_ready()) {
            $rows = DB::selectAll([
                "select slider_key, pos, label from date_slider_ticks",
                "order by slider_key asc, pos asc"
            ]);
            foreach ($rows as $r) {
                $k = $r['slider_key'];
                if (!isset($cache[$k])) {
                    $cache[$k] = [];
                }
                $cache[$k][] = ['pos' => (float)$r['pos'], 'label' => (string)$r['label']];
            }
        }
        $defaults = date_default_slider_ticks();
        foreach ($defaults as $k => $arr) {
            if (!isset($cache[$k]) || !$cache[$k]) {
                $cache[$k] = $arr;
            }
        }
    }
    $arr = $cache[$slider_key] ?? [];
    usort($arr, function($a, $b) {
        if ($a['pos'] == $b['pos']) return 0;
        return ($a['pos'] < $b['pos']) ? -1 : 1;
    });
    return $arr;
}

function date_print_slider_ticks($slider_key) {
    $ticks = date_get_slider_ticks($slider_key);
    if (!$ticks) {
        return;
    }
    echo '<div class="date-tickbar">';
    foreach ($ticks as $t) {
        $pos = (float)$t['pos'];
        if ($pos < 0) $pos = 0;
        if ($pos > 1) $pos = 1;
        $left = $pos * 100.0;
        $style = 'left:'.$left.'%;';
        if ($pos <= 0.000001) {
            $style .= 'transform:none;';
        } elseif ($pos >= 0.999999) {
            $style .= 'transform:translateX(-100%);';
        }
        echo '<div class="date-tick" style="'.$style.'">'.HTML::escape((string)$t['label']).'</div>';
    }
    echo '</div>';
}

function date_vec15_from_post(&$err) {
    $err = null;
    $specs = date_slider_specs();
    $vec = [];
    foreach ($specs as $sp) {
        $raw = $_POST['v_'.$sp['key']] ?? null;
        $v = 0.0;
        if (!date_parse_float01($raw, $v)) {
            $err = $sp['label'].' 填写不合法。';
            return null;
        }
        $vec[] = $v;
    }
    return $vec;
}

function date_techpref_from_post(&$err) {
    $err = null;
    $specs = date_tech_pref_specs();
    $prefs = [];
    foreach ($specs as $sp) {
        $raw = $_POST['p_'.$sp['slider_key']] ?? null;
        $v = 0.0;
        if (!date_parse_float01($raw, $v)) {
            $err = $sp['label'].' 填写不合法。';
            return null;
        }
        $prefs[$sp['slider_key']] = $v;
    }
    return $prefs;
}

function date_vec15_partner_ideal($vec15, $tech_pref) {
    $specs = date_slider_specs();
    $idx = [];
    foreach ($specs as $i => $sp) {
        $idx[$sp['key']] = $i;
    }
    $ideal = $vec15;
    foreach (date_tech_pref_specs() as $tp) {
        $k = $tp['slider_key'];
        if (!isset($idx[$k])) {
            continue;
        }
        $i = $idx[$k];
        $want = isset($tech_pref[$k]) ? (float)$tech_pref[$k] : 0.5;
        if ($want < 0) $want = 0;
        if ($want > 1) $want = 1;
        $ideal[$i] = $want;
    }
    return $ideal;
}

function date_hard_filter($u, $t) {
    if (!$t) return false;
    if ((int)$t['is_blacklisted'] === 1 || (int)$u['is_blacklisted'] === 1) return false;
    if ((int)$t['is_active'] !== 1 || (int)$u['is_active'] !== 1) return false;
    $k = (float)$u['gender_preference_k'];
    if ($k < 0) $k = 0;
    if ($k > 1) $k = 1;
    if (abs($k - 0.5) > 0.1) {
        $ideal_gender = ((float)$u['gender'] == 0.0) ? $k : (1.0 - $k);
        if (abs((float)$t['gender'] - $ideal_gender) >= 0.4) return false;
    }
    if (abs((float)$t['sub_dom'] - (1.0 - (float)$u['sub_dom'])) >= 0.3) return false;
    return true;
}

function date_admin_penalty($a, $b) {
    $a = (float)$a;
    $b = (float)$b;
    if ($a > 0.9 || $b > 0.9) return 0.0;
    if ($a > 0.6 && $b > 0.6) return 0.70;
    if ($a > 0.7 || $b > 0.7) return 0.85;
    return 1.0;
}

function date_score_pair($u, $t) {
    $u15 = json_decode($u['vector_15d_json'] ?? '[]', true);
    $t15 = json_decode($t['vector_15d_json'] ?? '[]', true);
    $upref = json_decode($u['tech_pref_json'] ?? '[]', true);
    $tpref = json_decode($t['tech_pref_json'] ?? '[]', true);
    if (!is_array($u15) || count($u15) !== 15) $u15 = array_fill(0, 15, 0.0);
    if (!is_array($t15) || count($t15) !== 15) $t15 = array_fill(0, 15, 0.0);
    if (!is_array($upref)) $upref = [];
    if (!is_array($tpref)) $tpref = [];
    $uideal = date_vec15_partner_ideal($u15, $upref);
    $tideal = date_vec15_partner_ideal($t15, $tpref);
    $sim15 = (date_vec_cosine_01($uideal, $t15) + date_vec_cosine_01($tideal, $u15)) / 2.0;
    $sim_admin = 1.0 - abs((float)$u['admin_projection'] - (float)$t['admin_projection']);
    if ($sim_admin < 0) $sim_admin = 0;
    if ($sim_admin > 1) $sim_admin = 1;
    $utag = json_decode($u['tag_vector_json'] ?? '[]', true);
    $ttag = json_decode($t['tag_vector_json'] ?? '[]', true);
    $sim_tags = 0.0;
    if (is_array($utag) && is_array($ttag) && count($utag) > 0 && count($ttag) > 0) {
        $sim_tags = date_vec_cosine_01($utag, $ttag);
    }
    $raw = ($sim15 + $sim_admin + $sim_tags) / 3.0;
    $pen = date_admin_penalty($u['admin_projection'], $t['admin_projection']);
    return [
        'sim15' => $sim15,
        'sim_admin' => $sim_admin,
        'sim_tags' => $sim_tags,
        'raw' => $raw,
        'penalty' => $pen,
        'final' => $raw * $pen
    ];
}

function date_top_dims($u, $t, $limit = 5) {
    $u15 = json_decode($u['vector_15d_json'] ?? '[]', true);
    $t15 = json_decode($t['vector_15d_json'] ?? '[]', true);
    $upref = json_decode($u['tech_pref_json'] ?? '[]', true);
    if (!is_array($u15) || count($u15) !== 15) $u15 = array_fill(0, 15, 0.0);
    if (!is_array($t15) || count($t15) !== 15) $t15 = array_fill(0, 15, 0.0);
    if (!is_array($upref)) $upref = [];
    $uideal = date_vec15_partner_ideal($u15, $upref);
    $specs = date_slider_specs();
    $scores = [];
    for ($i = 0; $i < 15; $i++) {
        $s = 1.0 - abs((float)$uideal[$i] - (float)$t15[$i]);
        $scores[] = ['i' => $i, 'label' => $specs[$i]['label'], 'score' => $s];
    }
    usort($scores, function($x, $y) {
        if ($x['score'] == $y['score']) return 0;
        return ($x['score'] > $y['score']) ? -1 : 1;
    });
    return array_slice($scores, 0, $limit);
}

$msg = null;
$err = null;

$tab = trim($_GET['tab'] ?? '');
if ($tab === '') $tab = 'profile';

$tabs = [
    'profile' => '填写档案',
    'submit' => '提交审核',
    'match' => '发起匹配'
];
if (date_is_admin()) {
    $tabs += [
        'admin_pending' => '待审核',
        'admin_users' => '用户管理',
        'admin_logs' => '审计日志',
        'admin_settings' => 'LLM设置'
    ];
}

$current_user_profile = null;
if (date_tables_ready()) {
    $current_user_profile = DB::selectFirst([
        "select * from date_user_profile",
        "where", ["user_id" => Auth::id()]
    ]);
}

function date_default_profile_row($user_id) {
    return [
        'user_id' => $user_id,
        'gender' => 1.0,
        'gender_preference_k' => 0.5,
        'sub_dom' => 0.5,
        'vector_15d_json' => json_encode(array_fill(0, 15, 0.5)),
        'tech_pref_json' => json_encode([
            'math_affinity' => 0.5,
            'data_structure' => 0.5,
            'graph_theory' => 0.5,
            'dp_skill' => 0.5,
            'string_skill' => 0.5,
            'computational_geometry' => 0.5
        ]),
        'bio_text' => '',
        'tags_raw_json' => json_encode(new stdClass()),
        'tag_vector_json' => json_encode([]),
        'admin_projection' => 0.0,
        'status' => 'draft',
        'is_active' => 0,
        'is_blacklisted' => 0,
        'reject_reason' => '',
        'created_at' => null,
        'updated_at' => null
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crsf_defend();
    $action = $_POST['action'] ?? '';
    try {
        if (!date_tables_ready()) {
            $err = '数据表未初始化。';
        } elseif ($action === 'save_profile') {
            $existing_profile = DB::selectFirst([
                "select * from date_user_profile",
                "where", ["user_id" => Auth::id()]
            ]);
            $is_new_profile = !$existing_profile;
            $profile = $existing_profile ?: date_default_profile_row(Auth::id());
            if (in_array($profile['status'], ['pending', 'active', 'blacklisted'], true)) {
                $err = '当前状态不允许修改档案。';
            } else {
                $gender = 0.0;
                $gender_k = 0.0;
                $sub_dom = 0.0;
                $bio_text = trim((string)($_POST['bio_text'] ?? ''));
                $bio_len = mb_strlen($bio_text, 'UTF-8');
                if ($bio_len < 100) {
                    $err = '自我介绍过短（至少 100 字）。';
                } elseif ($bio_len > 1000) {
                    $err = '自我介绍过长（最多 1000 字）。';
                } elseif (!date_parse_float01($_POST['gender'] ?? null, $gender)) {
                    $err = '性别填写不合法。';
                } elseif (!date_parse_float01($_POST['gender_preference_k'] ?? null, $gender_k)) {
                    $err = '偏好性别 k 填写不合法。';
                } elseif (!date_parse_float01($_POST['sub_dom'] ?? null, $sub_dom)) {
                    $err = 'Sub/Dom 填写不合法。';
                } else {
                    $v_err = null;
                    $vec15 = date_vec15_from_post($v_err);
                    if (!$vec15) {
                        $err = $v_err ?: '15维滑块填写不合法。';
                    } else {
                        $p_err = null;
                        $tech_pref = date_techpref_from_post($p_err);
                        if (!$tech_pref) {
                            $err = $p_err ?: '互补偏好填写不合法。';
                        } else {
                            $upd = [
                                'gender' => $gender >= 0.5 ? 1.0 : 0.0,
                                'gender_preference_k' => $gender_k,
                                'sub_dom' => $sub_dom,
                                'vector_15d_json' => json_encode($vec15),
                                'tech_pref_json' => json_encode($tech_pref),
                                'bio_text' => $bio_text,
                                'updated_at' => DB::now()
                            ];
                            if ($is_new_profile) {
                                $upd['user_id'] = Auth::id();
                                $upd['admin_projection'] = 0.0;
                                $upd['tags_raw_json'] = json_encode(new stdClass());
                                $upd['tag_vector_json'] = json_encode([]);
                                $upd['status'] = 'draft';
                                $upd['is_active'] = 0;
                                $upd['is_blacklisted'] = 0;
                                $upd['reject_reason'] = '';
                                $upd['created_at'] = DB::now();
                                DB::insert([
                                    "insert into date_user_profile",
                                    "(`user_id`, `gender`, `gender_preference_k`, `sub_dom`, `vector_15d_json`, `tech_pref_json`, `bio_text`, `tags_raw_json`, `tag_vector_json`, `admin_projection`, `status`, `is_active`, `is_blacklisted`, `reject_reason`, `created_at`, `updated_at`)",
                                    "values", DB::tuple([
                                        $upd['user_id'],
                                        $upd['gender'],
                                        $upd['gender_preference_k'],
                                        $upd['sub_dom'],
                                        $upd['vector_15d_json'],
                                        $upd['tech_pref_json'],
                                        $upd['bio_text'],
                                        $upd['tags_raw_json'],
                                        $upd['tag_vector_json'],
                                        $upd['admin_projection'],
                                        $upd['status'],
                                        $upd['is_active'],
                                        $upd['is_blacklisted'],
                                        $upd['reject_reason'],
                                        $upd['created_at'],
                                        $upd['updated_at']
                                    ])
                                ]);
                            } else {
                                DB::update([
                                    "update date_user_profile",
                                    "set", $upd,
                                    "where", ["user_id" => Auth::id()]
                                ]);
                            }
                            $msg = '保存成功。';
                        }
                    }
                }
            }
        } elseif ($action === 'submit_profile') {
            $profile = DB::selectFirst([
                "select * from date_user_profile",
                "where", ["user_id" => Auth::id()],
                DB::for_update()
            ]);
            if (!$profile) {
                $err = '请先填写档案并保存。';
            } elseif (in_array($profile['status'], ['pending', 'active', 'blacklisted'], true)) {
                $err = '当前状态不允许重复提交。';
            } else {
                DB::update([
                    "update date_user_profile",
                    "set", ["status" => 'pending', "reject_reason" => '', "updated_at" => DB::now()],
                    "where", ["user_id" => Auth::id()]
                ]);
                $msg = '已提交审核，等待管理员处理。';
                $esc_u = HTML::escape(Auth::id());
                $html = "<p>用户 <strong>{$esc_u}</strong> 提交了搭子匹配档案，等待审核。</p><p>请前往后台待审核面板处理。</p>";
                date_send_mail_to_admin('搭子匹配：有新的待审核用户', $html);
            }
        } elseif ($action === 'admin_review') {
            date_is_admin() || UOJResponse::page403();
            $user_id = trim((string)($_POST['user_id'] ?? ''));
            $decision = trim((string)($_POST['decision'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));
            $proj = 0.0;
            if (!validateUsername($user_id)) {
                $err = '用户名不合法。';
            } elseif (!in_array($decision, ['approve', 'reject', 'blacklist'], true)) {
                $err = '操作类型不合法。';
            } elseif (!date_parse_float01($_POST['admin_projection'] ?? null, $proj)) {
                $err = 'admin_projection 不合法。';
            } elseif ($decision === 'reject' && $reason === '') {
                $err = '退回需要填写理由。';
            } else {
                DB::transaction(function() use ($user_id, $decision, $reason, $proj, &$msg) {
                    $profile = DB::selectFirst([
                        "select admin_projection, status from date_user_profile",
                        "where", ["user_id" => $user_id],
                        DB::for_update()
                    ]);
                    if (!$profile) {
                        throw new Exception('用户档案不存在。');
                    }
                    $old_proj = (float)$profile['admin_projection'];
                    if ($decision === 'approve') {
                        DB::update([
                            "update date_user_profile",
                            "set", [
                                "admin_projection" => $proj,
                                "status" => 'active',
                                "is_active" => 1,
                                "is_blacklisted" => 0,
                                "reject_reason" => '',
                                "updated_at" => DB::now()
                            ],
                            "where", ["user_id" => $user_id]
                        ]);
                        DB::insert([
                            "insert into date_review_log",
                            "(user_id, admin_id, action, admin_projection_old, admin_projection_new, reason, created_at)",
                            "values", DB::tuple([$user_id, Auth::id(), 'approve', $old_proj, $proj, $reason, DB::now()])
                        ]);
                        $esc_u = HTML::escape($user_id);
                        $html = "<p>{$esc_u} 您好，</p><p>您的搭子匹配档案已通过审核并入库。</p>";
                        date_send_mail_to_user($user_id, '搭子匹配：审核通过', $html);
                        $msg = '已通过。';
                    } elseif ($decision === 'reject') {
                        DB::update([
                            "update date_user_profile",
                            "set", [
                                "admin_projection" => $proj,
                                "status" => 'rejected',
                                "is_active" => 0,
                                "is_blacklisted" => 0,
                                "reject_reason" => $reason,
                                "updated_at" => DB::now()
                            ],
                            "where", ["user_id" => $user_id]
                        ]);
                        DB::insert([
                            "insert into date_review_log",
                            "(user_id, admin_id, action, admin_projection_old, admin_projection_new, reason, created_at)",
                            "values", DB::tuple([$user_id, Auth::id(), 'reject', $old_proj, $proj, $reason, DB::now()])
                        ]);
                        $esc_u = HTML::escape($user_id);
                        $esc_r = HTML::escape($reason);
                        $html = "<p>{$esc_u} 您好，</p><p>您的搭子匹配档案被退回修改。</p><p>理由：{$esc_r}</p>";
                        date_send_mail_to_user($user_id, '搭子匹配：退回修改', $html);
                        $msg = '已退回。';
                    } else {
                        DB::update([
                            "update date_user_profile",
                            "set", [
                                "admin_projection" => $proj,
                                "status" => 'blacklisted',
                                "is_active" => 0,
                                "is_blacklisted" => 1,
                                "updated_at" => DB::now()
                            ],
                            "where", ["user_id" => $user_id]
                        ]);
                        DB::insert([
                            "insert into date_review_log",
                            "(user_id, admin_id, action, admin_projection_old, admin_projection_new, reason, created_at)",
                            "values", DB::tuple([$user_id, Auth::id(), 'blacklist', $old_proj, $proj, $reason, DB::now()])
                        ]);
                        $esc_u = HTML::escape($user_id);
                        $html = "<p>{$esc_u} 您好，</p><p>您的搭子匹配档案已被管理员处理。</p>";
                        date_send_mail_to_user($user_id, '搭子匹配：状态更新', $html);
                        $msg = '已拉黑。';
                    }
                });
            }
        } elseif ($action === 'admin_modify_projection') {
            date_is_admin() || UOJResponse::page403();
            $user_id = trim((string)($_POST['user_id'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));
            $proj = 0.0;
            if (!validateUsername($user_id)) {
                $err = '用户名不合法。';
            } elseif (!date_parse_float01($_POST['admin_projection'] ?? null, $proj)) {
                $err = 'admin_projection 不合法。';
            } else {
                DB::transaction(function() use ($user_id, $reason, $proj, &$msg) {
                    $profile = DB::selectFirst([
                        "select admin_projection from date_user_profile",
                        "where", ["user_id" => $user_id],
                        DB::for_update()
                    ]);
                    if (!$profile) {
                        throw new Exception('用户档案不存在。');
                    }
                    $old = (float)$profile['admin_projection'];
                    DB::update([
                        "update date_user_profile",
                        "set", ["admin_projection" => $proj, "updated_at" => DB::now()],
                        "where", ["user_id" => $user_id]
                    ]);
                    DB::insert([
                        "insert into date_review_log",
                        "(user_id, admin_id, action, admin_projection_old, admin_projection_new, reason, created_at)",
                        "values", DB::tuple([$user_id, Auth::id(), 'modify', $old, $proj, $reason, DB::now()])
                    ]);
                    $msg = '已更新 admin_projection。';
                });
            }
        } elseif ($action === 'admin_save_settings') {
            date_is_admin() || UOJResponse::page403();
            $did_update = false;

            if (array_key_exists('chat_completions_endpoint', $_POST)) {
                $chat_endpoint = date_trim_quotes((string)$_POST['chat_completions_endpoint']);
                if ($chat_endpoint !== '' && !preg_match('#^https?://#i', $chat_endpoint)) {
                    $err = 'chat/completions endpoint 必须是 http(s) URL。';
                } else {
                    date_set_setting('chat_completions_endpoint', $chat_endpoint);
                    $did_update = true;
                }
            }
            if ($err === null && array_key_exists('chat_model', $_POST)) {
                $chat_model = trim((string)$_POST['chat_model']);
                date_set_setting('chat_model', $chat_model);
                $did_update = true;
            }
            if ($err === null && array_key_exists('embeddings_endpoint', $_POST)) {
                $embed_endpoint = date_trim_quotes((string)$_POST['embeddings_endpoint']);
                if ($embed_endpoint !== '' && !preg_match('#^https?://#i', $embed_endpoint)) {
                    $err = 'embeddings endpoint 必须是 http(s) URL。';
                } else {
                    date_set_setting('embeddings_endpoint', $embed_endpoint);
                    $did_update = true;
                }
            }
            if ($err === null && array_key_exists('embeddings_model', $_POST)) {
                $embed_model = trim((string)$_POST['embeddings_model']);
                date_set_setting('embeddings_model', $embed_model);
                $did_update = true;
            }

            if ($err === null && array_key_exists('llm_api_key', $_POST)) {
                $api_key = trim((string)$_POST['llm_api_key']);
                if ($api_key !== '') {
                    date_set_setting('llm_api_key', $api_key);
                    $did_update = true;
                }
            }

            if ($err === null) {
                $msg = $did_update ? '设置已保存。' : '未修改任何设置。';
            }
        } elseif ($action === 'admin_llm_refresh') {
            date_is_admin() || UOJResponse::page403();
            $user_id = trim((string)($_POST['user_id'] ?? ''));
            if (!validateUsername($user_id)) {
                $err = '用户名不合法。';
            } else {
                $settings = date_load_settings([
                    'chat_completions_endpoint',
                    'chat_model',
                    'embeddings_endpoint',
                    'embeddings_model',
                    'llm_api_key'
                ]);
                $chat_ep = date_trim_quotes((string)$settings['chat_completions_endpoint']);
                $embed_ep = date_trim_quotes((string)$settings['embeddings_endpoint']);
                if ($chat_ep === '') {
                    $raw = (string)$settings['chat_completions_endpoint'];
                    $err = '未配置 chat/completions（当前读取到：'.HTML::escape($raw).'）。';
                } elseif ($embed_ep === '') {
                    $raw = (string)$settings['embeddings_endpoint'];
                    $err = '未配置 embeddings（当前读取到：'.HTML::escape($raw).'）。';
                }
                if ($err !== null) {
                    return;
                }
                $profile = DB::selectFirst([
                    "select bio_text from date_user_profile",
                    "where", ["user_id" => $user_id]
                ]);
                if (!$profile) {
                    $err = '用户档案不存在。';
                } else {
                    $api_key = $settings['llm_api_key'];
                    $e1 = null;
                    $tags = date_openai_chat_extract_tags(
                        $chat_ep,
                        $api_key,
                        $settings['chat_model'],
                        (string)$profile['bio_text'],
                        $e1
                    );
                    if (!$tags) {
                        $err = $e1 ?: '标签提取失败。';
                    } else {
                        $e2 = null;
                        $vec = date_weighted_sum_normalized(
                            $tags,
                            $embed_ep,
                            $api_key,
                            $e2,
                            $settings['embeddings_model']
                        );
                        if (!$vec) {
                            $err = $e2 ?: 'Embedding 失败。';
                        } else {
                            DB::update([
                                "update date_user_profile",
                                "set", [
                                    "tags_raw_json" => json_encode($tags, JSON_UNESCAPED_UNICODE),
                                    "tag_vector_json" => json_encode($vec),
                                    "updated_at" => DB::now()
                                ],
                                "where", ["user_id" => $user_id]
                            ]);
                            $msg = 'LLM 标签与向量已更新。';
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $err = '操作失败：' . HTML::escape($e->getMessage());
    }
    if (date_tables_ready()) {
        $current_user_profile = DB::selectFirst([
            "select * from date_user_profile",
            "where", ["user_id" => Auth::id()]
        ]);
    }
}

function date_badge_status($profile) {
    if (!$profile) return '<span class="label label-default">未创建</span>';
    $st = $profile['status'];
    if ($st === 'draft') return '<span class="label label-default">草稿</span>';
    if ($st === 'pending') return '<span class="label label-info">待审核</span>';
    if ($st === 'active') return '<span class="label label-success">活跃</span>';
    if ($st === 'rejected') return '<span class="label label-warning">退回修改</span>';
    if ($st === 'blacklisted') return '<span class="label label-danger">已拉黑</span>';
    return '<span class="label label-default">'.HTML::escape($st).'</span>';
}

?>
<?php echoUOJPageHeader('ICPC 搭子匹配系统') ?>
<style>
.date-nav { margin-bottom: 18px; }
.date-card { border: 1px solid #e6e6e6; border-radius: 10px; padding: 16px; background: #fff; margin-bottom: 14px; }
.date-card h4 { margin-top: 0; }
.date-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 992px) { .date-grid { grid-template-columns: 1fr; } }
.date-slider-row { display: grid; grid-template-columns: 180px 1fr 70px; align-items: center; gap: 10px; margin: 10px 0; }
@media (max-width: 768px) { .date-slider-row { grid-template-columns: 1fr; } }
.date-mono { font-family: Menlo, Consolas, monospace; }
.date-small { color: #666; font-size: 12px; }
.date-tickbar { position: relative; height: 18px; margin-top: 4px; }
.date-tick { position: absolute; top: 0; transform: translateX(-50%); font-size: 12px; color: #777; white-space: nowrap; }
</style>

<h2 class="page-header">ICPC 搭子匹配系统</h2>

<div class="date-nav">
    <ul class="nav nav-pills">
        <?php foreach ($tabs as $k => $label): ?>
            <li class="<?= $k === $tab ? 'active' : '' ?>"><a href="/date?tab=<?= HTML::escape($k) ?>"><?= HTML::escape($label) ?></a></li>
        <?php endforeach ?>
    </ul>
</div>

<?php date_require_schema_or_hint(); ?>

<?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif ?>

<?php
$profile = $current_user_profile ?: date_default_profile_row(Auth::id());
$vec15 = json_decode($profile['vector_15d_json'] ?? '[]', true);
if (!is_array($vec15) || count($vec15) !== 15) $vec15 = array_fill(0, 15, 0.5);
$tech_pref = json_decode($profile['tech_pref_json'] ?? '[]', true);
if (!is_array($tech_pref)) $tech_pref = [];
?>

<?php if ($tab === 'profile'): ?>
<div class="date-card">
    <h4>当前状态：<?= date_badge_status($current_user_profile) ?></h4>
    <?php if ($current_user_profile && $current_user_profile['status'] === 'rejected' && trim((string)$current_user_profile['reject_reason']) !== ''): ?>
        <div class="alert alert-warning">退回理由：<?= HTML::escape($current_user_profile['reject_reason']) ?></div>
    <?php endif ?>
    <?php if ($current_user_profile && in_array($current_user_profile['status'], ['pending', 'active', 'blacklisted'], true)): ?>
        <div class="alert alert-info">当前状态不允许修改档案。</div>
    <?php endif ?>

    <form method="post">
        <?= HTML::hiddenToken() ?>
        <input type="hidden" name="action" value="save_profile" />

        <div class="date-grid">
            <div class="date-card" style="margin:0">
                <h4>独立项（硬过滤）</h4>
                <div class="form-group">
                    <label>自身性别</label>
                    <select class="form-control" name="gender" <?= ($current_user_profile && in_array($current_user_profile['status'], ['pending', 'active', 'blacklisted'], true)) ? 'disabled' : '' ?>>
                        <option value="0" <?= ((float)$profile['gender'] < 0.5) ? 'selected' : '' ?>>女（0）</option>
                        <option value="1" <?= ((float)$profile['gender'] >= 0.5) ? 'selected' : '' ?>>男（1）</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>偏好性别 k（0=只找同性，1=只找异性）</label>
                    <input class="form-control" type="range" min="0" max="1" step="0.01" name="gender_preference_k" value="<?= HTML::escape((string)$profile['gender_preference_k']) ?>" oninput="document.getElementById('v_gender_k').innerText=this.value" <?= ($current_user_profile && in_array($current_user_profile['status'], ['pending', 'active', 'blacklisted'], true)) ? 'disabled' : '' ?> />
                    <?php date_print_slider_ticks('gender_preference_k'); ?>
                    <div class="date-small">当前：<span id="v_gender_k" class="date-mono"><?= HTML::escape((string)$profile['gender_preference_k']) ?></span></div>
                </div>
                <div class="form-group">
                    <label>关系中的（被）支配者（0=Sub，1=Dom）</label>
                    <input class="form-control" type="range" min="0" max="1" step="0.01" name="sub_dom" value="<?= HTML::escape((string)$profile['sub_dom']) ?>" oninput="document.getElementById('v_subdom').innerText=this.value" <?= ($current_user_profile && in_array($current_user_profile['status'], ['pending', 'active', 'blacklisted'], true)) ? 'disabled' : '' ?> />
                    <?php date_print_slider_ticks('sub_dom'); ?>
                    <div class="date-small">当前：<span id="v_subdom" class="date-mono"><?= HTML::escape((string)$profile['sub_dom']) ?></span></div>
                </div>
            </div>

            <div class="date-card" style="margin:0">
                <h4>自由文本</h4>
                <div class="form-group">
                    <label>自我介绍（100~1000字）</label>
                    <textarea class="form-control" name="bio_text" rows="7" minlength="100" maxlength="1000" <?= ($current_user_profile && in_array($current_user_profile['status'], ['pending', 'active', 'blacklisted'], true)) ? 'disabled' : '' ?>><?= HTML::escape((string)$profile['bio_text']) ?></textarea>
                </div>
                <div class="date-small">
                    提示：管理员可用 LLM 从这里提取标签并生成语义向量（1536维）。你可以写的东西有很多，喜欢什么类型的音乐、游戏、文学……
                </div>
            </div>
        </div>

        <div class="date-card">
            <h4>15 维滑块（0~1）</h4>
            <?php foreach (date_slider_specs() as $i => $sp): ?>
                <div class="date-slider-row">
                    <div><?= HTML::escape($sp['label']) ?></div>
                    <input class="form-control" type="range" min="0" max="1" step="0.01" name="v_<?= HTML::escape($sp['key']) ?>" value="<?= HTML::escape((string)$vec15[$i]) ?>" oninput="document.getElementById('sv_<?= HTML::escape($sp['key']) ?>').innerText=this.value" <?= ($current_user_profile && in_array($current_user_profile['status'], ['pending', 'active', 'blacklisted'], true)) ? 'disabled' : '' ?> />
                    <div class="date-mono" id="sv_<?= HTML::escape($sp['key']) ?>"><?= HTML::escape((string)$vec15[$i]) ?></div>
                </div>
                <?php date_print_slider_ticks($sp['key']); ?>
            <?php endforeach ?>
        </div>

        <div class="date-card">
            <h4>技术维度偏好（你希望队友在哪）</h4>
            <div class="date-small">这是你对“队友”的期望，不是你自己的水平。你固然可以希望队友是超人，但如此贪心应会降低匹配度。</div>
            <?php foreach (date_tech_pref_specs() as $tp): ?>
                <?php $k = $tp['slider_key']; $cur = isset($tech_pref[$k]) ? (float)$tech_pref[$k] : 0.0; ?>
                <div class="date-slider-row">
                    <div><?= HTML::escape($tp['label']) ?></div>
                    <input class="form-control" type="range" min="0" max="1" step="0.01" name="p_<?= HTML::escape($k) ?>" value="<?= HTML::escape((string)$cur) ?>" oninput="document.getElementById('pv_<?= HTML::escape($k) ?>').innerText=this.value" <?= ($current_user_profile && in_array($current_user_profile['status'], ['pending', 'active', 'blacklisted'], true)) ? 'disabled' : '' ?> />
                    <div class="date-mono" id="pv_<?= HTML::escape($k) ?>"><?= HTML::escape((string)$cur) ?></div>
                </div>
                <?php date_print_slider_ticks($k); ?>
            <?php endforeach ?>
        </div>

        <div class="text-right">
            <button class="btn btn-primary" <?= ($current_user_profile && in_array($current_user_profile['status'], ['pending', 'active', 'blacklisted'], true)) ? 'disabled' : '' ?>>保存草稿</button>
        </div>
    </form>
</div>
<?php endif ?>

<?php if ($tab === 'submit'): ?>
<div class="date-card">
    <h4>状态机</h4>
    <div>当前状态：<?= date_badge_status($current_user_profile) ?></div>
    <div class="date-small">草稿 → 待审核 → 活跃；可被退回修改；可被拉黑。</div>
    <?php if ($current_user_profile && $current_user_profile['status'] === 'rejected' && trim((string)$current_user_profile['reject_reason']) !== ''): ?>
        <div class="alert alert-warning">退回理由：<?= HTML::escape($current_user_profile['reject_reason']) ?></div>
    <?php endif ?>
    <hr />
    <form method="post">
        <?= HTML::hiddenToken() ?>
        <input type="hidden" name="action" value="submit_profile" />
        <button class="btn btn-success" <?= (!$current_user_profile || !in_array($current_user_profile['status'], ['draft', 'rejected'], true)) ? 'disabled' : '' ?>>提交审核</button>
        <a class="btn btn-default" href="/date?tab=profile">返回填写档案</a>
    </form>
</div>
<?php endif ?>

<?php if ($tab === 'match'): ?>
<div class="date-card">
    <h4>发起匹配</h4>
    <?php if (!$current_user_profile): ?>
        <div class="alert alert-warning">请先填写档案并保存。</div>
    <?php elseif ($current_user_profile['status'] !== 'active'): ?>
        <div class="alert alert-warning">只有“活跃”用户才可参与匹配。</div>
    <?php else: ?>
        <?php
        $k = date_parse_uint_or_null($_GET['k'] ?? null);
        if ($k === null) $k = 10;
        if ($k < 1) $k = 1;
        if ($k > 50) $k = 50;
        $disable_hard = (string)($_GET['disable_hard'] ?? '') === '1';
        $user_row = DB::selectFirst([
            "select * from date_user_profile",
            "where", ["user_id" => Auth::id()]
        ]);
        $cands = DB::selectAll([
            "select user_id, gender, gender_preference_k, sub_dom, vector_15d_json, tech_pref_json, admin_projection, tag_vector_json, tags_raw_json, status, is_active, is_blacklisted",
            "from date_user_profile",
            "where", [
                ["user_id", "!=", Auth::id()],
                ["is_active", "=", 1],
                ["is_blacklisted", "=", 0]
            ]
        ]);
        $scores = [];
        foreach ($cands as $t) {
            if (!$disable_hard && !date_hard_filter($user_row, $t)) {
                continue;
            }
            $pen = date_admin_penalty($user_row['admin_projection'], $t['admin_projection']);
            if ($pen <= 0) {
                continue;
            }
            $sc = date_score_pair($user_row, $t);
            $scores[] = ['user' => $t, 'score' => $sc];
        }
        usort($scores, function($a, $b) {
            $x = $a['score']['final'];
            $y = $b['score']['final'];
            if ($x == $y) return 0;
            return ($x > $y) ? -1 : 1;
        });
        $scores = array_slice($scores, 0, $k);
        ?>
        <form method="get" class="form-inline">
            <input type="hidden" name="tab" value="match" />
            <select class="form-control" name="k">
                <?php foreach ([5, 10, 20, 30, 50] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $k === $opt ? 'selected' : '' ?>>Top <?= $opt ?></option>
                <?php endforeach ?>
            </select>
            <label class="checkbox-inline" style="margin-left:10px;">
                <input type="checkbox" name="disable_hard" value="1" <?= $disable_hard ? 'checked' : '' ?> />
                关闭性别/Sub-Dom 硬筛选
            </label>
            <button class="btn btn-primary">刷新</button>
        </form>
        <hr />
        <?php if (!$scores): ?>
            <div class="alert alert-info">暂无候选<?= $disable_hard ? '' : '（或均被硬过滤过滤）' ?>。</div>
        <?php else: ?>
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>用户</th>
                        <th>匹配度</th>
                        <th>15滑块</th>
                        <th>标签语义</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($scores as $row): ?>
                    <?php $u = $row['user']; $sc = $row['score']; ?>
                    <tr>
                        <td><?= getUserLink($u['user_id']) ?></td>
                        <td class="date-mono"><?= number_format((float)$sc['final'], 4) ?></td>
                        <td class="date-mono"><?= number_format((float)$sc['sim15'], 4) ?></td>
                        <td class="date-mono"><?= number_format((float)$sc['sim_tags'], 4) ?></td>
                        <td><a class="btn btn-xs btn-default" href="/date?tab=reason&u=<?= HTML::escape($u['user_id']) ?><?= $disable_hard ? '&disable_hard=1' : '' ?>">查看理由</a></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    <?php endif ?>
</div>
<?php endif ?>

<?php if ($tab === 'reason'): ?>
<div class="date-card">
    <h4>匹配理由</h4>
    <?php
    $target_id = trim((string)($_GET['u'] ?? ''));
    $disable_hard = (string)($_GET['disable_hard'] ?? '') === '1';
    if (!validateUsername($target_id)) {
        echo '<div class="alert alert-danger">目标用户不合法。</div>';
    } elseif (!date_tables_ready()) {
        echo '<div class="alert alert-danger">数据表未初始化。</div>';
    } else {
        $u = DB::selectFirst([
            "select * from date_user_profile",
            "where", ["user_id" => Auth::id()]
        ]);
        if (!$u || $u['status'] !== 'active') {
            echo '<div class="alert alert-warning">只有活跃用户可查看匹配理由。</div>';
        } else {
            $t = DB::selectFirst([
                "select * from date_user_profile",
                "where", ["user_id" => $target_id]
            ]);
            if (!$t || $t['status'] !== 'active') {
                echo '<div class="alert alert-warning">目标用户不存在或未处于活跃状态。</div>';
            } elseif (!$disable_hard && !date_hard_filter($u, $t)) {
                echo '<div class="alert alert-warning">该用户未通过硬过滤。</div>';
            } else {
                $sc = date_score_pair($u, $t);
                $dims = date_top_dims($u, $t, 5);
                echo '<div>目标：'.getUserLink($t['user_id']).'</div>';
                echo '<hr />';
                echo '<div class="date-grid">';
                echo '<div class="date-card" style="margin:0">';
                echo '<h4>分数拆解</h4>';
                echo '<div>最终匹配度：<span class="date-mono">'.number_format((float)$sc['final'], 4).'</span></div>';
                echo '<div>15维滑块：<span class="date-mono">'.number_format((float)$sc['sim15'], 4).'</span></div>';
                echo '<div>标签语义：<span class="date-mono">'.number_format((float)$sc['sim_tags'], 4).'</span></div>';
                if ($disable_hard) {
                    echo '<div class="date-small">提示：本次查看关闭了性别/Sub-Dom 硬筛选。</div>';
                }
                echo '<div class="date-small">管理员投影参与计算，但对普通用户不可见。</div>';
                echo '</div>';
                echo '<div class="date-card" style="margin:0">';
                echo '<h4>契合点（Top 5）</h4>';
                echo '<table class="table table-condensed">';
                echo '<thead><tr><th>维度</th><th>契合度</th></tr></thead><tbody>';
                foreach ($dims as $d) {
                    echo '<tr><td>'.HTML::escape($d['label']).'</td><td class="date-mono">'.number_format((float)$d['score'], 4).'</td></tr>';
                }
                echo '</tbody></table>';
                echo '</div>';
                echo '</div>';
            }
        }
    }
    ?>
    <hr />
    <a class="btn btn-default" href="/date?tab=match<?= $disable_hard ? '&disable_hard=1' : '' ?>">返回匹配</a>
</div>
<?php endif ?>

<?php if ($tab === 'admin_pending' && date_is_admin()): ?>
<div class="date-card">
    <h4>待审核列表</h4>
    <?php if (date_tables_ready()): ?>
        <?php
        $pending = DB::selectAll([
            "select user_id, updated_at from date_user_profile",
            "where", ["status" => 'pending'],
            "order by updated_at asc"
        ]);
        ?>
        <?php if (!$pending): ?>
            <div class="alert alert-info">暂无待审核用户。</div>
        <?php else: ?>
            <table class="table table-bordered table-hover">
                <thead><tr><th>用户</th><th>更新时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td><?= getUserLink($p['user_id']) ?></td>
                        <td><?= HTML::escape((string)$p['updated_at']) ?></td>
                        <td><a class="btn btn-xs btn-primary" href="/date?tab=admin_user&u=<?= HTML::escape($p['user_id']) ?>">进入审核</a></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    <?php endif ?>
</div>
<?php endif ?>

<?php if ($tab === 'admin_user' && date_is_admin()): ?>
<div class="date-card">
    <h4>审核用户</h4>
    <?php
    $u = trim((string)($_GET['u'] ?? ''));
    if (!validateUsername($u)) {
        echo '<div class="alert alert-danger">用户名不合法。</div>';
    } elseif (!date_tables_ready()) {
        echo '<div class="alert alert-danger">数据表未初始化。</div>';
    } else {
        $p = DB::selectFirst([
            "select * from date_user_profile",
            "where", ["user_id" => $u]
        ]);
        if (!$p) {
            echo '<div class="alert alert-danger">用户档案不存在。</div>';
        } else {
            $p15 = json_decode($p['vector_15d_json'] ?? '[]', true);
            if (!is_array($p15) || count($p15) !== 15) $p15 = array_fill(0, 15, 0.0);
            $pt = json_decode($p['tags_raw_json'] ?? '{}', true);
            if (!is_array($pt)) $pt = [];
            ?>
            <div class="date-grid">
                <div class="date-card" style="margin:0">
                    <h4>基本信息</h4>
                    <div>用户：<?= getUserLink($p['user_id']) ?></div>
                    <div>状态：<?= date_badge_status($p) ?></div>
                    <div class="date-small">updated_at：<?= HTML::escape((string)$p['updated_at']) ?></div>
                    <?php if ($p['status'] === 'rejected' && trim((string)$p['reject_reason']) !== ''): ?>
                        <div class="alert alert-warning">退回理由：<?= HTML::escape($p['reject_reason']) ?></div>
                    <?php endif ?>
                </div>
                <div class="date-card" style="margin:0">
                    <h4>自由文本</h4>
                    <pre style="white-space:pre-wrap;"><?= HTML::escape((string)$p['bio_text']) ?></pre>
                </div>
            </div>

            <div class="date-card">
                <h4>LLM 标签</h4>
                <?php if (!$pt): ?>
                    <div class="text-muted">暂无标签</div>
                <?php else: ?>
                    <div class="date-small">tags_raw</div>
                    <pre style="white-space:pre-wrap;"><?= HTML::escape(json_encode($pt, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                <?php endif ?>
                <form method="post" class="form-inline">
                    <?= HTML::hiddenToken() ?>
                    <input type="hidden" name="action" value="admin_llm_refresh" />
                    <input type="hidden" name="user_id" value="<?= HTML::escape($p['user_id']) ?>" />
                    <button class="btn btn-default">刷新标签/向量</button>
                    <span class="date-small">需要先在“LLM设置”配置 endpoint 与 key。</span>
                </form>
            </div>

            <div class="date-card">
                <h4>审核操作</h4>
                <form method="post">
                    <?= HTML::hiddenToken() ?>
                    <input type="hidden" name="action" value="admin_review" />
                    <input type="hidden" name="user_id" value="<?= HTML::escape($p['user_id']) ?>" />
                    <div class="form-group">
                        <label>admin_projection（0~1）</label>
                        <input class="form-control" name="admin_projection" value="<?= HTML::escape((string)$p['admin_projection']) ?>" />
                    </div>
                    <div class="form-group">
                        <label>操作</label>
                        <select class="form-control" name="decision">
                            <option value="approve">通过</option>
                            <option value="reject">退回</option>
                            <option value="blacklist">拉黑</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>理由/备注（退回必填）</label>
                        <input class="form-control" name="reason" style="min-width:340px" />
                    </div>
                    <button class="btn btn-primary">提交</button>
                </form>
            </div>
            <?php
        }
    }
    ?>
    <a class="btn btn-default" href="/date?tab=admin_pending">返回待审核</a>
</div>
<?php endif ?>

<?php if ($tab === 'admin_users' && date_is_admin()): ?>
<div class="date-card">
    <h4>已入库用户管理</h4>
    <?php if (date_tables_ready()): ?>
        <?php
        $rows = DB::selectAll([
            "select user_id, admin_projection, status, updated_at from date_user_profile",
            "order by updated_at desc",
            DB::limit(500)
        ]);
        ?>
        <?php if (!$rows): ?>
            <div class="alert alert-info">暂无用户记录。</div>
        <?php else: ?>
        <table class="table table-bordered table-hover">
            <thead><tr><th>用户</th><th>状态</th><th>admin_projection</th><th>更新时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= getUserLink($r['user_id']) ?></td>
                    <td><?= date_badge_status($r) ?></td>
                    <td class="date-mono"><?= number_format((float)$r['admin_projection'], 3) ?></td>
                    <td><?= HTML::escape((string)$r['updated_at']) ?></td>
                    <td>
                        <a class="btn btn-xs btn-default" href="/date?tab=admin_user&u=<?= HTML::escape($r['user_id']) ?>">详情</a>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php endif ?>
        <div class="date-card">
            <h4>修改 admin_projection</h4>
            <form method="post" class="form-inline">
                <?= HTML::hiddenToken() ?>
                <input type="hidden" name="action" value="admin_modify_projection" />
                <input class="form-control" name="user_id" placeholder="用户名" required />
                <input class="form-control" name="admin_projection" placeholder="0~1" required />
                <input class="form-control" name="reason" placeholder="备注（可选）" style="min-width:280px" />
                <button class="btn btn-warning">修改</button>
            </form>
        </div>
    <?php endif ?>
</div>
<?php endif ?>

<?php if ($tab === 'admin_logs' && date_is_admin()): ?>
<div class="date-card">
    <h4>管理员审计日志</h4>
    <?php if (date_tables_ready()): ?>
        <?php
        $f_user = trim((string)($_GET['user'] ?? ''));
        $f_action = trim((string)($_GET['action'] ?? ''));
        $conds = [];
        if ($f_user !== '' && validateUsername($f_user)) {
            $conds[] = ['user_id', '=', $f_user];
        }
        if ($f_action !== '' && in_array($f_action, ['approve', 'reject', 'blacklist', 'modify'], true)) {
            $conds[] = ['action', '=', $f_action];
        }
        $q = [
            "select id, user_id, admin_id, action, admin_projection_old, admin_projection_new, reason, created_at",
            "from date_review_log"
        ];
        if ($conds) {
            $q[] = "where";
            $q[] = $conds;
        }
        $q[] = "order by id desc";
        $q[] = DB::limit(200);
        $logs = DB::selectAll($q);
        ?>
        <form method="get" class="form-inline">
            <input type="hidden" name="tab" value="admin_logs" />
            <input class="form-control" name="user" placeholder="用户" value="<?= HTML::escape($f_user) ?>" />
            <select class="form-control" name="action">
                <option value="">全部操作</option>
                <?php foreach (['approve' => 'approve', 'reject' => 'reject', 'blacklist' => 'blacklist', 'modify' => 'modify'] as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $f_action === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach ?>
            </select>
            <button class="btn btn-primary">筛选</button>
            <a class="btn btn-default" href="/date?tab=admin_logs">重置</a>
        </form>
        <hr />
        <table class="table table-bordered table-hover">
            <thead><tr><th>ID</th><th>用户</th><th>管理员</th><th>操作</th><th>旧值</th><th>新值</th><th>备注</th><th>时间</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="date-mono"><?= (int)$l['id'] ?></td>
                    <td><?= getUserLink($l['user_id']) ?></td>
                    <td><?= getUserLink($l['admin_id']) ?></td>
                    <td class="date-mono"><?= HTML::escape($l['action']) ?></td>
                    <td class="date-mono"><?= $l['admin_projection_old'] === null ? '' : number_format((float)$l['admin_projection_old'], 3) ?></td>
                    <td class="date-mono"><?= $l['admin_projection_new'] === null ? '' : number_format((float)$l['admin_projection_new'], 3) ?></td>
                    <td><?= HTML::escape((string)$l['reason']) ?></td>
                    <td><?= HTML::escape((string)$l['created_at']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <div class="date-small">仅展示最近 200 条。</div>
    <?php endif ?>
</div>
<?php endif ?>

<?php if ($tab === 'admin_settings' && date_is_admin()): ?>
<div class="date-card">
    <h4>LLM 设置</h4>
    <?php if (date_tables_ready()): ?>
        <?php
        $settings = date_load_settings([
            'chat_completions_endpoint',
            'chat_model',
            'embeddings_endpoint',
            'embeddings_model',
            'llm_api_key'
        ]);
        $masked_key = $settings['llm_api_key'] ? str_repeat('*', 10) : '';
        ?>
        <form method="post">
            <?= HTML::hiddenToken() ?>
            <input type="hidden" name="action" value="admin_save_settings" />
            <div class="form-group">
                <label>chat/completions Endpoint（OpenAI兼容）</label>
                <input class="form-control" name="chat_completions_endpoint" value="<?= HTML::escape((string)$settings['chat_completions_endpoint']) ?>" placeholder="https://.../v1/chat/completions" />
            </div>
            <div class="form-group">
                <label>chat 模型（可选）</label>
                <input class="form-control" name="chat_model" value="<?= HTML::escape((string)$settings['chat_model']) ?>" placeholder="例如 gpt-4o-mini / deepseek-chat" />
            </div>
            <div class="form-group">
                <label>embeddings Endpoint（OpenAI兼容）</label>
                <input class="form-control" name="embeddings_endpoint" value="<?= HTML::escape((string)$settings['embeddings_endpoint']) ?>" placeholder="https://.../v1/embeddings" />
            </div>
            <div class="form-group">
                <label>embeddings 模型（可选）</label>
                <input class="form-control" name="embeddings_model" value="<?= HTML::escape((string)$settings['embeddings_model']) ?>" placeholder="例如 text-embedding-3-small" />
            </div>
            <div class="form-group">
                <label>API Key（不回显旧值）</label>
                <input class="form-control" name="llm_api_key" value="" placeholder="<?= $masked_key ? '已配置（留空不修改）' : '未配置' ?>" />
            </div>
            <button class="btn btn-primary">保存设置</button>
        </form>
        <div class="date-small">保存后，在“审核用户”页点击“刷新标签/向量”即可写回 tags_raw 与 tag_vector。</div>
        <div class="date-small">API Key 仅保存在数据库中，页面不会回显旧值。</div>
    <?php endif ?>
</div>
<?php endif ?>

<?php echoUOJPageFooter() ?>

