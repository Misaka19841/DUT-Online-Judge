<?php
// ==================== 卡牌游戏 - 24轮12分制 ====================
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ==================== 1. 数据库配置 ====================
define('DB_HOST', UOJConfig::$data['database']['host']);
define('DB_USER', UOJConfig::$data['database']['username']);
define('DB_PASS', UOJConfig::$data['database']['password']);
define('DB_NAME', 'card_game_new');

try {
    $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// ==================== 2. 游戏配置 ====================
define('BENCH_SIZE', 9);
define('FIELD_SIZE', 5);
define('ROUND_REWARDS', [4, 4, 4, 6, 6, 6, 8, 8, 8, 10, 10, 10, 12, 12, 12, 14, 14, 14, 16, 16, 16, 18, 18, 18]);
define('WIN_SCORE', 12);
define('MOD', 998244353);

// ==================== 3. 工具函数 ====================
function fastPow($base, $exp, $mod) {
    $result = 1;
    $base = $base % $mod;
    while ($exp > 0) {
        if ($exp & 1) {
            $result = ($result * $base) % $mod;
        }
        $base = ($base * $base) % $mod;
        $exp >>= 1;
    }
    return $result;
}

function modInverse($a, $mod = MOD) {
    $a = $a % $mod;
    if ($a == 0) return 0;
    return fastPow($a, $mod - 2, $mod);
}

// ==================== 4. 卡牌类（用于战斗） ====================
class BattleCard {
    public $id;
    public $name;
    public $star;
    public $basePoints;
    public $points;
    public $cost;
    public $skill_name;
    public $skill_description;
    public $image_url;
    public $is_enemy = false;
    public $slot;
    public $instance_id;
    
    public function __construct($data, $star = 1, $is_enemy = false, $slot = null) {
        $this->id = $data['id'];
        $this->name = $data['name'];
        $this->star = $star;
        $this->basePoints = $data['base_points'];
        $this->points = $data['base_points'] * pow(2, $star - 1);
        $this->cost = $data['cost'];
        $this->skill_name = $data['skill_name'] ?? '';
        $this->skill_description = $data['skill_description'] ?? '';
        $this->image_url = $data['image_url'] ?? null;
        $this->is_enemy = $is_enemy;
        $this->slot = $slot;
        $this->instance_id = $data['instance_id'] ?? uniqid();
    }
    
    public function preBattleSkill($battleContext) {
        if ($this->name == '将军') {
            $battleContext->background = 'light';
            $battleContext->messages[] = ($this->is_enemy ? '敌方' : '我方') . '将军发动：天亮了';
        }
    }
    
    public function battleSkill($battleContext) {
        $points = $this->points;
        $originPoints = $points;
        
        if ($battleContext->background == 'light' && strpos($this->skill_name, '熬夜') !== false) {
            $new = floor($points / 2);
            $battleContext->messages[] = ($this->is_enemy ? '敌方' : '我方') . $this->name . '熬夜：' . $points . ' -> ' . $new;
            $points = $new;
        }
        
        if (strpos($this->skill_name, '神秘') !== false) {
            $multiplier = 0.7 + mt_rand()/mt_getrandmax() * 1.3;
            $new = floor($points * $multiplier);
            $battleContext->messages[] = ($this->is_enemy ? '敌方' : '我方') . $this->name . '神秘x' . round($multiplier, 2) . '：' . $points . ' -> ' . $new;
            $points = $new;
        }
        
        if ($battleContext->round >= 4 && strpos($this->skill_name, '老骥伏枥') !== false) {
            $new = $points * 2;
            $battleContext->messages[] = ($this->is_enemy ? '敌方' : '我方') . $this->name . '老骥伏枥：' . $points . ' -> ' . $new;
            $points = $new;
        }
        
        if (($battleContext->milkBuffCount > 0) && (strpos($this->name, '奶龙') !== false || strpos($this->skill_name, '奶龙') !== false)) {
            $factor = pow(2, $battleContext->milkBuffCount);
            $new = $points * $factor;
            $battleContext->messages[] = ($this->is_enemy ? '敌方' : '我方') . $this->name . '奶龙界王拳x' . $battleContext->milkBuffCount . '：' . $points . ' -> ' . $new;
            $points = $new;
        }
        
        if ($battleContext->catLadyCount > 0 && $this->isCat()) {
            $factor = pow(2, $battleContext->catLadyCount);
            $new = $points * $factor;
            $battleContext->messages[] = ($this->is_enemy ? '敌方' : '我方') . $this->name . '猫女士x' . $battleContext->catLadyCount . '：' . $points . ' -> ' . $new;
            $points = $new;
        }
        
        return $points;
    }
    
    public function afterBattleSkill($engine, $battleContext) {
        $messages = [];
        
        // 管道猫猫虫/叮咚鸡 - 合并效果：每阵营本回合只刷一张大祥
        if ($this->name == '管道猫猫虫' || $this->name == '叮咚鸡') {
            if ($this->is_enemy) {
                if (!$battleContext->spawnedDaxiangEnemy) {
                    $engine->addCardToEnemy('大祥', 120);
                    $battleContext->spawnedDaxiangEnemy = true;
                    $messages[] = '敌方' . $this->name . '刷出大祥(120费)';
                }
            } else {
                if (!$battleContext->spawnedDaxiangPlayer) {
                    $engine->addCardToPlayer('大祥', 120);
                    $battleContext->spawnedDaxiangPlayer = true;
                    $messages[] = $this->name . '刷出大祥(120费)';
                }
            }
        }
        
        // 神秘人 - 10%刷小祥
        if ($this->name == '神秘人' && mt_rand(1, 100) <= 10) {
            if ($this->is_enemy) {
                $engine->addCardToEnemy('小祥', 1);
                $messages[] = '敌方神秘人幸运发动，刷出小祥(1费)';
            } else {
                $engine->addCardToPlayer('小祥', 1);
                $messages[] = '神秘人幸运发动，刷出小祥(1费)';
            }
        }
        
        // 牢大 - 50%刷Super Big Cup
        if ($this->name == '牢大' && mt_rand(1, 100) <= 50) {
            if ($this->is_enemy) {
                $engine->addCardToEnemy('SuperBigCup', 1);
                $messages[] = '敌方牢大换大盏，刷出Super Big Cup';
            } else {
                $engine->addCardToPlayer('SuperBigCup', 1);
                $messages[] = '牢大换大盏，刷出Super Big Cup';
            }
        }
        
        // 辽宁算法皇帝效果已改为在战斗开始阶段临时生效
        
        // 点燃大海 - 成长性（记录双方场上最大卡面点数）
        if ($this->name == '点燃大海' && !$this->is_enemy) {
            if (!isset($engine->game['dianran_max_points'])) {
                $engine->game['dianran_max_points'] = $this->points;
            }
            $maxFace = 0;
            foreach ($battleContext->playerCards as $c) {
                if ($c) {
                    $maxFace = max($maxFace, $c->points);
                }
            }
            foreach ($battleContext->enemyCards as $c) {
                if ($c) {
                    $maxFace = max($maxFace, $c->points);
                }
            }
            $engine->game['dianran_max_points'] = max($engine->game['dianran_max_points'], $maxFace);
        }
        
        return $messages;
    }
    
    public function isCat() {
        return (strpos($this->skill_name, '猫') !== false || strpos($this->name, '猫') !== false);
    }
}

// ==================== 5. 战斗上下文 ====================
class BattleContext {
    public $round;
    public $background = 'dark';
    public $playerCards = [];
    public $enemyCards = [];
    public $playerScore = 0;
    public $enemyScore = 0;
    public $messages = [];
    public $hasCatLady = false;
    public $milkBuffCount = 0;
    public $catLadyCount = 0;
    public $spawnedDaxiangPlayer = false;
    public $spawnedDaxiangEnemy = false;
    public $stolenByLNRulerPlayer = false;
    public $stolenByLNRulerEnemy = false;
    
    public function __construct($round) {
        $this->round = $round;
    }
}

// ==================== 6. 游戏引擎 ====================
class GameEngine {
    private $db;
    public $game;
    
    public function __construct(&$gameState, $db) {
        $this->game = &$gameState;
        $this->db = $db;
    }
    
    public function getCardDataById($id) {
        $stmt = $this->db->prepare("SELECT id, cost, base_points, skill_name, skill_description, image_url FROM cards WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function init() {
        $this->game = [
            'gold' => 10,
            'round' => 1,
            'wins' => 0,
            'losses' => 0,
            'bench' => [],
            'field' => array_fill(0, FIELD_SIZE, null),
            'shop' => [],
            'inBattle' => false,
            'battleResult' => null,
            'battleEnemy' => null,
            'battleMessages' => [],
            'background' => 'dark',
            'session_id' => uniqid('game_', true),
            'message' => '',
            'gameOver' => false,
            'pendingSpawns' => [],
            'dianran_max_points' => 0
        ];
        
        $this->initDrawPool();
        $this->refreshShop();
    }
    
    private function initDrawPool() {
        $session_id = $this->game['session_id'];
        
        // 清空旧数据
        $stmt = $this->db->prepare("DELETE FROM user_cards WHERE session_id = ?");
        $stmt->execute([$session_id]);
        
        // 只插入第1轮的卡牌
        $stmt = $this->db->prepare("
            SELECT card_id, base_quantity, is_unique 
            FROM round_pool_config 
            WHERE round_number = 1
        ");
        $stmt->execute();
        $configs = $stmt->fetchAll();
        
        foreach ($configs as $config) {
            for ($i = 0; $i < $config['base_quantity']; $i++) {
                $stmt = $this->db->prepare("
                    INSERT INTO user_cards (session_id, card_id, star_level, is_unique, is_in_hand) 
                    VALUES (?, ?, 1, ?, 1)
                ");
                $stmt->execute([$session_id, $config['card_id'], $config['is_unique']]);
            }
        }
    }
    
    public function refreshShop($spawnedCards = []) {
        $shopCards = [];
        
        foreach ($spawnedCards as $card) {
            $shopCards[] = $card;
        }
        
        $needed = 5 - count($shopCards);
        if ($needed > 0) {
            $stmt = $this->db->prepare("
                SELECT uc.*, c.name, c.cost, c.base_points, c.skill_name, c.skill_description, c.image_url,
                       cs.points as current_points
                FROM user_cards uc
                JOIN cards c ON uc.card_id = c.id
                JOIN card_stars cs ON uc.card_id = cs.card_id AND uc.star_level = cs.star_level
                WHERE uc.session_id = ? AND uc.is_in_hand = 1
                ORDER BY RAND()
                LIMIT " . intval($needed) . "
            ");
            $stmt->execute([$this->game['session_id']]);
            $poolCards = $stmt->fetchAll();
            $shopCards = array_merge($shopCards, $poolCards);
        }
        
        $this->game['shop'] = array_slice($shopCards, 0, 5);
    }
    
    public function addCardToPlayer($cardName, $cost = null) {
        $stmt = $this->db->prepare("SELECT id, cost, base_points, skill_name, skill_description, image_url FROM cards WHERE name = ?");
        $stmt->execute([$cardName]);
        $cardData = $stmt->fetch();
        
        if (!$cardData) return false;
        
        $spawnedCard = [
            'id' => uniqid(),
            'card_id' => $cardData['id'],
            'name' => $cardName,
            'cost' => $cost ?? $cardData['cost'],
            'original_cost' => ($cost === 0) ? $cardData['cost'] : null,
            'star_level' => 1,
            'current_points' => $cardData['base_points'],
            'skill_name' => $cardData['skill_name'],
            'skill_description' => $cardData['skill_description'],
            'image_url' => $cardData['image_url'],
            'is_spawned' => true
        ];
        
        $this->game['pendingSpawns'][] = $spawnedCard;
        return true;
    }
    
    public function addCardToEnemy($cardName, $cost = null) {
        return true;
    }
    
    public function playerStealFromEnemy() {
        $stmt = $this->db->prepare("
            SELECT id, name, base_points, cost, skill_name, skill_description
            FROM cards 
            WHERE cost <= 3 AND name != '奶龙'
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->execute();
        $stolenCard = $stmt->fetch();
        
        if (!$stolenCard) return false;
        
        $emptySlot = -1;
        for ($i = 0; $i < FIELD_SIZE; $i++) {
            if ($this->game['field'][$i] === null) {
                $emptySlot = $i;
                break;
            }
        }
        
        $newCard = [
            'id' => $stolenCard['id'],
            'instance_id' => uniqid(),
            'name' => $stolenCard['name'],
            'star' => 1,
            'points' => $stolenCard['base_points'],
            'cost' => $stolenCard['cost'],
            'skill_name' => $stolenCard['skill_name'],
            'skill_description' => $stolenCard['skill_description'],
            'image_url' => null
        ];
        
        if ($emptySlot >= 0) {
            $this->game['field'][$emptySlot] = $newCard;
            return $stolenCard['name'] . '，放在空位';
        } else {
            $occupiedSlots = [];
            for ($i = 0; $i < FIELD_SIZE; $i++) {
                if ($this->game['field'][$i] !== null) {
                    $occupiedSlots[] = $i;
                }
            }
            if (!empty($occupiedSlots)) {
                $deleteSlot = $occupiedSlots[array_rand($occupiedSlots)];
                $deletedCard = $this->game['field'][$deleteSlot]['name'];
                $this->game['field'][$deleteSlot] = $newCard;
                return $stolenCard['name'] . '，删除了' . $deletedCard;
            }
        }
        return false;
    }
    
    public function enemyStealFromPlayer() {
        $myCards = [];
        for ($i = 0; $i < FIELD_SIZE; $i++) {
            if ($this->game['field'][$i] !== null) {
                $myCards[] = ['slot' => $i, 'card' => $this->game['field'][$i]];
            }
        }
        
        if (empty($myCards)) return false;
        
        $stolen = $myCards[array_rand($myCards)];
        $stolenCard = $stolen['card'];
        $stolenSlot = $stolen['slot'];
        
        $cardName = $stolenCard['name'];
        $this->game['field'][$stolenSlot] = null;
        
        return $cardName;
    }
    
    public function buyCard($shopIndex) {
        if (!isset($this->game['shop'][$shopIndex])) {
            return ['success' => false, 'msg' => '商店里没有这张牌'];
        }
        
        $card = $this->game['shop'][$shopIndex];
        
        if ($this->game['gold'] < $card['cost']) {
            return ['success' => false, 'msg' => '金币不足'];
        }
        
        if (count($this->game['bench']) >= BENCH_SIZE) {
            return ['success' => false, 'msg' => '等待区已满'];
        }
        
        $this->game['gold'] -= $card['cost'];
        array_splice($this->game['shop'], $shopIndex, 1);
        
        if (!isset($card['is_spawned']) || !$card['is_spawned']) {
            $stmt = $this->db->prepare("
                DELETE FROM user_cards 
                WHERE session_id = ? AND id = ? 
                LIMIT 1
            ");
            $stmt->execute([$this->game['session_id'], $card['id']]);
        }
        
        $newCard = [
            'id' => $card['card_id'],
            'instance_id' => $card['id'] ?? uniqid(),
            'name' => $card['name'],
            'star' => 1,
            'points' => $card['current_points'],
            'cost' => isset($card['original_cost']) && $card['original_cost'] !== null ? $card['original_cost'] : $card['cost'],
            'skill_name' => $card['skill_name'],
            'skill_description' => $card['skill_description'],
            'image_url' => $card['image_url']
        ];
        
        $this->game['bench'][] = $newCard;
        
        return ['success' => true];
    }
    
    public function sellCard($type, $index) {
        if ($type == 'bench' && isset($this->game['bench'][$index])) {
            $card = $this->game['bench'][$index];
            if ($card['name'] == '三只复健小蒟蒻') {
                $sellPrice = 20;
            } else {
                $sellPrice = $card['cost'] * $card['star'];
            }
            array_splice($this->game['bench'], $index, 1);
            $this->game['gold'] += $sellPrice;
            return ['success' => true];
        } elseif ($type == 'field' && isset($this->game['field'][$index]) && $this->game['field'][$index] !== null) {
            $card = $this->game['field'][$index];
            if ($card['name'] == '三只复健小蒟蒻') {
                $sellPrice = 20;
            } else {
                $sellPrice = $card['cost'] * $card['star'];
            }
            $this->game['field'][$index] = null;
            $this->game['gold'] += $sellPrice;
            $this->checkGeneralSkill();
            return ['success' => true];
        }
        return ['success' => false, 'msg' => '卡牌不存在'];
    }
    
    public function moveCard($fromType, $fromIndex, $toType, $toIndex = null) {
        if ($fromType == 'bench') {
            if (!isset($this->game['bench'][$fromIndex])) return false;
            $card = $this->game['bench'][$fromIndex];
            
            if ($toType == 'bench' && $toIndex !== null) {
                if (!isset($this->game['bench'][$toIndex])) return false;
                $this->game['bench'][$fromIndex] = $this->game['bench'][$toIndex];
                $this->game['bench'][$toIndex] = $card;
                return true;
            } elseif ($toType == 'field' && $toIndex !== null && $toIndex >= 0 && $toIndex < FIELD_SIZE) {
                if ($this->game['field'][$toIndex] !== null) return false;
                
                $isUnique = $this->isUniqueCard($card['id']);
                
                if (!$isUnique) {
                    foreach ($this->game['field'] as $fieldCard) {
                        if ($fieldCard && $fieldCard['id'] == $card['id']) {
                            return false;
                        }
                    }
                }
                
                $this->game['field'][$toIndex] = $card;
                array_splice($this->game['bench'], $fromIndex, 1);
                $this->checkGeneralSkill();
                return true;
            }
        } elseif ($fromType == 'field') {
            if (!isset($this->game['field'][$fromIndex]) || $this->game['field'][$fromIndex] === null) return false;
            $card = $this->game['field'][$fromIndex];
            
            if ($toType == 'bench') {
                if (count($this->game['bench']) >= BENCH_SIZE) return false;
                $this->game['bench'][] = $card;
                $this->game['field'][$fromIndex] = null;
                $this->checkGeneralSkill();
                return true;
            } elseif ($toType == 'field' && $toIndex !== null && $toIndex >= 0 && $toIndex < FIELD_SIZE) {
                if ($this->game['field'][$toIndex] !== null) {
                    $temp = $this->game['field'][$toIndex];
                    $this->game['field'][$toIndex] = $card;
                    $this->game['field'][$fromIndex] = $temp;
                } else {
                    $this->game['field'][$toIndex] = $card;
                    $this->game['field'][$fromIndex] = null;
                }
                return true;
            }
        }
        return false;
    }
    
    private function isUniqueCard($cardId) {
        static $uniqueIds = null;
        if ($uniqueIds === null) {
            $stmt = $this->db->query("SELECT id FROM cards WHERE name IN ('什么鸭', '德德', '鸭么什')");
            $uniqueIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        return in_array($cardId, $uniqueIds);
    }
    
    public function checkGeneralSkill() {
        $hasGeneral = false;
        foreach ($this->game['field'] as $card) {
            if ($card && $card['name'] == '将军') {
                $hasGeneral = true;
                break;
            }
        }
        $this->game['background'] = $hasGeneral ? 'light' : 'dark';
    }
    
    public function getAllSynthesisFormulas() {
        $formulas = [];
        
        $stmt = $this->db->query("
            SELECT f.*, c.name as result_name, c.base_points, c.id as result_id, c.cost
            FROM synthesis_formulas f
            JOIN cards c ON f.result_card_id = c.id
        ");
        $baseFormulas = $stmt->fetchAll();
        
        foreach ($baseFormulas as $baseFormula) {
            $stmt = $this->db->prepare("
                SELECT m.*, c.name 
                FROM synthesis_materials m
                JOIN cards c ON m.required_card_id = c.id
                WHERE m.formula_id = ?
            ");
            $stmt->execute([$baseFormula['id']]);
            $baseMaterials = $stmt->fetchAll();
            
            for ($star = 1; $star <= 3; $star++) {
                $hasAll = true;
                $materialsDetail = [];
                
                foreach ($baseMaterials as $material) {
                    $count = 0;
                    foreach ($this->game['bench'] as $card) {
                        if ($card && $card['id'] == $material['required_card_id'] && $card['star'] == $star) {
                            $count++;
                        }
                    }
                    $materialsDetail[] = [
                        'name' => $material['name'],
                        'required' => $material['quantity'],
                        'has' => $count
                    ];
                    if ($count < $material['quantity']) {
                        $hasAll = false;
                    }
                }
                
                if ($hasAll) {
                    $formulas[] = [
                        'type' => 'special',
                        'id' => $baseFormula['id'],
                        'star' => $star,
                        'name' => $baseFormula['formula_name'] . '★' . $star,
                        'result' => $baseFormula['result_name'] . '★' . $star,
                        'result_id' => $baseFormula['result_id'],
                        'points' => $baseFormula['base_points'] * pow(2, $star - 1),
                        'cost' => $baseFormula['cost'],
                        'materials' => $materialsDetail
                    ];
                }
            }
        }
        
        $cardCounts = [];
        foreach ($this->game['bench'] as $card) {
            if ($card) {
                $key = $card['id'] . '_' . $card['star'];
                if (!isset($cardCounts[$key])) {
                    $cardCounts[$key] = ['card' => $card, 'count' => 0];
                }
                $cardCounts[$key]['count']++;
            }
        }
        
        foreach ($cardCounts as $key => $info) {
            if ($info['count'] >= 3 && $info['card']['star'] < 4) {
                $card = $info['card'];
                $basePoints = $card['points'] / pow(2, $card['star'] - 1);
                $formulas[] = [
                    'type' => 'star',
                    'card_id' => $card['id'],
                    'star' => $card['star'],
                    'name' => $card['name'] . '升星',
                    'result' => $card['name'] . '★' . ($card['star'] + 1),
                    'points' => $basePoints * pow(2, $card['star']),
                    'cost' => $card['cost'],
                    'materials' => [
                        ['name' => $card['name'] . '★' . $card['star'], 'required' => 3, 'has' => $info['count']]
                    ]
                ];
            }
        }
        
        return $formulas;
    }
    
    public function doSynthesis($formulaId, $star) {
        $stmt = $this->db->prepare("
            SELECT f.*, c.id as result_id, c.name as result_name, c.base_points, c.cost, c.skill_name as result_skill_name, c.skill_description as result_skill_description, c.image_url as result_image_url
            FROM synthesis_formulas f
            JOIN cards c ON f.result_card_id = c.id
            WHERE f.id = ?
        ");
        $stmt->execute([$formulaId]);
        $formula = $stmt->fetch();
        
        if (!$formula) {
            return ['success' => false, 'msg' => '合成公式不存在'];
        }
        
        $stmt = $this->db->prepare("
            SELECT m.*, c.name 
            FROM synthesis_materials m
            JOIN cards c ON m.required_card_id = c.id
            WHERE m.formula_id = ?
        ");
        $stmt->execute([$formulaId]);
        $materials = $stmt->fetchAll();
        
        $hasShenmi = false;
        foreach ($materials as $material) {
            if ($material['name'] == '神秘人') {
                $hasShenmi = true;
                break;
            }
        }
        
        if ($hasShenmi) {
            $allShenmi = [];
            foreach ($this->game['bench'] as $index => $card) {
                if ($card && $card['name'] == '神秘人' && $card['star'] == $star) {
                    $allShenmi[] = ['index' => $index, 'card' => $card];
                }
            }
            
            if (!empty($allShenmi)) {
                $selectedShenmi = $allShenmi[array_rand($allShenmi)];
                
                if (mt_rand(1, 100) <= 90) {
                    array_splice($this->game['bench'], $selectedShenmi['index'], 1);
                    $this->game['last_synthesis_message'] = '上次合成：神秘人失踪了，合成' . $formula['result_name'] . '失败';
                    return ['success' => false, 'msg' => '神秘人发动观测禁止，合成失败！神秘人消失了'];
                }
            }
        }
        
        foreach ($materials as $material) {
            $removed = 0;
            foreach ($this->game['bench'] as $index => $card) {
                if ($card && $card['id'] == $material['required_card_id'] && $card['star'] == $star) {
                    array_splice($this->game['bench'], $index, 1);
                    $removed++;
                    if ($removed >= $material['quantity']) break;
                }
            }
            if ($removed < $material['quantity']) {
                return ['success' => false, 'msg' => '材料不足'];
            }
        }
        
        $newCard = [
            'id' => $formula['result_id'],
            'instance_id' => uniqid(),
            'name' => $formula['result_name'],
            'star' => $star,
            'points' => $formula['base_points'] * pow(2, $star - 1),
            'cost' => $formula['cost'],
            'skill_name' => $formula['result_skill_name'],
            'skill_description' => $formula['result_skill_description'],
            'image_url' => $formula['result_image_url']
        ];
        
        $this->game['bench'][] = $newCard;
        $this->game['last_synthesis_message'] = '上次合成：成功合成' . $newCard['name'] . '★' . $star;
        
        return ['success' => true];
    }
    
    public function doStarSynthesis($cardId, $star) {
        $cards = [];
        foreach ($this->game['bench'] as $index => $card) {
            if ($card && $card['id'] == $cardId && $card['star'] == $star) {
                $cards[] = ['index' => $index, 'card' => $card];
            }
        }
        
        if (count($cards) < 3) {
            return ['success' => false, 'msg' => '需要3张相同的卡'];
        }
        
        $selectedThree = array_slice($cards, 0, 3);
        
        $hasShenmi = false;
        $selectedShenmiItems = [];
        foreach ($selectedThree as $item) {
            if ($item['card']['name'] == '神秘人') {
                $hasShenmi = true;
                $selectedShenmiItems[] = $item;
            }
        }
        
        if ($hasShenmi) {
            $selectedShenmi = $selectedShenmiItems[array_rand($selectedShenmiItems)];
            if (mt_rand(1, 100) <= 90) {
                // 移除参与材料中的神秘人并判定失败（不再继续合成）
                array_splice($this->game['bench'], $selectedShenmi['index'], 1);
                $this->game['last_synthesis_message'] = '上次合成：神秘人失踪了，合成' . $selectedThree[0]['card']['name'] . '失败';
                return ['success' => false, 'msg' => '神秘人发动观测禁止，升星失败！神秘人消失了'];
            }
        }
        
        $indices = array_column($selectedThree, 'index');
        rsort($indices);
        foreach ($indices as $index) {
            array_splice($this->game['bench'], $index, 1);
        }
        
        $baseCard = $selectedThree[0]['card'];
        $newStar = $star + 1;
        $basePoints = $baseCard['points'] / pow(2, $baseCard['star'] - 1);
        
        $newCard = [
            'id' => $baseCard['id'],
            'instance_id' => uniqid(),
            'name' => $baseCard['name'],
            'star' => $newStar,
            'points' => $basePoints * pow(2, $newStar - 1),
            'cost' => $baseCard['cost'],
            'skill_name' => $baseCard['skill_name'],
            'skill_description' => $baseCard['skill_description'],
            'image_url' => $baseCard['image_url']
        ];
        
        $this->game['bench'][] = $newCard;
        $this->game['last_synthesis_message'] = '上次合成：成功合成' . $newCard['name'] . '★' . $newStar;
        
        return ['success' => true];
    }
    
    // 处理所有卡牌的技能（包括等待区和上场区）
    public function processAllCardSkills($battleContext) {
        $allCards = array_merge($this->game['bench'], $this->game['field']);
        $messages = [];
        
        // 什么鸭技能
        foreach ($allCards as $card) {
            if ($card && $card['name'] == '什么鸭') {
                // 免费送1张随机牌（仅3~5费，显示保持原费用颜色，购买免费）
                for ($i = 0; $i < 1; $i++) {
                    $stmt = $this->db->prepare("SELECT name FROM cards WHERE cost BETWEEN 3 AND 5 ORDER BY RAND() LIMIT 1");
                    $stmt->execute();
                    $row = $stmt->fetch();
                    if ($row && isset($row['name'])) {
                        $randomCard = $row['name'];
                        $this->addCardToPlayer($randomCard, 0);
                        $messages[] = '什么鸭免费送你一张' . $randomCard;
                    }
                }
                
                // 随机删一张牌（包括自己）
                $allCardsList = [];
                foreach ($this->game['bench'] as $index => $c) {
                    if ($c) {
                        $allCardsList[] = ['type' => 'bench', 'index' => $index, 'card' => $c];
                    }
                }
                foreach ($this->game['field'] as $index => $c) {
                    if ($c) {
                        $allCardsList[] = ['type' => 'field', 'index' => $index, 'card' => $c];
                    }
                }
                
                if (!empty($allCardsList)) {
                    $toDelete = $allCardsList[array_rand($allCardsList)];
                    $deletedCardName = $toDelete['card']['name'];
                    
                    if ($toDelete['type'] == 'bench') {
                        array_splice($this->game['bench'], $toDelete['index'], 1);
                    } else {
                        $this->game['field'][$toDelete['index']] = null;
                    }
                    
                    // 如果删的是什么鸭自己
                    if ($deletedCardName == '什么鸭') {
                        $this->game['bench'] = [];
                        $this->game['field'] = array_fill(0, FIELD_SIZE, null);
                        $messages[] = '什么鸭让自己退队，所有牌都消失了';
                        break;
                    } else {
                        $messages[] = '什么鸭随机删除了' . $deletedCardName;
                    }
                }
            }
        }
        
        // 德德技能
        foreach ($allCards as $card) {
            if ($card && $card['name'] == '德德') {
                $this->game['gold'] += 2;
                $messages[] = '德德给你加2块钱';
                
                if ($this->game['gold'] > 30) {
                    $this->game['gold'] = 0;
                    $messages[] = '德德把你的钱清零了';
                }
                break;
            }
        }
        
        // 奶龙pair - 每回合免费赠送一只奶龙（1费划掉变0费）
        // 规则：只有我方此次有效参战队伍（场上，含偷取等临时卡）有奶龙pair时，才赠送
        foreach ($battleContext->playerCards as $card) {
            if ($card && $card->name == '奶龙pair') {
                $this->addCardToPlayer('奶龙', 0);
                $messages[] = '奶龙pair赠送一只奶龙(免费)';
                break;
            }
        }
        
        // 鸭么什技能
        foreach ($allCards as $card) {
            if ($card && $card['name'] == '鸭么什') {
                // 找所有1费卡（包括自己）
                $oneCostCards = [];
                foreach ($this->game['bench'] as $index => $c) {
                    if ($c && $c['cost'] == 1) {
                        $oneCostCards[] = ['type' => 'bench', 'index' => $index, 'card' => $c];
                    }
                }
                foreach ($this->game['field'] as $index => $c) {
                    if ($c && $c['cost'] == 1) {
                        $oneCostCards[] = ['type' => 'field', 'index' => $index, 'card' => $c];
                    }
                }
                
                if (!empty($oneCostCards)) {
                    // 随机卖掉一张1费卡，获得1金币
                    $toDelete = $oneCostCards[array_rand($oneCostCards)];
                    $deletedCardName = $toDelete['card']['name'];
                    
                    if ($toDelete['type'] == 'bench') {
                        array_splice($this->game['bench'], $toDelete['index'], 1);
                    } else {
                        $this->game['field'][$toDelete['index']] = null;
                    }
                    
                    $this->game['gold'] += 1;
                    $messages[] = '鸭么什卖掉了' . $deletedCardName . '，获得1块钱';
                }
            }
        }
        
        return $messages;
    }
    
    public function startBattle() {
        $fieldCards = array_filter($this->game['field'], function($c) {
            return $c !== null;
        });
        if (count($fieldCards) == 0) {
            return ['success' => false, 'msg' => '请至少上阵1张卡牌'];
        }
        
        $round = $this->game['round'];
        
        $rank_id = 1;
        $stmt = $this->db->prepare("
            SELECT * FROM ai_opponents 
            WHERE rank_id = ? AND round_number = ?
        ");
        $stmt->execute([$rank_id, $round]);
        $opponent = $stmt->fetch();
        
        if (!$opponent) {
            return ['success' => false, 'msg' => '找不到对手'];
        }
        
        $battleContext = new BattleContext($round);
        
        $playerCards = [];
        foreach ($this->game['field'] as $slot => $card) {
            if ($card) {
                $cardData = [
                    'id' => $card['id'],
                    'name' => $card['name'],
                    'base_points' => $card['points'] / pow(2, $card['star'] - 1),
                    'cost' => $card['cost'],
                    'skill_name' => $card['skill_name'],
                    'skill_description' => $card['skill_description'],
                    'image_url' => $card['image_url'],
                    'instance_id' => $card['instance_id']
                ];
                $battleCard = new BattleCard($cardData, $card['star'], false, $slot);
                $playerCards[] = $battleCard;
            }
        }
        $battleContext->playerCards = $playerCards;
        
        $enemyCards = [];
        $enemyDisplay = [];
        for ($i = 1; $i <= 5; $i++) {
            $cardId = $opponent["slot_{$i}_card_id"];
            $starCol = "card_pos{$i}_star";
            $slotStar = isset($opponent[$starCol]) ? intval($opponent[$starCol]) : 1;
            $slotStar = max(1, $slotStar);
            if ($cardId) {
                $stmt = $this->db->prepare("
                    SELECT * FROM cards WHERE id = ?
                ");
                $stmt->execute([$cardId]);
                $cardData = $stmt->fetch();
                
                $battleCard = new BattleCard($cardData, $slotStar, true, $i-1);
                $enemyCards[] = $battleCard;
                $enemyDisplay[] = [
                    'name' => $cardData['name'],
                    'points' => $cardData['base_points'] * pow(2, $slotStar - 1),
                    'star' => $slotStar,
                    'skill_name' => $cardData['skill_name'],
                    'skill_description' => $cardData['skill_description'],
                    'image_url' => $cardData['image_url']
                ];
            } else {
                $enemyDisplay[] = null;
            }
        }
        $battleContext->enemyCards = $enemyCards;
        
        // 辽宁算法皇帝：本轮最先触发临时偷牌（双方对称），仅影响本轮的 playerCards/enemyCards 与弹框展示
        // 我方偷敌方
        $playerHasLNRuler = false;
        foreach ($playerCards as $pc) { if ($pc->name == '辽宁算法皇帝') { $playerHasLNRuler = true; break; } }
        if ($playerHasLNRuler && !$battleContext->stolenByLNRulerPlayer) {
            $eligibleIndices = [];
            foreach ($enemyCards as $idx => $ec) { if ($ec->name !== '辽宁算法皇帝') $eligibleIndices[] = $idx; }
            if (count($eligibleIndices) > 0) {
                $stealIndex = $eligibleIndices[array_rand($eligibleIndices)];
                $stolen = $enemyCards[$stealIndex];
                array_splice($enemyCards, $stealIndex, 1);
            $playerEmptySlots = [];
            for ($i = 0; $i < FIELD_SIZE; $i++) { if ($this->game['field'][$i] === null) $playerEmptySlots[] = $i; }
            if (!empty($playerEmptySlots)) {
                $stolen->is_enemy = false;
                $stolen->slot = $playerEmptySlots[0];
                $playerCards[] = $stolen;
                $battleContext->messages[] = '辽宁算法皇帝偷来了' . $stolen->name . '，放在空位';
            } else {
                $replaceIndex = array_rand($playerCards);
                $replacedName = $playerCards[$replaceIndex]->name;
                $stolen->is_enemy = false;
                $stolen->slot = $playerCards[$replaceIndex]->slot;
                $playerCards[$replaceIndex] = $stolen;
                $battleContext->messages[] = '辽宁算法皇帝偷来了' . $stolen->name . '，替换了' . $replacedName;
            }
            $battleContext->stolenByLNRulerPlayer = true;
            }
        }
        // 敌方偷我方
        $enemyHasLNRuler = false;
        foreach ($enemyCards as $ec) { if ($ec->name == '辽宁算法皇帝') { $enemyHasLNRuler = true; break; } }
        if ($enemyHasLNRuler && !$battleContext->stolenByLNRulerEnemy) {
            $eligibleIndices = [];
            foreach ($playerCards as $idx => $pc) { if ($pc->name !== '辽宁算法皇帝') $eligibleIndices[] = $idx; }
            if (count($eligibleIndices) > 0) {
                $stealIndex = $eligibleIndices[array_rand($eligibleIndices)];
                $stolen = $playerCards[$stealIndex];
                array_splice($playerCards, $stealIndex, 1);
            $enemyUsedSlots = [];
            for ($i = 0; $i < FIELD_SIZE; $i++) { $enemyUsedSlots[$i] = false; }
            foreach ($enemyCards as $ec) { if ($ec->slot !== null) $enemyUsedSlots[$ec->slot] = true; }
            $enemyEmptySlots = [];
            for ($i = 0; $i < FIELD_SIZE; $i++) { if (!$enemyUsedSlots[$i]) $enemyEmptySlots[] = $i; }
            if (!empty($enemyEmptySlots)) {
                $stolen->is_enemy = true;
                $stolen->slot = $enemyEmptySlots[0];
                $enemyCards[] = $stolen;
                $battleContext->messages[] = '敌方辽宁算法皇帝偷走了我方' . $stolen->name . '，放在其空位';
            } else {
                $replaceIndex = array_rand($enemyCards);
                $replacedName = $enemyCards[$replaceIndex]->name;
                $stolen->is_enemy = true;
                $stolen->slot = $enemyCards[$replaceIndex]->slot;
                $enemyCards[$replaceIndex] = $stolen;
                $battleContext->messages[] = '敌方辽宁算法皇帝偷走了我方' . $stolen->name . '，并替换了其' . $replacedName;
            }
            $battleContext->stolenByLNRulerEnemy = true;
            }
        }
        
        // 更新战斗用卡组与弹框展示
        $battleContext->playerCards = $playerCards;
        $battleContext->enemyCards = $enemyCards;
        
        $playerDisplay = array_fill(0, FIELD_SIZE, null);
        foreach ($playerCards as $pc) {
            if ($pc->slot !== null) {
                $playerDisplay[$pc->slot] = [
                    'name' => $pc->name,
                    'points' => $pc->points,
                    'star' => $pc->star,
                    'skill_name' => $pc->skill_name,
                    'skill_description' => $pc->skill_description,
                    'image_url' => $pc->image_url
                ];
            }
        }
        $enemyDisplay = array_fill(0, FIELD_SIZE, null);
        foreach ($enemyCards as $ec) {
            if ($ec->slot !== null) {
                $enemyDisplay[$ec->slot] = [
                    'name' => $ec->name,
                    'points' => $ec->points,
                    'star' => $ec->star,
                    'skill_name' => $ec->skill_name,
                    'skill_description' => $ec->skill_description,
                    'image_url' => $ec->image_url
                ];
            }
        }
        $this->game['battlePlayer'] = $playerDisplay;
        $this->game['battleEnemy'] = $enemyDisplay;
        
        // 统计本回合场上的奶龙界王拳与猫女士来源次数（含技能字样）
        $battleContext->milkBuffCount = 0;
        $battleContext->catLadyCount = 0;
        foreach (array_merge($playerCards, $enemyCards) as $bc) {
            if ($bc && strpos($bc->skill_name, '奶龙界王拳') !== false) {
                $battleContext->milkBuffCount++;
            }
            if ($bc && (strpos($bc->skill_name, '猫女士') !== false || strpos($bc->name, '猫女士') !== false)) {
                $battleContext->catLadyCount++;
                $battleContext->hasCatLady = true;
            }
        }
        
        foreach ($playerCards as $card) {
            $card->preBattleSkill($battleContext);
        }
        foreach ($enemyCards as $card) {
            $card->preBattleSkill($battleContext);
        }
        
        $playerScores = [];
        $enemyScores = [];
        
        foreach ($playerCards as $card) {
            $playerScores[] = $card->battleSkill($battleContext);
        }
        
        foreach ($enemyCards as $card) {
            $enemyScores[] = $card->battleSkill($battleContext);
        }
        
        // 猫女士翻倍效果已在 battleSkill 中按可叠加次数计算
        
        $playerScore = array_sum($playerScores);
        $enemyScore = array_sum($enemyScores);
        
        $hasPlayerNiyuan = false;
        $hasEnemyNiyuan = false;
        
        foreach ($playerCards as $card) {
            if ($card->name == '逆元') {
                $hasPlayerNiyuan = true;
                break;
            }
        }
        foreach ($enemyCards as $card) {
            if ($card->name == '逆元') {
                $hasEnemyNiyuan = true;
                break;
            }
        }
        
        if ($hasPlayerNiyuan) {
            $playerScore = modInverse($playerScore, MOD);
            $battleContext->messages[] = '我方逆元发动：得分变为模逆元';
        }
        if ($hasEnemyNiyuan) {
            $enemyScore = modInverse($enemyScore, MOD);
            $battleContext->messages[] = '敌方逆元发动：得分变为模逆元';
        }
        
        $battleContext->playerScore = $playerScore;
        $battleContext->enemyScore = $enemyScore;
        
        $win = $playerScore > $enemyScore;
        
        $rewardIndex = min($round - 1, count(ROUND_REWARDS) - 1);
        if ($win) {
            $bonus = ROUND_REWARDS[$rewardIndex];
            $this->game['wins']++;
        } else {
            $bonus = floor(ROUND_REWARDS[$rewardIndex] / 2);
            $this->game['losses']++;
        }
        
        foreach ($playerCards as $card) {
            if ($card->name == '吴爷爷') {
                $bonus++;
                $battleContext->messages[] = '吴爷爷给你加1块钱';
            }
        }
        
        $this->game['gold'] += $bonus;
        
        foreach ($playerCards as $card) {
            if ($card->name == '猫猫虫') {
                $oldGold = $this->game['gold'];
                $this->game['gold'] = floor($this->game['gold'] * (0.5 + mt_rand()/mt_getrandmax() * 1.0));
                $battleContext->messages[] = '猫猫虫风险投资：' . $oldGold . ' -> ' . $this->game['gold'];
                break;
            }
        }
        
        $this->game['pendingSpawns'] = [];
        
        // 处理战斗中的技能（场上）
        foreach ($playerCards as $card) {
            $msgs = $card->afterBattleSkill($this, $battleContext);
            foreach ($msgs as $msg) {
                $battleContext->messages[] = $msg;
            }
        }
        
        foreach ($enemyCards as $card) {
            $msgs = $card->afterBattleSkill($this, $battleContext);
            foreach ($msgs as $msg) {
                $battleContext->messages[] = $msg;
            }
        }
        
        // 处理所有卡牌的技能（包括等待区）
        $skillMessages = $this->processAllCardSkills($battleContext);
        foreach ($skillMessages as $msg) {
            $battleContext->messages[] = $msg;
        }
        
        // 点燃大海成长性
        foreach ($this->game['bench'] as &$card) {
            if ($card && $card['name'] == '点燃大海') {
                $newPoints = max($card['points'], $this->game['dianran_max_points']);
                if ($newPoints > $card['points']) {
                    $card['points'] = $newPoints;
                    $battleContext->messages[] = '点燃大海成长了，点数变为' . $newPoints;
                }
            }
        }
        unset($card);
        
        foreach ($this->game['field'] as &$card) {
            if ($card && $card['name'] == '点燃大海') {
                $newPoints = max($card['points'], $this->game['dianran_max_points']);
                if ($newPoints > $card['points']) {
                    $card['points'] = $newPoints;
                    $battleContext->messages[] = '点燃大海成长了，点数变为' . $newPoints;
                }
            }
        }
        unset($card);
        
        
        
        $gameOver = false;
        if ($this->game['wins'] >= WIN_SCORE) {
            $gameOver = true;
            $result = 'win';
        } elseif ($this->game['losses'] >= WIN_SCORE) {
            $gameOver = true;
            $result = 'lose';
        }
        
        if (!$gameOver) {
            $this->game['round']++;
            $this->nextRoundPool();
            $this->refreshShop($this->game['pendingSpawns']);
        }
        
        $this->game['battleResult'] = [
            'win' => $win,
            'playerScore' => $playerScore,
            'enemyScore' => $enemyScore,
            'bonus' => $bonus,
            'gameOver' => $gameOver,
            'result' => $result ?? null,
            'messages' => $battleContext->messages
        ];
        
        $this->game['gameOver'] = $gameOver;
        $this->game['battleMessages'] = $battleContext->messages;
        $this->game['inBattle'] = true;
        $this->game['background'] = $battleContext->background;
        
        return ['success' => true];
    }
    
    private function nextRoundPool() {
        $session_id = $this->game['session_id'];
        $round = $this->game['round'];
        
        // 获取本轮要新增的卡牌配置
        $stmt = $this->db->prepare("
            SELECT card_id, base_quantity, is_unique 
            FROM round_pool_config 
            WHERE round_number = ?
        ");
        $stmt->execute([$round]);
        $configs = $stmt->fetchAll();
        
        // 添加本轮新卡（累加，不删除旧的）
        foreach ($configs as $config) {
            for ($i = 0; $i < $config['base_quantity']; $i++) {
                $stmt = $this->db->prepare("
                    INSERT INTO user_cards (session_id, card_id, star_level, is_unique, is_in_hand) 
                    VALUES (?, ?, 1, ?, 1)
                ");
                $stmt->execute([$session_id, $config['card_id'], $config['is_unique']]);
            }
        }
    }
    
    public function getPoolCount() {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM user_cards uc
            WHERE uc.session_id = ? AND uc.is_in_hand = 1
        ");
        $stmt->execute([$this->game['session_id']]);
        return $stmt->fetchColumn();
    }
    
    public function getState() {
        return $this->game;
    }
    
    public function resetGame() {
        session_destroy();
        return true;
    }
}

// ==================== 7. 初始化游戏 ====================
if (!isset($_SESSION['game']) || empty($_SESSION['game'])) {
    $gameState = null;
    $engine = new GameEngine($gameState, $db);
    $engine->init();
    $_SESSION['game'] = $engine->getState();
} else {
    $engine = new GameEngine($_SESSION['game'], $db);
}

// ==================== 8. API处理 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $engine = new GameEngine($_SESSION['game'], $db);
    
    switch ($_POST['action']) {
        case 'refresh_shop':
            if ($_SESSION['game']['gold'] >= 2) {
                $_SESSION['game']['gold'] -= 2;
                $engine->refreshShop();
                $_SESSION['game'] = $engine->getState();
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'msg' => '金币不足']);
            }
            break;
            
        case 'buy':
            $result = $engine->buyCard(intval($_POST['index']));
            $_SESSION['game'] = $engine->getState();
            echo json_encode($result);
            break;
            
        case 'sell':
            $result = $engine->sellCard($_POST['type'], intval($_POST['index']));
            $_SESSION['game'] = $engine->getState();
            echo json_encode($result);
            break;
            
        case 'move':
            $result = $engine->moveCard(
                $_POST['from_type'],
                intval($_POST['from_index']),
                $_POST['to_type'],
                isset($_POST['to_index']) ? intval($_POST['to_index']) : null
            );
            $_SESSION['game'] = $engine->getState();
            echo json_encode(['success' => $result]);
            break;
            
        case 'battle':
            $result = $engine->startBattle();
            $_SESSION['game'] = $engine->getState();
            echo json_encode($result);
            break;
            
        case 'end_battle':
            $engine->game['inBattle'] = false;
            $engine->game['battleResult'] = null;
            $engine->game['battleEnemy'] = null;
            $engine->game['battleMessages'] = [];
            $engine->checkGeneralSkill();
            
            if ($engine->game['gameOver']) {
                session_destroy();
                echo json_encode(['success' => true, 'reset' => true]);
            } else {
                $_SESSION['game'] = $engine->getState();
                echo json_encode(['success' => true]);
            }
            break;
            
        case 'synthesis':
            $result = $engine->doSynthesis(intval($_POST['formula_id']), intval($_POST['star']));
            if (!$result['success'] && strpos($result['msg'], '神秘人发动观测禁止') !== false) {
                $_SESSION['game'] = $engine->getState();
                echo json_encode(['success' => true]);
            } else {
                $_SESSION['game'] = $engine->getState();
                echo json_encode($result);
            }
            break;
            
        case 'star_synthesis':
            $result = $engine->doStarSynthesis(intval($_POST['card_id']), intval($_POST['star']));
            if (!$result['success'] && strpos($result['msg'], '神秘人发动观测禁止') !== false) {
                $_SESSION['game'] = $engine->getState();
                echo json_encode(['success' => true]);
            } else {
                $_SESSION['game'] = $engine->getState();
                echo json_encode($result);
            }
            break;
            
        case 'reset':
            session_destroy();
            echo json_encode(['success' => true]);
            break;
    }
    exit;
}

$synthesisFormulas = $engine->getAllSynthesisFormulas();
$game = $_SESSION['game'];
$poolCount = $engine->getPoolCount();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DUT-ACM战争</title>
    <link rel="icon" href="/pictures/UOJ_small.png">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            background: <?php echo $game['background'] == 'light' ? '#f0f0f0' : '#1a1a1a'; ?>;
            color: <?php echo $game['background'] == 'light' ? '#333' : '#fff'; ?>;
            font-family: 'Microsoft YaHei', sans-serif;
            padding: 20px;
            transition: background-color 0.3s, color 0.3s;
            min-width: 1200px;
        }
        
        .cost-1 { border: 3px solid #808080 !important; }
        .cost-2 { border: 3px solid #90ee90 !important; }
        .cost-3 { border: 3px solid #4169e1 !important; }
        .cost-4 { border: 3px solid #9370db !important; }
        .cost-5 { border: 3px solid #ffd700 !important; }
        .cost-special { border: 3px solid #ff4444 !important; }
        
        .game-container {
            display: flex;
            gap: 30px;
            max-width: 1600px;
            margin: 0 auto;
        }
        
        .left-panel { flex: 1.8; }
        .right-panel { flex: 1.3; }
        
        .stats-bar {
            display: flex;
            gap: 30px;
            background: <?php echo $game['background'] == 'light' ? '#fff' : '#2a2a2a'; ?>;
            border: 2px solid #4a4a4a;
            border-radius: 8px;
            padding: 15px 25px;
            margin-bottom: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .gold {
            color: gold;
            font-size: 22px;
            font-weight: bold;
        }
        
        .board, .bench-area, .shop, .battle-result, .enemy-team, .synthesis-area {
            background: <?php echo $game['background'] == 'light' ? '#fff' : '#2a2a2a'; ?>;
            border: 2px solid #4a4a4a;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .field-grid, .bench-slots, .enemy-grid, .synthesis-grid {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 10px;
            min-height: 240px;
        }
        
        .slot {
            width: 120px;
            background: <?php echo $game['background'] == 'light' ? '#f5f5f5' : '#333'; ?>;
            border: 2px dashed #555;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            transition: all 0.2s;
            position: relative;
            padding: 12px 8px;
            min-height: 220px;
        }
        /* 左侧上场区与等待区的卡片稍微变宽一点点 */
        .field-grid .slot,
        .bench-slots .slot {
            width: 129px;
        }
        
        .slot.has-card {
            cursor: grab;
            background: <?php echo $game['background'] == 'light' ? '#e8e8e8' : '#3a3a3a'; ?>;
        }
        
        .slot.has-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
        }
        
        .slot.enemy-slot {
            background: <?php echo $game['background'] == 'light' ? '#ffebee' : '#4a2a2a'; ?>;
            border-color: #f44336;
        }
        
        .slot.drag-over {
            border-color: #ffd700 !important;
            background: #3a4a3a;
        }
        
        .sell-btn {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 24px;
            height: 24px;
            background: rgba(255, 0, 0, 0.8);
            color: white;
            border: none;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.2s;
            z-index: 10;
            font-weight: bold;
        }
        
        .slot.has-card:hover .sell-btn {
            opacity: 1;
        }
        
        .card-image {
            width: 100%;
            height: auto !important;
            aspect-ratio: 1 / 1;
            background: transparent;
            border-radius: 4px;
            margin-bottom: 8px;
            display: block;
            object-fit: contain;
        }
        
        .card-name {
            font-weight: bold;
            font-size: 14px;
            text-align: center;
            margin-bottom: 5px;
            word-break: break-word;
        }
        
        .card-stars {
            color: gold;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .card-points {
            font-size: 16px;
            font-weight: bold;
            color: #4CAF50;
            margin-bottom: 5px;
        }
        
        .card-cost {
            font-size: 12px;
            color: #888;
            margin-bottom: 5px;
        }
        
        .card-skill {
            font-size: 11px;
            color: #888;
            text-align: center;
            cursor: help;
            border-bottom: 1px dotted #888;
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            position: relative;
        }
        
        .card-skill:hover {
            background: rgba(255,215,0,0.2);
        }
        
        .skill-tooltip {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: gold;
            color: black;
            padding: 15px;
            border-radius: 8px;
            font-size: 14px;
            white-space: normal;
            width: 300px;
            text-align: center;
            z-index: 10000;
            border: 2px solid #000;
            box-shadow: 0 4px 20px rgba(0,0,0,0.8);
            line-height: 1.5;
            pointer-events: none;
        }
        
        .shop-slots {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin: 15px 0;
        }
        
        .shop-item {
            background: <?php echo $game['background'] == 'light' ? '#f5f5f5' : '#333'; ?>;
            border: 2px solid;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 0;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .shop-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255,215,0,0.3);
        }
        
        .shop-item .card-name {
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
        }
        
        .synthesis-item {
            width: 200px;
            background: <?php echo $game['background'] == 'light' ? '#f5f5f5' : '#333'; ?>;
            border: 2px solid gold;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            margin-bottom: 10px;
        }
        
        .synthesis-materials {
            font-size: 12px;
            color: #888;
            margin: 10px 0;
            padding: 5px;
            background: <?php echo $game['background'] == 'light' ? '#e8e8e8' : '#3a3a3a'; ?>;
            border-radius: 4px;
            width: calc(100% - 10px);
        }
        
        .synthesis-grid .synthesis-item {
            flex: 1 1 290px;
            max-width: 290px;
        }
        
        .synthesis-btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            margin: 5px 0;
        }
        
        .synthesis-btn:hover {
            background: #45a049;
        }
        
        .synthesis-btn.disabled {
            background: #666;
            cursor: not-allowed;
        }
        
        button {
            background: #4a6a7a;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.2s;
        }
        
        button:hover {
            background: #5a7a8a;
        }
        
        button:disabled {
            background: #666;
            cursor: not-allowed;
        }
        
        button.battle-btn {
            background: #8b4513;
            font-size: 18px;
            padding: 15px 30px;
            width: 100%;
            margin-top: 10px;
        }
        
        button.battle-btn:hover {
            background: #a05a2a;
        }
        
        .battle-result-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .battle-result-content {
            background: <?php echo $game['background'] == 'light' ? '#fff' : '#2a2a2a'; ?>;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            max-width: 960px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .battle-result-content h2 {
            color: gold;
            margin-bottom: 20px;
        }
        
        .battle-result-content p {
            margin: 10px 0;
            font-size: 18px;
        }
        
        .messages-list {
            margin: 20px 0;
            padding: 15px;
            background: <?php echo $game['background'] == 'light' ? '#f5f5f5' : '#333'; ?>;
            border-radius: 8px;
            text-align: left;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .messages-list ul {
            list-style: none;
        }
        
        .messages-list li {
            padding: 5px 0;
            border-bottom: 1px solid #444;
            color: gold;
        }
        
        .enemy-list {
            margin: 20px 0;
            padding: 15px;
            background: <?php echo $game['background'] == 'light' ? '#ffebee' : '#4a2a2a'; ?>;
            border-radius: 8px;
            text-align: left;
        }
        
        .enemy-list h4 {
            color: #f44336;
            margin-bottom: 10px;
        }
        
        .enemy-list ul {
            list-style: none;
        }
        
        .enemy-list li {
            padding: 5px 0;
            border-bottom: 1px solid #f44336;
        }
        
        .board h3,
        .bench-area h3,
        .synthesis-area h3 {
            margin-bottom: 20px;
        }
        
        .game-over {
            font-size: 24px;
            font-weight: bold;
            color: #ff6b6b;
            margin: 20px 0;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px 20px;
            background: <?php echo $game['background'] == 'light' ? '#fff' : '#2a2a2a'; ?>;
            border-radius: 8px;
            border-left: 4px solid gold;
        }
        
        .round-info {
            display: flex;
            gap: 30px;
        }
        
        .win-loss {
            font-size: 18px;
        }
        
        .win-loss .wins {
            color: #4CAF50;
        }
        
        .win-loss .losses {
            color: #f44336;
        }
        
        .pool-info {
            background: <?php echo $game['background'] == 'light' ? '#e8e8e8' : '#333'; ?>;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .reset-btn {
            background: #f44336;
            margin-left: 10px;
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .reset-btn:hover {
            background: #d32f2f;
        }
        
        .home-btn {
            background: #fff;
            color: #000;
            border: 1px solid #000;
            margin-left: 10px;
            padding: 5px 10px;
            font-size: 12px;
        }
        .home-btn:hover {
            background: #f5f5f5;
        }
        
        .enemy-title {
            color: #f44336;
            margin-bottom: 10px;
        }
        
        .message-text {
            color: <?php echo $game['background'] == 'light' ? '#ff6b6b' : '#ffd700'; ?>;
            font-style: italic;
            margin: 10px 0;
            padding: 10px;
            background: <?php echo $game['background'] == 'light' ? '#f0f0f0' : '#333'; ?>;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>⚔️ 拼点 · 先到12分者胜 
            <button class="reset-btn" onclick="resetGame()">重置游戏</button>
            <button class="home-btn" onclick="location.href='/'">回到主站</button>
        </h2>
        <div class="round-info">
            <span>第 <?= $game['round'] ?>  轮</span>
            <span class="win-loss">
                <span class="wins"><?= $game['wins'] ?></span> : <span class="losses"><?= $game['losses'] ?></span>
            </span>
            <span class="pool-info">牌库剩余: <?= $poolCount ?></span>
        </div>
    </div>
    
    
    
    <div id="skillTooltip" class="skill-tooltip"></div>
    
    <div class="game-container">
        <div class="left-panel">
            <div class="stats-bar">
                <div class="gold">💰 <?= $game['gold'] ?></div>
                <div>🖱️长按拖拽卡牌</div>
                <div>⚠️卡牌退队前会先完成该轮工作</div>
            </div>
            
            <div class="board">
                <h3>⚔️ 上场区 </h3>
                <div class="field-grid" id="field">
                    <?php for ($i = 0; $i < FIELD_SIZE; $i++): 
                        $card = $game['field'][$i] ?? null;
                        $cost = $card ? ($card['cost'] ?? 1) : 0;
                        $costClass = $cost > 5 ? 'cost-special' : 'cost-' . $cost;
                    ?>
                        <div class="slot <?= $card ? 'has-card ' . $costClass : '' ?>" 
                             data-type="field"
                             data-index="<?= $i ?>"
                             data-empty="<?= $card ? 'false' : 'true' ?>"
                             draggable="<?= $card ? 'true' : 'false' ?>">
                            <?php if ($card): ?>
                                <button class="sell-btn" onclick="sellCard('field', <?= $i ?>, event)">💰</button>
                                <?php if ($card['image_url']): ?>
                                <img src="<?= $card['image_url'] ?>" class="card-image">
                                <?php else: ?>
                                <div class="card-image">暂无图片</div>
                                <?php endif; ?>
                                <div class="card-name"><?= htmlspecialchars($card['name']) ?></div>
                                <div class="card-stars"><?= str_repeat('★', $card['star']) ?></div>
                                <div class="card-points"><?= $card['points'] ?>点</div>
                                <div class="card-cost">💰 <?= $card['cost'] ?></div>
                                <?php if ($card['skill_name']): ?>
                                <div class="card-skill" onmouseenter="showSkillTip('<?= htmlspecialchars(addslashes($card['skill_description'])) ?>')" onmouseleave="hideSkillTip()">
                                    <?= htmlspecialchars($card['skill_name']) ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            
            <div class="bench-area">
                <h3>⏳ 等待区 </h3>
                <div class="bench-slots" id="bench">
                    <?php for ($i = 0; $i < BENCH_SIZE; $i++): 
                        $card = $game['bench'][$i] ?? null;
                        $cost = $card ? ($card['cost'] ?? 1) : 0;
                        $costClass = $cost > 5 ? 'cost-special' : 'cost-' . $cost;
                    ?>
                        <div class="slot <?= $card ? 'has-card ' . $costClass : '' ?>"
                             data-type="bench"
                             data-index="<?= $i ?>"
                             data-empty="<?= $card ? 'false' : 'true' ?>"
                             draggable="<?= $card ? 'true' : 'false' ?>">
                            <?php if ($card): ?>
                                <button class="sell-btn" onclick="sellCard('bench', <?= $i ?>, event)">💰</button>
                                <?php if ($card['image_url']): ?>
                                <img src="<?= $card['image_url'] ?>" class="card-image">
                                <?php else: ?>
                                <div class="card-image">暂无图片</div>
                                <?php endif; ?>
                                <div class="card-name"><?= htmlspecialchars($card['name']) ?></div>
                                <div class="card-stars"><?= str_repeat('★', $card['star']) ?></div>
                                <div class="card-points"><?= $card['points'] ?>点</div>
                                <div class="card-cost">💰 <?= $card['cost'] ?></div>
                                <?php if ($card['skill_name']): ?>
                                <div class="card-skill" onmouseenter="showSkillTip('<?= htmlspecialchars(addslashes($card['skill_description'])) ?>')" onmouseleave="hideSkillTip()">
                                    <?= htmlspecialchars($card['skill_name']) ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            
            <!-- 敌方阵容从主界面移至战斗结果弹框呈现 -->
        </div>
        
        <div class="right-panel">
            <div class="shop">
                <div style="display: flex; justify-content: space-between;">
                    <h3>🏪 商店</h3>
                    <button onclick="refreshShop()" <?= $game['gold'] < 2 ? 'disabled' : '' ?>>刷新 (-2💰)</button>
                </div>
                <div class="shop-slots">
                    <?php if (empty($game['shop'])): ?>
                        <div style="grid-column: span 5; text-align: center; color: #888;">商店暂无卡牌，请刷新</div>
                    <?php else: ?>
                        <?php foreach ($game['shop'] as $index => $card): 
                            $displayCostBase = isset($card['original_cost']) && $card['original_cost'] !== null ? $card['original_cost'] : $card['cost'];
                            $costClass = $displayCostBase > 5 ? 'cost-special' : 'cost-' . $displayCostBase;
                        ?>
                            <div class="shop-item <?= $costClass ?>" 
                                 onclick="buyCard(<?= $index ?>)">
                                <?php if ($card['image_url']): ?>
                                <img src="<?= $card['image_url'] ?>" class="card-image">
                                <?php else: ?>
                                <div class="card-image">暂无图片</div>
                                <?php endif; ?>
                                <div class="card-name"><?= htmlspecialchars($card['name']) ?></div>
                                <div class="card-stars">★</div>
                                <div class="card-points"><?= $card['current_points'] ?>点</div>
                                <div class="card-cost">
                                    💰 
                                    <?php if (isset($card['original_cost']) && $card['original_cost'] !== null && $card['cost'] == 0): ?>
                                        <span style="text-decoration: line-through; opacity: 0.7;"><?= $card['original_cost'] ?></span> 0
                                    <?php else: ?>
                                        <?= $card['cost'] ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($card['skill_name']): ?>
                                <div class="card-skill" onmouseenter="showSkillTip('<?= htmlspecialchars(addslashes($card['skill_description'])) ?>')" onmouseleave="hideSkillTip()">
                                    <?= htmlspecialchars($card['skill_name']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($card['is_spawned']) && $card['is_spawned']): ?>
                                <div style="font-size:10px; color:gold;">✨ 技能刷出</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button class="battle-btn" onclick="startBattle()" <?= $game['inBattle'] ? 'disabled' : '' ?>>
                    ⚔️ 开始战斗
                </button>
            </div>
            
            <div class="synthesis-area">
                <h3>🔮 合成区</h3>
                <div style="display:flex; justify-content: space-between; margin-bottom:20px;">
                    <div style="color:#888;">合成材料需要均放置于等待区</div>
                    <div style="text-align:right;">
                        <?php if (!empty($game['last_synthesis_message'])): 
                            $msg = $game['last_synthesis_message'];
                            $col = (strpos($msg, '成功合成') !== false) ? '#4CAF50' : ((strpos($msg, '失败') !== false) ? '#f44336' : '#ffd700');
                        ?>
                            <span style="color: <?= $col ?>;"><?= htmlspecialchars($msg) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="synthesis-grid">
                    <?php if (empty($synthesisFormulas)): ?>
                        <div style="color: #888;">暂无可用合成</div>
                    <?php else: ?>
                        <?php foreach ($synthesisFormulas as $formula): ?>
                        <div class="synthesis-item">
                            <strong><?= htmlspecialchars($formula['name']) ?></strong>
                            <div class="synthesis-materials">
                                材料:<br>
                                <?php foreach ($formula['materials'] as $m): ?>
                                    <?= $formula['star'] ?>星<?= $m['name'] ?> <?= ($m['required'] == 1 ? '' : ('x' . $m['required'])) ?>
                                    (拥有: <?= $m['has'] ?>)<br>
                                <?php endforeach; ?>
                            </div>
                            <div>结果: <?= htmlspecialchars($formula['result']) ?> (<?= $formula['points'] ?>点) 费用:<?= $formula['cost'] ?></div>
                            <?php if ($formula['type'] == 'special'): ?>
                                <button class="synthesis-btn" onclick="doSynthesis(<?= $formula['id'] ?>, <?= $formula['star'] ?>)">合成</button>
                            <?php else: ?>
                                <button class="synthesis-btn" onclick="doStarSynthesis(<?= $formula['card_id'] ?>, <?= $formula['star'] ?>)">升星</button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php if ($game['inBattle'] && $game['battleResult']): ?>
    <div class="battle-result-modal">
        <div class="battle-result-content">
            <h2 id="battleTitle">⚔️ 战斗进行中...</h2>
            <div class="enemy-team">
                <h3 class="enemy-title">🛡️ 我方阵容</h3>
                <div class="enemy-grid">
                    <?php foreach ($game['battlePlayer'] as $i => $card): ?>
                        <div class="slot <?= $card ? 'has-card' : '' ?>">
                            <?php if ($card): ?>
                                <?php if (!empty($card['image_url'])): ?>
                                <img src="<?= $card['image_url'] ?>" class="card-image">
                                <?php else: ?>
                                <div class="card-image">我方</div>
                                <?php endif; ?>
                                <div class="card-name"><?= htmlspecialchars($card['name']) ?></div>
                                <?php if (!empty($card['star'])): ?>
                                <div class="card-stars"><?= str_repeat('★', intval($card['star'])) ?></div>
                                <?php endif; ?>
                                <div class="card-points"><?= $card['points'] ?>点</div>
                                <?php if (!empty($card['skill_name'])): ?>
                                <div class="card-skill" onmouseenter="showSkillTip('<?= htmlspecialchars(addslashes($card['skill_description'])) ?>')" onmouseleave="hideSkillTip()">
                                    <?= htmlspecialchars($card['skill_name']) ?>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="card-name">空位</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="enemy-team">
                <h3 class="enemy-title">👾 敌方阵容</h3>
                <div class="enemy-grid">
                    <?php foreach ($game['battleEnemy'] as $i => $card): ?>
                        <div class="slot enemy-slot <?= $card ? 'has-card' : '' ?>">
                            <?php if ($card): ?>
                                <?php if (!empty($card['image_url'])): ?>
                                <img src="<?= $card['image_url'] ?>" class="card-image">
                                <?php else: ?>
                                <div class="card-image">敌方</div>
                                <?php endif; ?>
                                <div class="card-name"><?= htmlspecialchars($card['name']) ?></div>
                                <?php if (!empty($card['star'])): ?>
                                <div class="card-stars"><?= str_repeat('★', intval($card['star'])) ?></div>
                                <?php endif; ?>
                                <div class="card-points"><?= $card['points'] ?>点</div>
                                <?php if ($card['skill_name']): ?>
                                <div class="card-skill" onmouseenter="showSkillTip('<?= htmlspecialchars(addslashes($card['skill_description'])) ?>')" onmouseleave="hideSkillTip()">
                                    <?= htmlspecialchars($card['skill_name']) ?>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="card-name">空位</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="messages-list">
                <h4>📖 战斗记录：</h4>
                <ul id="battleLog"></ul>
            </div>
            <div id="battleSummary" style="display:none;">
                <h4>📊 战斗结果：</h4>
                <p id="scores"></p>
                <p id="bonus"></p>
                <p id="record"></p>
                <div id="gameOver" style="display:none;"></div>
            </div>
            <button onclick="endBattle()" style="display:none;">继续</button>
        </div>
    </div>
    <script>
    (function(){
        const inBattle = <?= json_encode($game['inBattle'] && $game['battleResult']) ?>;
        if (!inBattle) return;
        const logs = <?= json_encode($game['battleMessages'] ?? []) ?>;
        const enemy = <?= json_encode($game['battleEnemy'] ?? []) ?>;
        const summary = {
            win: <?= $game['battleResult']['win'] ? 'true' : 'false' ?>,
            playerScore: <?= (int)$game['battleResult']['playerScore'] ?>,
            enemyScore: <?= (int)$game['battleResult']['enemyScore'] ?>,
            bonus: <?= (int)$game['battleResult']['bonus'] ?>,
            wins: <?= (int)$game['wins'] ?>,
            losses: <?= (int)$game['losses'] ?>,
            gameOver: <?= $game['battleResult']['gameOver'] ? 'true' : 'false' ?>,
            result: <?= $game['battleResult']['result'] ? json_encode($game['battleResult']['result']) : 'null' ?>
        };
        const modal = document.querySelector('.battle-result-modal');
        const logEl = modal.querySelector('#battleLog');
        const summaryEl = modal.querySelector('#battleSummary');
        const titleEl = modal.querySelector('#battleTitle');
        const continueBtn = modal.querySelector('button');
        let i = 0;
        function step() {
            if (i < logs.length) {
                const li = document.createElement('li');
                li.textContent = logs[i];
                logEl.appendChild(li);
                i++;
                setTimeout(step, 500);
            } else {
                titleEl.textContent = summary.win ? '🎉 胜利！' : '💔 失败...';
                summaryEl.querySelector('#scores').textContent = '得分: ' + summary.playerScore + ' : ' + summary.enemyScore;
                summaryEl.querySelector('#bonus').textContent = '获得金币: +' + summary.bonus;
                summaryEl.querySelector('#record').textContent = '当前战绩: ' + summary.wins + '胜 ' + summary.losses + '负 (目标12分)';
                if (summary.gameOver) {
                    const over = summaryEl.querySelector('#gameOver');
                    over.style.display = 'block';
                    over.textContent = summary.result === 'win' ? '🎉 通关成功！' : '💔 游戏结束';
                }
                summaryEl.style.display = 'block';
                continueBtn.style.display = 'inline-block';
            }
        }
        setTimeout(step, 500);
    })();
    </script>
    <?php endif; ?>
    
    <script>
        let draggedItem = null;
        
        function showSkillTip(text) {
            document.getElementById('skillTooltip').style.display = 'block';
            document.getElementById('skillTooltip').innerHTML = text;
        }
        
        function hideSkillTip() {
            document.getElementById('skillTooltip').style.display = 'none';
        }
        
        document.querySelectorAll('.slot.has-card').forEach(slot => {
            slot.addEventListener('dragstart', (e) => {
                draggedItem = {
                    type: slot.dataset.type,
                    index: slot.dataset.index
                };
                e.dataTransfer.setData('text/plain', 'drag');
            });
            
            slot.addEventListener('dragend', () => {
                setTimeout(() => { draggedItem = null; }, 100);
            });
            
            slot.setAttribute('draggable', 'true');
        });
        
        document.querySelectorAll('.slot').forEach(slot => {
            slot.addEventListener('dragover', (e) => {
                e.preventDefault();
                slot.classList.add('drag-over');
            });
            
            slot.addEventListener('dragleave', () => {
                slot.classList.remove('drag-over');
            });
            
            slot.addEventListener('drop', (e) => {
                e.preventDefault();
                slot.classList.remove('drag-over');
                
                if (!draggedItem) return;
                
                const toType = slot.dataset.type;
                const toIndex = slot.dataset.index;
                
                const formData = new FormData();
                formData.append('action', 'move');
                formData.append('from_type', draggedItem.type);
                formData.append('from_index', draggedItem.index);
                formData.append('to_type', toType);
                formData.append('to_index', toIndex);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(result => {
                    if (result.success) location.reload();
                });
            });
        });
        
        document.addEventListener('dragover', (e) => e.preventDefault());
        document.addEventListener('drop', (e) => e.preventDefault());
        
        async function apiCall(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            Object.keys(data).forEach(key => formData.append(key, data[key]));
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.success !== false) {
                if (result.reset) {
                    window.location.reload();
                } else {
                    location.reload();
                }
            } else {
                alert(result.msg || '操作失败');
            }
        }
        
        function refreshShop() {
            apiCall('refresh_shop');
        }
        
        function buyCard(index) {
            apiCall('buy', { index: index });
        }
        
        function sellCard(type, index, event) {
            event.stopPropagation();
            if (confirm('确定出售吗？')) {
                apiCall('sell', { type: type, index: index });
            }
        }
        
        function startBattle() {
            apiCall('battle');
        }
        
        function endBattle() {
            apiCall('end_battle');
        }
        
        function doSynthesis(formulaId, star) {
            if (confirm('确定合成吗？')) {
                apiCall('synthesis', { formula_id: formulaId, star: star });
            }
        }
        
        function doStarSynthesis(cardId, star) {
            if (confirm('确定升星合成吗？')) {
                apiCall('star_synthesis', { card_id: cardId, star: star });
            }
        }
        
        function resetGame() {
            if (confirm('确定重置游戏吗？')) {
                apiCall('reset');
            }
        }
    </script>
</body>
</html>

