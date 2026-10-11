<?php
// deepseek.php
contest_security_mode_block_if_needed('deepseek', Auth::user());
requireLib('mathjax');
echoUOJPageHeader('DeepSeek问答');

// 配置参数
define('API_KEY', getenv('SILICONFLOW_API_KEY') ?: '');
define('API_ENDPOINT', 'https://api.siliconflow.cn/v1/chat/completions');
define('API_MODEL', 'Qwen/Qwen2.5-7B-Instruct');
define('CACHE_DIR', '/tmp/deepseek_cache/');

// 创建缓存目录
if (!file_exists(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

if (!function_exists('deepseekFetchSkills')) {
    function deepseekFetchSkills() {
        return DB::selectAll([
            "select name, skill from skills",
            "order by name"
        ]) ?: [];
    }
}

// 辅助函数：安全的HTML转义
function safeHtmlEscape($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// 获取问题参数
$question = isset($_GET['q']) ? trim($_GET['q']) : '';
$selectedSkillName = isset($_GET['skill']) ? trim($_GET['skill']) : '';
$error = null;
$result = null;
$skills = deepseekFetchSkills();
$selectedSkillContent = '';

foreach ($skills as $skillRow) {
    if ($skillRow['name'] === $selectedSkillName) {
        $selectedSkillContent = trim($skillRow['skill']);
        break;
    }
}

if ($question !== '') {
    $effectiveQuestion = $question;
    if ($selectedSkillContent !== '') {
        $effectiveQuestion = $selectedSkillContent . "\n\n" . $question;
    }

    $cacheFile = CACHE_DIR . md5(json_encode([$selectedSkillName, $question])) . '.json';
    
    // 1. 尝试读取缓存
    if (file_exists($cacheFile)) {
        $result = json_decode(file_get_contents($cacheFile), true);
    } else {
        // 2. 直接调用API
        $result = callSiliconFlowAPI($effectiveQuestion);
        
        if (!$result) {
            // 3. 使用本地预设回答
            $result = [
                'question' => $question,
                'answer' => generateFallbackAnswer($question),
                'timestamp' => time(),
                'source' => 'preset'
            ];
        }
        
        // 保存结果到缓存
        file_put_contents($cacheFile, json_encode($result));
    }

    if ($result) {
        $result['question'] = $question;
        $result['selected_skill_name'] = $selectedSkillName;
    }
}

/**
 * 调用SiliconFlow API
 */
function callSiliconFlowAPI($question) {
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . API_KEY
    ];
    
    $data = [
        'model' => API_MODEL,
        'messages' => [
            ['role' => 'user', 'content' => $question]
        ],
        'temperature' => 0.7,
        'max_tokens' => 2000
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => API_ENDPOINT,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['choices'][0]['message']['content'])) {
            return [
                'question' => $question,
                'answer' => $data['choices'][0]['message']['content'],
                'timestamp' => time(),
                'source' => 'api'
            ];
        }
    }
    
    return false;
}

/**
 * 生成本地备用回答
 */
function generateFallbackAnswer($question) {
    $presetAnswers = [
        'who are you' => "我是DeepSeek AI助手，当API不可用时提供本地回答。您的问题【".$question."】需要联网获取更准确答案。",
        'default' => "关于【".$question."】的参考回答（当前无法连接AI服务）\n\n常见解决方案：\n1. 检查服务器时间是否准确\n2. 运行命令测试：\n   curl -v ".API_ENDPOINT."\n3. 联系管理员检查防火墙"
    ];
    
    return $presetAnswers[strtolower($question)] ?? $presetAnswers['default'];
}

/**
 * 优先使用站内 Markdown 编译器，失败时回退到简易转换。
 */
function convertMarkdownToHTML($text) {
    if (class_exists('UOJMarkdown') && method_exists('UOJMarkdown', 'compile_from_markdown')) {
        $html = UOJMarkdown::compile_from_markdown($text, ['type' => 'uoj']);
        if ($html !== false && $html !== null) {
            return $html;
        }
    }

    return convertMarkdownToHTMLFallback($text);
}

/**
 * 简易Markdown转HTML回退实现。
 */
function convertMarkdownToHTMLFallback($text) {
    // 使用自定义的转义函数
    $html = safeHtmlEscape($text);
    
    // 转换代码块
    $html = preg_replace_callback(
        '/```(\w*)\s*\n(.*?)\n```/s',
        function($matches) {
            $lang = safeHtmlEscape($matches[1]);
            $code = safeHtmlEscape($matches[2]);
            $langClass = !empty($lang) ? ' class="language-' . $lang . '"' : '';
            return '<pre><code' . $langClass . '>' . $code . '</code></pre>';
        },
        $html
    );
    
    // 转换链接
    $html = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)]+)\)/',
        function($matches) {
            $linkText = safeHtmlEscape($matches[1]);
            $linkUrl = safeHtmlEscape($matches[2]);
            return '<a href="' . $linkUrl . '" target="_blank">' . $linkText . '</a>';
        },
        $html
    );
    
    // 转换标题
    $html = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $html);
    
    // 转换加粗和斜体
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);
    
    // 转换换行
    $html = nl2br($html);
    
    return $html;
}

?>
<div class="row">
    <div class="col-md-9">
        <form method="get" class="form-horizontal">
            <div class="row">
                <div class="col-sm-4 col-md-3">
                    <select class="form-control" name="skill">
                        <?php foreach ($skills as $skillRow): ?>
                            <option value="<?= safeHtmlEscape($skillRow['name']) ?>" <?= $selectedSkillName === $skillRow['name'] ? 'selected' : '' ?>>
                                <?= safeHtmlEscape($skillRow['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-8 col-md-9">
                    <div class="input-group">
                        <input type="text" class="form-control" name="q"
                               value="<?= safeHtmlEscape($question) ?>"
                               placeholder="输入问题..." required>
                        <span class="input-group-btn">
                            <button type="submit" class="btn btn-primary">提问</button>
                        </span>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($result): ?>
            <div class="panel panel-default top-buffer-md">
                <div class="panel-body uoj-article">
                    <?= convertMarkdownToHTML($result['answer']) ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
echoUOJPageFooter();
?>
