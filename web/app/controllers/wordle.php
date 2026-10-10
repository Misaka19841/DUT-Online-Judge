<?php
// Wordle 游戏 - 动态长度版本
session_start();

define('MAX_ATTEMPTS', 6);

function loadWordBank() {
    // 检查session中是否已有词库缓存
    if (isset($_SESSION['cached_wordbank']) && !isset($_GET['refresh_cache'])) {
        return $_SESSION['cached_wordbank'];
    }
    
    $csvFile = __DIR__ . '/wordlist.csv';
    $wordBank = [];
    
    if (!file_exists($csvFile)) {
        // 默认词库（多种长度）
        return [
            'APPLE', 'BEACH', 'CANDY', 'DREAM', 'EARTH',  // 5字母
            'PYTHON', 'JAVASCRIPT', 'PHP', 'RUBY', 'SWIFT',  // 不同长度
            'CAT', 'DOG', 'FISH', 'BIRD', 'TREE'  // 3字母
        ];
    }
    
    if (($handle = fopen($csvFile, 'r')) !== false) {
        $header = fgetcsv($handle);
        $wordColumnIndex = null;
        
        foreach ($header as $index => $columnName) {
            if (strtolower(trim($columnName)) === 'word') {
                $wordColumnIndex = $index;
                break;
            }
        }
        
        if ($wordColumnIndex === null) {
            $wordColumnIndex = 0;
        }
        
        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[$wordColumnIndex])) {
                $word = strtoupper(trim($row[$wordColumnIndex]));
                // 只保留纯字母单词，不限制长度
                if (!empty($word) && ctype_alpha($word)) {
                    $wordBank[] = $word;
                }
            }
        }
        fclose($handle);
    }
    
    // 去重
    $wordBank = array_unique($wordBank);
    
    if (empty($wordBank)) {
        $wordBank = ['APPLE', 'BEACH', 'CANDY', 'DREAM', 'EARTH'];
    }
    
    // 缓存到session
    $_SESSION['cached_wordbank'] = $wordBank;
    
    return $wordBank;
}

// 加载词库
$wordBank = loadWordBank();

// 获取当前游戏的单词长度（从session中读取，如果未设置则随机选择一个单词并设置长度）
function getCurrentWordLength() {
    if (isset($_SESSION['word_length'])) {
        return $_SESSION['word_length'];
    }
    
    // 随机选择一个单词作为答案，并设置长度
    global $wordBank;
    $secret = $wordBank[array_rand($wordBank)];
    $_SESSION['secret'] = $secret;
    $_SESSION['word_length'] = strlen($secret);
    
    return $_SESSION['word_length'];
}

// 初始化或重置游戏
function initGame() {
    global $wordBank;
    if (empty($wordBank)) {
        $_SESSION['secret'] = 'APPLE';
        $_SESSION['word_length'] = 5;
    } else {
        $_SESSION['secret'] = $wordBank[array_rand($wordBank)];
        $_SESSION['word_length'] = strlen($_SESSION['secret']);
    }
    $_SESSION['attempts'] = [];
    $_SESSION['game_over'] = false;
    $_SESSION['win'] = false;
    $_SESSION['current_attempt'] = 0;
    $_SESSION['message'] = '';
}

// 检查单词是否在词库中（同时检查长度）
function isValidWord($word) {
    global $wordBank;
    $word = strtoupper(trim($word));
    
    // 检查长度是否匹配
    if (strlen($word) != $_SESSION['word_length']) {
        return false;
    }
    
    return in_array($word, $wordBank);
}

// 处理猜测
function makeGuess($guess) {
    $guess = strtoupper(trim($guess));
    $wordLength = $_SESSION['word_length'];
    
    if ($_SESSION['game_over']) {
        $_SESSION['message'] = "游戏已结束，请开始新游戏。";
        return false;
    }
    
    if (strlen($guess) != $wordLength) {
        $_SESSION['message'] = "请输入 " . $wordLength . " 个字母的单词。";
        return false;
    }
    
    if (!ctype_alpha($guess)) {
        $_SESSION['message'] = "只能包含字母。";
        return false;
    }
    
    if (!isValidWord($guess)) {
        $_SESSION['message'] = "单词不在词库中。";
        return false;
    }
    
    $secret = $_SESSION['secret'];
    $result = [];
    $secretLetters = str_split($secret);
    $guessLetters = str_split($guess);
    
    // 第一步：标记完全正确的位置（绿色）
    for ($i = 0; $i < $wordLength; $i++) {
        if ($guessLetters[$i] === $secretLetters[$i]) {
            $result[$i] = 'green';
            $secretLetters[$i] = null;
            $guessLetters[$i] = null;
        }
    }
    
    // 第二步：标记存在但位置不对的字母（黄色）
    for ($i = 0; $i < $wordLength; $i++) {
        if ($guessLetters[$i] !== null) {
            $foundIndex = array_search($guessLetters[$i], $secretLetters);
            if ($foundIndex !== false) {
                $result[$i] = 'yellow';
                $secretLetters[$foundIndex] = null;
            } else {
                $result[$i] = 'gray';
            }
        } else {
            if (!isset($result[$i])) $result[$i] = 'green';
        }
    }
    
    // 记录本次猜测和结果
    $_SESSION['attempts'][] = ['word' => $guess, 'result' => $result];
    $_SESSION['current_attempt']++;
    
    // 判断是否猜中
    if ($guess === $_SESSION['secret']) {
        $_SESSION['game_over'] = true;
        $_SESSION['win'] = true;
        $_SESSION['message'] = "恭喜！你猜对了！答案是 " . $_SESSION['secret'];
        return true;
    }
    
    // 判断是否达到最大尝试次数
    if ($_SESSION['current_attempt'] >= MAX_ATTEMPTS) {
        $_SESSION['game_over'] = true;
        $_SESSION['win'] = false;
        $_SESSION['message'] = "游戏结束！正确答案是 " . $_SESSION['secret'];
    }
    
    return true;
}

// 处理重置请求
if (isset($_GET['reset'])) {
    initGame();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// 处理提交的猜测
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guess'])) {
    $guess = $_POST['guess'];
    makeGuess($guess);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// 如果会话中没有游戏数据，初始化
if (!isset($_SESSION['secret'])) {
    initGame();
}

// 获取当前游戏状态
$wordLength = $_SESSION['word_length'];
$secret = $_SESSION['secret'];
$attempts = $_SESSION['attempts'];
$game_over = $_SESSION['game_over'];
$win = $_SESSION['win'];
$message = $_SESSION['message'];
$current_attempt = $_SESSION['current_attempt'];
$remaining = MAX_ATTEMPTS - $current_attempt;
$totalWords = count($wordBank);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Wordle 游戏 - 动态长度版</title>
    <style>
        * {
            box-sizing: border-box;
            user-select: none;
        }
        body {
            font-family: 'Segoe UI', 'Courier New', monospace;
            background: #121213;
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .game-container {
            max-width: 19200px;
            width: 100%;
            text-align: center;
        }
        h1 {
            font-size: 2.5rem;
            letter-spacing: 2px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #fff;
            text-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }
        .sub {
            color: #888;
            margin-bottom: 30px;
            font-size: 0.9rem;
        }
        .word-length-info {
            background: #2c2c2e;
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }
        .board {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 30px;
            background: #1a1a1c;
            padding: 15px;
            border-radius: 16px;
            overflow-x: auto;
        }
        .attempt-row {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .tile {
            width: 60px;
            height: 60px;
            background: #3a3a3c;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: bold;
            text-transform: uppercase;
            transition: all 0.1s ease;
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
            flex-shrink: 0;
        }
        /* 根据单词长度自适应宽度 */
        @media (max-width: 768px) {
            .tile {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
        }
        @media (max-width: 600px) {
            .tile {
                width: 45px;
                height: 45px;
                font-size: 1.3rem;
            }
            .attempt-row {
                gap: 5px;
            }
        }
        @media (max-width: 480px) {
            .tile {
                width: 40px;
                height: 40px;
                font-size: 1.1rem;
            }
        }
        .green {
            background: #538d4e;
            transform: scale(0.98);
        }
        .yellow {
            background: #b59f3b;
        }
        .gray {
            background: #3a3a3c;
        }
        .empty {
            background: #2c2c2e;
            border: 1px solid #3a3a3c;
            box-shadow: none;
        }
        .input-area {
            margin-top: 10px;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        input {
            padding: 14px 18px;
            font-size: 1.2rem;
            border: none;
            border-radius: 40px;
            background: #2c2c2e;
            color: white;
            font-family: monospace;
            text-transform: uppercase;
            text-align: center;
            letter-spacing: 2px;
            width: 220px;
            outline: none;
        }
        input:focus {
            background: #3a3a3c;
            border: 1px solid #538d4e;
        }
        button {
            padding: 14px 24px;
            font-size: 1rem;
            font-weight: bold;
            border: none;
            border-radius: 40px;
            background: #538d4e;
            color: white;
            cursor: pointer;
            transition: 0.2s;
            font-family: inherit;
        }
        button:hover {
            background: #6aaa64;
            transform: scale(1.02);
        }
        .reset-btn {
            background: #3a3a3c;
            margin-left: 8px;
        }
        .reset-btn:hover {
            background: #565758;
        }
        .message {
            margin-top: 20px;
            padding: 12px;
            background: #1e1e20;
            border-radius: 40px;
            font-weight: bold;
            color: #f5c542;
            font-size: 0.95rem;
        }
        .win-message {
            color: #6aaa64;
        }
        .info {
            font-size: 0.8rem;
            margin-top: 20px;
            color: #aaa;
        }
        .word-count {
            font-size: 0.7rem;
            color: #666;
            margin-top: 10px;
        }
    </style>
</head>
<body>
<div class="game-container">
    <h1>WORDLE</h1>
    <div class="sub">猜单词 · 动态长度</div>
    
    <div class="word-length-info">
        📏 当前单词长度: <?php echo $wordLength; ?> 个字母
    </div>

    <div class="board">
        <?php for ($row = 0; $row < MAX_ATTEMPTS; $row++): ?>
            <div class="attempt-row">
                <?php
                $hasGuess = isset($attempts[$row]);
                $guessData = $hasGuess ? $attempts[$row] : null;
                $word = $hasGuess ? $guessData['word'] : '';
                $result = $hasGuess ? $guessData['result'] : [];
                ?>
                <?php for ($col = 0; $col < $wordLength; $col++): ?>
                    <?php
                    $letter = '';
                    $colorClass = 'empty';
                    if ($hasGuess && isset($word[$col])) {
                        $letter = strtoupper($word[$col]);
                        $colorClass = $result[$col] ?? 'gray';
                    }
                    ?>
                    <div class="tile <?php echo $colorClass; ?>">
                        <?php echo htmlspecialchars($letter); ?>
                    </div>
                <?php endfor; ?>
            </div>
        <?php endfor; ?>
    </div>

    <?php if ($message): ?>
        <div class="message <?php echo $win ? 'win-message' : ''; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if (!$game_over): ?>
        <div class="input-area">
            <form method="POST" action="" style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;">
                <input type="text" name="guess" maxlength="<?php echo $wordLength; ?>" autocomplete="off" placeholder="输入<?php echo $wordLength; ?>个字母的单词" required autofocus>
                <button type="submit">↵ 猜</button>
                <a href="?reset=1" style="text-decoration: none;"><button type="button" class="reset-btn">🔄 新游戏</button></a>
            </form>
        </div>
        <div class="info">
            剩余尝试次数: <?php echo $remaining; ?> / <?php echo MAX_ATTEMPTS; ?>
        </div>
        <div class="word-count">
            📚 词库单词数: <?php echo $totalWords; ?>
        </div>
    <?php else: ?>
        <div class="input-area" style="margin-top: 15px;">
            <a href="?reset=1"><button type="button">🔄 新的一局</button></a>
        </div>
        <div class="info">
            <?php if ($win): ?>
                🎉 太棒了！ 🎉
            <?php else: ?>
                正确答案是 <strong><?php echo htmlspecialchars($_SESSION['secret']); ?></strong>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
