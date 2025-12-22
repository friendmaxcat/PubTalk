<?php
/**
 * PubTalk v9.5 - JSON Fix, Full API Implementation & Working Context Menu
 */

session_start();

// --- [КОНФИГУРАЦИЯ ФАЙЛОВ] ---
define('DATA_DIR', 'data/');
define('USER_DATA_FILE', DATA_DIR . 'users.json');
define('MESSAGE_DATA_FILE', DATA_DIR . 'messages.json');
define('CHAT_DATA_FILE', DATA_DIR . 'chats.json');
define('MARKET_DATA_FILE', DATA_DIR . 'market.json');
define('SHOP_DATA_FILE', DATA_DIR . 'shop.json'); 

// --- [ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ ФАЙЛОВОГО ВВОДА/ВЫВОДА] ---

function load_json($file) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    return json_decode($content, true) ?? [];
}

function save_json($file, $data) {
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        error_log("Ошибка: Папка '" . DATA_DIR . "' не существует или не имеет прав на запись!");
        return false;
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($file, $json);
}

// --- [СИСТЕМА ДОСТИЖЕНИЙ] ---

$achievement_rewards = [
    'ach_veteran' => ['name' => 'Ветеран Чаттер', 'title' => 'Ветеран', 'frame_id' => 'frame_ach_veteran'],
    'ach_chatter' => ['name' => 'Болтун Года', 'title' => 'Болтун', 'frame_id' => 'frame_ach_chatter'],
    'ach_millionaire' => ['name' => 'Звездный Миллионер', 'title' => 'Миллионер', 'frame_id' => 'frame_ach_millionaire'],
];

function check_achievements(&$user, $rewards) {
    $achievements_to_grant = [];
    $initial_achievements = $user['achievements'] ?? [];

    if (($user['level'] ?? 1) >= 5 && !in_array('ach_veteran', $initial_achievements)) {
        $achievements_to_grant[] = 'ach_veteran';
    }
    if (($user['messages_sent'] ?? 0) >= 10 && !in_array('ach_chatter', $initial_achievements)) {
        $achievements_to_grant[] = 'ach_chatter';
    }
    if (($user['wallet']['stars'] ?? 0) >= 10000 && !in_array('ach_millionaire', $initial_achievements)) {
        $achievements_to_grant[] = 'ach_millionaire';
    }

    foreach ($achievements_to_grant as $ach_id) {
        $user['achievements'][] = $ach_id;
        $reward = $rewards[$ach_id];
        
        if (!in_array($reward['frame_id'], $user['inventory'])) {
             $user['inventory'][] = $reward['frame_id'];
        }
    }
    
    return count($achievements_to_grant) > 0;
}

// --- [ЗАГРУЗКА И СТАТИЧЕСКИЕ ДАННЫЕ] ---

$users = load_json(USER_DATA_FILE);
$messages = load_json(MESSAGE_DATA_FILE);
$chats = load_json(CHAT_DATA_FILE);
$market = load_json(MARKET_DATA_FILE); 
$shop_data = load_json(SHOP_DATA_FILE); 

// Инициализация дефолтных чатов
if (empty($chats)) {
    $chats = [
        'global_1' => ['name' => 'Площадь | Global', 'type' => 'channel', 'members' => []],
        'default_dm' => ['name' => 'Личные апартаменты', 'type' => 'dm', 'members' => []]
    ];
    save_json(CHAT_DATA_FILE, $chats);
}

// Статическая генерация PublicCat Shop - FRAME tab
$frames_shop = [];
$colors = ['Blue', 'Red', 'Green', 'Pink', 'Cyan', 'Purple', 'Gold', 'Plasma', 'Void', 'Divine'];
$basePrice = 100;
foreach ($colors as $i => $color) {
    $frames_shop[] = [
        'id' => 'frame_' . $i,
        'name' => "Neon $color",
        'price' => floor($basePrice * pow(1.5, $i)),
        'rarity' => $i > 6 ? 'legendary' : ($i > 3 ? 'epic' : 'common'),
        'type' => 'frame'
    ];
}

$public_shop = array_merge($frames_shop, $shop_data);

// --- [ОБРАБОТКА AJAX ЗАПРОСОВ (API)] ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $res = ['status' => 'error'];

    // --- АВТОРИЗАЦИЯ ---
    if ($action === 'register') {
        $username = htmlspecialchars(trim($input['username']));
        $password = trim($input['password']);

        if (isset($users[$username])) $res['msg'] = 'Пользователь уже существует!'; 
        elseif (strlen($password) < 4) $res['msg'] = 'Пароль слишком короткий!';
        else {
            $role = ($username === 'PublicCat') ? 'admin' : 'user';
            
            $newUser = [
                'username' => $username, 'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role, 'level' => 1, 'xp' => 0, 'max_xp' => 100, 'is_premium' => false,
                'wallet' => ['stars' => 0, 'tokens' => 0, 'fame' => 0],
                'inventory' => [], 'equipped_frame' => null,
                'messages_sent' => 0, 
                'achievements' => [],
                'favorites' => [], 
                'highlight_until' => 0, 
            ];
            $users[$username] = $newUser;
            save_json(USER_DATA_FILE, $users);
            $_SESSION['current_user'] = $username;
            $res = ['status' => 'ok', 'user' => $newUser];
        }
        echo json_encode($res); exit;
    } elseif ($action === 'login') {
        $u = $input['username']; $p = $input['password']; $user = $users[$u] ?? null;
        if ($user && password_verify($p, $user['password'])) {
            $_SESSION['current_user'] = $u;
            $res = ['status' => 'ok', 'user' => $user];
        } else $res['msg'] = 'Неверный логин или пароль';
        echo json_encode($res); exit;
    } elseif ($action === 'logout') {
        unset($_SESSION['current_user']); $res = ['status' => 'ok'];
        echo json_encode($res); exit;
    }

    // --- ИГРОВАЯ ЛОГИКА (Требует входа) ---
    
    if (!isset($_SESSION['current_user']) || !isset($users[$_SESSION['current_user']])) { 
        echo json_encode(['status' => 'auth_error']); exit; 
    }
    
    $currentUserKey = $_SESSION['current_user'];
    $userData = &$users[$currentUserKey];

    // Получение состояния
    if ($action === 'fetch_state') {
        check_achievements($userData, $achievement_rewards);
        save_json(USER_DATA_FILE, $users);

        $chatId = $input['chat_id'] ?? 'global_1';
        $offset = (int)($input['offset'] ?? 0);
        $limit = (int)($input['limit'] ?? 50); // Default to 50 messages per fetch

        if (!isset($chats[$chatId])) $chatId = 'global_1';

        $all_chat_messages = array_filter($messages, fn($m) => ($m['chat_id'] ?? 'global_1') === $chatId);
        $all_chat_messages = array_values($all_chat_messages); // Reindex

        // Get total count before slicing
        $total_messages_in_chat = count($all_chat_messages);

        // Slice messages based on offset and limit
        $current_messages = array_slice($all_chat_messages, max(0, $total_messages_in_chat - ($offset + $limit)), $limit);


        echo json_encode([
            'user' => $userData,
            'messages' => $current_messages,
            'shop' => $public_shop,
            'market' => $market,
            'chats' => $chats,
            'current_chat_id' => $chatId,
            'achievements_meta' => $achievement_rewards,
            'total_messages' => $total_messages_in_chat // Add total count for pagination
        ]);
        exit;
    }
    
    // Создание нового чата/группы
    if ($action === 'create_chat') { 
        $chatName = htmlspecialchars(trim($input['name']));
        $chatType = $input['type'] === 'channel' ? 'channel' : 'group';
        
        if (strlen($chatName) < 3) {
            $res['msg'] = 'Название чата слишком короткое.';
        } else {
            $newId = uniqid('chat_');
            $chats[$newId] = [
                'name' => $chatName,
                'type' => $chatType,
                'members' => [$currentUserKey],
                'owner' => $currentUserKey 
            ];
            save_json(CHAT_DATA_FILE, $chats);
            $res = ['status' => 'ok', 'chat_id' => $newId];
        }
        echo json_encode($res); exit;
    }

    // Отправка сообщения
    if ($action === 'send_message') {
        $text = $input['text'];
        $encryptedText = $input['encrypted_text'];
        $chatId = $input['chat_id'];

        if ($text && $chatId) {
            $chatType = $chats[$chatId]['type'] ?? 'channel';
            $chatOwner = $chats[$chatId]['owner'] ?? null; // Get channel owner

            // If it's a channel and current user is not the owner, deny message
            if ($chatType === 'channel' && $chatOwner !== $currentUserKey) {
                echo json_encode(['status' => 'error', 'msg' => 'Только владелец канала может отправлять сообщения.']);
                exit;
            }
            
            $msg = [
                'id' => uniqid('msg_'), 
                'time' => date('H:i'),
                'author' => $userData['username'],
                'role' => $userData['role'],
                'is_premium' => $userData['is_premium'],
                // Конфиденциальность: в DM/Group не храним открытый текст
                'text' => ($chatType === 'dm' || $chatType === 'group') ? '' : $text, 
                'encrypted_text' => $encryptedText, 
                'frame' => $userData['equipped_frame'],
                'chat_id' => $chatId,
                'quote' => $input['quote'] ?? null
            ];
            $messages[] = $msg;
            save_json(MESSAGE_DATA_FILE, $messages);
            
            // Начисление опыта
            $xpGain = 10 * ($userData['is_premium'] ? 1.5 : 1);
            $userData['xp'] += $xpGain;
            if ($userData['xp'] >= $userData['max_xp']) {
                $userData['level']++;
                $userData['xp'] = 0;
                $userData['max_xp'] = 100 * pow(1.2, $userData['level'] - 1);
                $userData['wallet']['stars'] += 50;
            }
            $userData['messages_sent'] = ($userData['messages_sent'] ?? 0) + 1;
            check_achievements($userData, $achievement_rewards);
            save_json(USER_DATA_FILE, $users);
        }
        echo json_encode(['status' => 'ok']); exit;
    }

    // Покупка Премиума
    if ($action === 'buy_premium') { 
        $price = 4444;
        if (!$userData['is_premium'] && $userData['wallet']['stars'] >= $price) {
            $userData['wallet']['stars'] -= $price;
            $userData['is_premium'] = true;
            save_json(USER_DATA_FILE, $users);
            $res = ['status' => 'ok', 'msg' => 'Поздравляем! Вы купили Premium.'];
        } else {
            $currentStars = $userData['wallet']['stars'];
            $statusMsg = $userData['is_premium'] ? 'Вы уже Premium' : "Недостаточно средств (у вас $currentStars ⭐, требуется 4444 ⭐)";
            $res['msg'] = $statusMsg;
        }
        echo json_encode($res); exit;
    }

    // --- Выделение сообщения ---
    if ($action === 'buy_highlight') {
        $duration = $input['duration'];
        $price = 0;
        $seconds = 0;

        if ($duration === '24h') {
            $price = 15;
            $seconds = 24 * 3600;
        } elseif ($duration === '7d') {
            $price = 90;
            $seconds = 7 * 24 * 3600;
        } else {
            $res['msg'] = 'Неверная длительность.';
            echo json_encode($res); exit;
        }

        if ($userData['wallet']['stars'] >= $price) {
            $userData['wallet']['stars'] -= $price;
            
            $startTime = max(time(), $userData['highlight_until']);
            $userData['highlight_until'] = $startTime + $seconds;
            
            save_json(USER_DATA_FILE, $users);
            $res = ['status' => 'ok', 'msg' => 'Ваши сообщения будут выделены до ' . date('Y-m-d H:i', $userData['highlight_until']) . '!'];
        } else {
            $res['msg'] = "Недостаточно средств (требуется $price ⭐).";
        }
        echo json_encode($res); exit;
    }
    
    // Покупка/Надевание предмета из Shop/Inventory
    if ($action === 'buy_shop_item' || $action === 'equip_item') {
        $itemId = $input['item_id'];
        $item = null;
        foreach($public_shop as $s) if($s['id'] === $itemId) $item = $s;
        
        $ach_item = array_filter($achievement_rewards, fn($a) => $a['frame_id'] === $itemId);
        if ($ach_item) {
             $item = ['id' => $itemId, 'name' => 'Achievement Frame', 'price' => 0, 'type' => 'frame'];
        }
        
        if (!$item) { $res['msg'] = 'Предмет не найден.'; }
        
        if (in_array($itemId, $userData['inventory']) && ($item['type'] ?? 'frame') === 'frame') {
            // Equip
            $userData['equipped_frame'] = $itemId;
            $res = ['status' => 'ok', 'msg' => 'Полоса надета!'];
        } elseif ($item && ($item['price'] ?? 0) > 0 && $userData['wallet']['stars'] >= $item['price']) {
            // Buy
            $userData['wallet']['stars'] -= $item['price'];
            $userData['inventory'][] = $item['id'];
            
            if (($item['type'] ?? 'frame') === 'frame') {
                $userData['equipped_frame'] = $item['id'];
                $res = ['status' => 'ok', 'msg' => 'Куплено и надето!'];
            } else {
                 $res = ['status' => 'ok', 'msg' => 'Куплено! Добавлено в инвентарь.'];
            }
        } else {
            $res['msg'] = 'Недостаточно средств или предмет недоступен для покупки.';
        }
        
        save_json(USER_DATA_FILE, $users);
        echo json_encode($res); exit;
    }
    
    // --- Удаление сообщения ---
    if ($action === 'delete_message') {
        $msgId = $input['message_id'];
        $found = false;
        foreach ($messages as $index => $msg) {
            if (($msg['id'] ?? $index) === $msgId && $msg['author'] === $currentUserKey) {
                array_splice($messages, $index, 1);
                $found = true;
                break;
            }
        }
        if ($found) {
            save_json(MESSAGE_DATA_FILE, $messages);
            $res = ['status' => 'ok', 'msg' => 'Сообщение удалено.'];
        } else {
            $res['msg'] = 'Ошибка: Сообщение не найдено или вы не его автор.';
        }
        echo json_encode($res); exit;
    }

    // --- Редактирование сообщения ---
    if ($action === 'edit_message') {
        $msgId = $input['message_id'];
        $newText = trim($input['new_text']);
        $encryptedText = base64_encode($newText);
        
        $found = false;
        if (strlen($newText) < 1) { $res['msg'] = 'Сообщение не может быть пустым.'; echo json_encode($res); exit; }
        
        foreach ($messages as &$msg) {
            if (($msg['id'] ?? null) === $msgId && $msg['author'] === $currentUserKey) {
                
                $chatType = $chats[$msg['chat_id']]['type'] ?? 'channel';
                if ($chatType === 'dm' || $chatType === 'group') {
                    $msg['text'] = '';
                } else {
                    $msg['text'] = $newText;
                }
                
                $msg['encrypted_text'] = $encryptedText;
                $msg['edited'] = true; 
                $found = true;
                break;
            }
        }
        unset($msg);
        
        if ($found) {
            save_json(MESSAGE_DATA_FILE, $messages);
            $res = ['status' => 'ok', 'msg' => 'Сообщение отредактировано.'];
        } else {
            $res['msg'] = 'Ошибка: Сообщение не найдено или вы не его автор.';
        }
        echo json_encode($res); exit;
    }
    
    // --- Поиск (Пользователи/Чаты) ---
    if ($action === 'search') {
        $query = strtolower(trim($input['query']));
        $results = ['users' => [], 'chats' => []];

        if (strlen($query) >= 2) {
            foreach ($users as $username => $user) {
                if (str_contains(strtolower($username), $query)) {
                    $results['users'][] = [
                        'username' => $username,
                        'role' => $user['role'],
                        'is_premium' => $user['is_premium']
                    ];
                }
            }

            foreach ($chats as $chatId => $chat) {
                if (str_contains(strtolower($chat['name']), $query)) {
                    $results['chats'][] = [
                        'id' => $chatId,
                        'name' => $chat['name'],
                        'type' => $chat['type']
                    ];
                }
            }
        }

        $res = ['status' => 'ok', 'results' => $results];
        echo json_encode($res); exit;
    }
    
    // ----------------------------------------------------------------
    // --- НОВЫЕ РЕАЛИЗОВАННЫЕ API ДЕЙСТВИЯ (Fix для Context Menu) ---
    // ----------------------------------------------------------------
    
    // --- Получить профиль другого пользователя (для openProfileModal) ---
    if ($action === 'get_profile') {
        $target = $input['target_user'];
        if (isset($users[$target])) {
            $userProfile = $users[$target];
            // Убрать пароль
            unset($userProfile['password']);
            $res = ['status' => 'ok', 'profile' => $userProfile];
        } else {
            $res['msg'] = 'Пользователь не найден.';
        }
        echo json_encode($res); exit;
    }
    
    // --- Подарить звезды (для giftStars) ---
    if ($action === 'gift_stars') {
        $target = $input['target_user'];
        $amount = (int)$input['amount'];

        if (!isset($users[$target]) || $target === $currentUserKey) {
            $res['msg'] = 'Неверный пользователь или вы пытаетесь подарить себе.';
        } elseif ($amount <= 0 || $amount > $userData['wallet']['stars']) {
            $res['msg'] = 'Неверная сумма или недостаточно звезд.';
        } else {
            $userData['wallet']['stars'] -= $amount;
            $users[$target]['wallet']['stars'] += $amount;
            save_json(USER_DATA_FILE, $users);
            $res = ['status' => 'ok', 'msg' => "Вы успешно подарили $amount ⭐ пользователю $target."];
        }
        echo json_encode($res); exit;
    }

    // --- Добавить в избранное (для addToFavorites) ---
    if ($action === 'add_to_favorites') {
        $msg_data = [
            'author' => $input['author'],
            'text' => $input['text'],
            'time' => $input['time'],
            'chat_id' => $input['chat_id']
        ];

        if (!isset($userData['favorites'])) $userData['favorites'] = [];
        
        // Проверка на дубликат (по автору и тексту)
        $isDuplicate = false;
        foreach ($userData['favorites'] as $fav) {
            if ($fav['text'] === $msg_data['text'] && $fav['author'] === $msg_data['author']) {
                $isDuplicate = true;
                break;
            }
        }
        
        if ($isDuplicate) {
            $res['msg'] = 'Это сообщение уже в избранном.';
        } else {
            $userData['favorites'][] = $msg_data;
            save_json(USER_DATA_FILE, $users);
            $res = ['status' => 'ok', 'msg' => 'Сообщение добавлено в Избранное!'];
        }
        echo json_encode($res); exit;
    }
    
    // --- Обновить профиль (для updateProfile) ---
    if ($action === 'update_profile') {
        $new_password = trim($input['new_password'] ?? '');
        $new_frame = $input['new_frame'] ?? null;

        if ($new_password) {
            if (strlen($new_password) < 4) {
                $res['msg'] = 'Новый пароль слишком короткий.';
                echo json_encode($res); exit;
            }
            $userData['password'] = password_hash($new_password, PASSWORD_DEFAULT);
        }

        // Если null или пустая строка, сбросить рамку
        if ($new_frame !== null && (in_array($new_frame, $userData['inventory']) || $new_frame === '')) {
            $userData['equipped_frame'] = $new_frame ?: null;
        }

        save_json(USER_DATA_FILE, $users);
        $res = ['status' => 'ok', 'msg' => 'Профиль обновлен!'];
        echo json_encode($res); exit;
    }
    
    // --- Выставить предмет на Маркет (для listItem) ---
    if ($action === 'list_item') {
        $itemId = $input['item_id'];
        $price = (int)$input['price'];
        
        if (!in_array($itemId, $userData['inventory'])) {
            $res['msg'] = 'У вас нет этого предмета.';
        } elseif ($price < 1) {
            $res['msg'] = 'Цена должна быть больше 0.';
        } else {
            // Удалить из инвентаря
            $index = array_search($itemId, $userData['inventory']);
            if ($index !== false) {
                array_splice($userData['inventory'], $index, 1);
            }
            
            // Добавить на маркет
            $listingId = uniqid('list_');
            $market[$listingId] = [
                'id' => $listingId,
                'item_id' => $itemId,
                'seller' => $currentUserKey,
                'price' => $price,
                'time' => time(),
            ];
            save_json(USER_DATA_FILE, $users);
            save_json(MARKET_DATA_FILE, $market);
            $res = ['status' => 'ok', 'msg' => 'Предмет выставлен на Маркет!'];
        }
        echo json_encode($res); exit;
    }
    
    // --- Купить предмет с Маркета (для buyMarketItem) ---
    if ($action === 'buy_market_item') {
        $listingId = $input['listing_id'];
        $listing = $market[$listingId] ?? null;

        if (!$listing) {
            $res['msg'] = 'Объявление не найдено.';
        } elseif ($listing['seller'] === $currentUserKey) {
            $res['msg'] = 'Вы не можете купить свой собственный предмет.';
        } elseif ($userData['wallet']['stars'] < $listing['price']) {
            $res['msg'] = 'Недостаточно звезд.';
        } else {
            // Транзакция
            $userData['wallet']['stars'] -= $listing['price'];
            $userData['inventory'][] = $listing['item_id'];
            
            // Начисление денег продавцу
            $users[$listing['seller']]['wallet']['stars'] += $listing['price'];
            
            // Удалить с маркет
            unset($market[$listingId]);

            save_json(USER_DATA_FILE, $users);
            save_json(MARKET_DATA_FILE, $market);
            $res = ['status' => 'ok', 'msg' => 'Покупка успешна! Предмет добавлен в ваш инвентарь.'];
        }
        echo json_encode($res); exit;
    }
    
    if ($action === 'create_dm_chat') {
        $targetUsername = htmlspecialchars(trim($input['target_username']));

        if (!isset($users[$targetUsername])) {
            $res['msg'] = 'Пользователь не найден.';
        } elseif ($targetUsername === $currentUserKey) {
            $res['msg'] = 'Вы не можете создать личный чат с самим собой.';
        } else {
            // Check if DM already exists
            $dm_exists = false;
            $existing_dm_id = null;
            foreach ($chats as $chatId => $chat) {
                if ($chat['type'] === 'dm' && count($chat['members']) === 2 &&
                    in_array($currentUserKey, $chat['members']) && in_array($targetUsername, $chat['members'])) {
                    $dm_exists = true;
                    $existing_dm_id = $chatId;
                    break;
                }
            }

            if ($dm_exists) {
                $res = ['status' => 'ok', 'chat_id' => $existing_dm_id, 'msg' => 'Личный чат уже существует.'];
            } else {
                $newId = uniqid('dm_');
                $chats[$newId] = [
                    'name' => 'Личный чат с ' . $targetUsername, // You might want a more dynamic name in JS
                    'type' => 'dm',
                    'members' => [$currentUserKey, $targetUsername],
                    'owner' => $currentUserKey // DM owner is the creator
                ];
                save_json(CHAT_DATA_FILE, $chats);
                $res = ['status' => 'ok', 'chat_id' => $newId, 'msg' => 'Личный чат создан.'];
            }
        }
        echo json_encode($res); exit;
    }

    // --- АДМИН ДЕЙСТВИЯ (PublicCat) ---
    if ($userData['role'] === 'admin') {
        
        if ($action === 'admin_action') {
            $subAction = $input['sub_action'];
            
            if ($subAction === 'add_money') {
                $userData['wallet']['stars'] += 10000;
                save_json(USER_DATA_FILE, $users); 
                $res = ['status' => 'ok', 'msg' => '+10,000⭐ начислено'];
            }
            
            if ($subAction === 'clear_chat') {
                $sys_msg_text = 'Чат был очищен Администратором.';
                $messages = [[
                    'id' => uniqid('msg_'),
                    'time'=>date('H:i'), 
                    'author'=>'System', 
                    'role'=>'system', 
                    'text'=>$sys_msg_text, 
                    'is_premium'=>false, 
                    'frame'=>null, 
                    'chat_id' => 'global_1', 
                    'encrypted_text' => base64_encode($sys_msg_text) 
                ]];
                save_json(MESSAGE_DATA_FILE, $messages);
                $res = ['status' => 'ok'];
            }
            
            // --- СИСТЕМНОЕ СМС (FIX) ---
            if ($subAction === 'system_msg') {
                $text = $input['text'];
                $chatId = $input['chat_id'] ?? 'global_1';
                $encrypted_text = base64_encode($text);
                 $messages[] = [
                    'id' => uniqid('msg_'), 
                    'time'=>date('H:i'), 
                    'author'=>'PubTalk Official', 
                    'role'=>'admin', 
                    'text'=>$text, 
                    'is_premium'=>true, 
                    'frame'=>'frame_9', 
                    'chat_id' => $chatId, 
                    'encrypted_text' => $encrypted_text
                ];
                save_json(MESSAGE_DATA_FILE, $messages); 
                $res = ['status' => 'ok', 'msg' => 'Системное сообщение отправлено.'];
            }
            
            echo json_encode($res); exit;
        }
    }
    
    // --- АДМИН ДЕЙСТВИЯ (Остальное) ---
    if ($userData['role'] === 'admin' && $action === 'admin_set_xp') {
        $target = $input['target_user'];
        $level = (int)$input['level'];
        $xp = (int)$input['xp'];

        if (isset($users[$target])) {
            $users[$target]['level'] = max(1, $level);
            $users[$target]['max_xp'] = 100 * pow(1.2, $users[$target]['level'] - 1);
            $users[$target]['xp'] = max(0, $xp);
            save_json(USER_DATA_FILE, $users);
            $res = ['status' => 'ok', 'msg' => "Прогрессия пользователя $target обновлена."];
        } else {
            $res['msg'] = 'Пользователь не найден.';
        }
        echo json_encode($res); exit;
    }
    
    if ($userData['role'] === 'admin' && $action === 'admin_add_shop_item') {
        $type = $input['type'];
        $name = htmlspecialchars(trim($input['name']));
        $price = (int)$input['price'];
        $image = $input['image'] ?? 'default.png';

        if (in_array($type, ['nft', 'gift']) && $name && $price > 0) {
            $shop_data[] = [
                'id' => uniqid($type . '_'),
                'name' => $name,
                'price' => $price,
                'rarity' => 'special',
                'type' => $type,
                'image' => $image,
                'added_by' => $currentUserKey
            ];
            save_json(SHOP_DATA_FILE, $shop_data);
            $res = ['status' => 'ok', 'msg' => 'Товар добавлен в магазин!'];
        } else {
            $res['msg'] = 'Неверные данные товара.';
        }
        echo json_encode($res); exit;
    }

    // --- ФИНАЛЬНАЯ ЛОВУШКА AJAX ЗАПРОСОВ (Fixing the JSON Error) ---
    // Если ни одно из действий выше не сработало и не вызвало exit,
    // мы выводим ошибку и принудительно завершаем скрипт JSON-ом, 
    // чтобы избежать вывода HTML.
    if ($action) {
        $res['msg'] = 'Неизвестное или нереализованное действие: ' . $action;
    }
    echo json_encode($res); 
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PubTalk v9.5 | JSON & Full API Fix</title>
    <style>
        /* --- CSS: Адаптивность и Стили --- */
        :root {
            --bg: #090a10; --panel: #161823; --primary: #5865F2; --accent: #FF00E6;
            --gold: #FFD700; --text: #eee; --text-dim: #888;
            --font: 'Segoe UI', Roboto, sans-serif;
            --mobile-sidebar-width: 280px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: var(--font); }
        body { background: var(--bg); color: var(--text); height: 100vh; overflow: hidden; display: flex; flex-direction: column; }
        
        /* Utils */
        .btn { padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; transition: 0.2s; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-gold { background: var(--gold); color: black; }
        .btn-danger { background: #ff4757; color: white; }
        .btn-dark { background: #333; color: white; }
        .btn:hover { filter: brightness(1.2); }
        input, select, textarea { background: #222; border: 1px solid #444; color: white; padding: 10px; border-radius: 6px; outline: none; }
        
        /* Modals */
        .modal { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.8); z-index: 200; display: none; justify-content: center; align-items: center; }
        .modal.open { display: flex; }
        .modal-content { background: var(--panel); padding: 25px; border-radius: 12px; width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 0 20px rgba(0,0,0,0.5); }

        /* AUTH SCREEN */
        #auth-screen { position: fixed; inset: 0; background: var(--bg); z-index: 1000; display: flex; justify-content: center; align-items: center; }
        .auth-box { background: var(--panel); padding: 40px; border-radius: 16px; width: 350px; text-align: center; border: 1px solid #333; box-shadow: 0 0 50px rgba(88, 101, 242, 0.2); }
        .auth-form { display: flex; flex-direction: column; gap: 15px; }

        /* APP LAYOUT - PC Default */
        #app-screen { display: none; height: 100%; grid-template-columns: 250px 1fr 300px; }
        
        /* Left Sidebar: Navigation & Chats */
        .sidebar-left { background: var(--panel); border-right: 1px solid #222; display: flex; flex-direction: column; overflow-y: hidden; }
        .sidebar-menu { padding: 20px 0; }
        .menu-btn { padding: 12px 20px; cursor: pointer; display: flex; align-items: center; gap: 10px; transition: background 0.1s; }
        .menu-btn.active, .menu-btn:hover { background: rgba(88, 101, 242, 0.2); }
        .chat-list-container { padding: 10px 0; flex: 1; overflow-y: auto; }
        .chat-list-container h4 { padding: 10px 20px; color: var(--text-dim); font-size: 12px; }
        .chat-item { padding: 8px 20px; cursor: pointer; border-left: 3px solid transparent; }
        .chat-item.active { background: rgba(88, 101, 242, 0.3); border-left: 3px solid var(--primary); }
        .exit-container { margin-top: auto; padding: 10px 0; border-top: 1px solid #222; }

        /* Chat */
        .chat-area { display: flex; flex-direction: column; background: radial-gradient(circle at center, #1a1c29 0%, #090a10 100%); position: relative; }
        .chat-header { padding: 15px 20px; border-bottom: 1px solid #222; display: flex; justify-content: space-between; align-items: center; background: rgba(22, 24, 35, 0.95); z-index: 10; }
        .messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; }
        .input-bar { padding: 10px 20px; background: var(--panel); display: flex; gap: 10px; border-top: 1px solid #222; position: sticky; bottom: 0; z-index: 5; }
        .input-bar input { flex: 1; }
        
        /* Message Bubbles */
        .msg { max-width: 80%; padding: 10px 14px; border-radius: 12px; background: #2f3136; position: relative; font-size: 14px; cursor: pointer; }
        .msg-header { font-size: 11px; margin-bottom: 4px; display: flex; align-items: center; gap: 5px; color: var(--text-dim); }
        .msg.mine { align-self: flex-end; background: var(--primary); }
        .msg.admin { border: 1px solid var(--accent); background: #2a0a2a; } 
        .u-name.premium { color: var(--gold); text-shadow: 0 0 5px rgba(255,215,0,0.5); }
        
        /* Message Highlight & Edit */
        .msg.highlight { 
            border: 2px solid var(--accent); 
            box-shadow: 0 0 10px var(--accent);
            animation: highlight-pulse 2s infinite alternate;
        }
        @keyframes highlight-pulse {
            from { opacity: 1; }
            to { opacity: 0.8; }
        }
        .msg-edited { font-size: 9px; color: #777; margin-left: 5px; }

        /* Right Profile */
        .profile { background: var(--panel); border-left: 1px solid #222; padding: 20px; display: flex; flex-direction: column; }
        .highlight-status { font-size: 11px; color: var(--accent); margin-top: 5px; font-weight: bold; }
        
        /* --- СТИЛЬ: ПОЛОСА АВАТАРА (Stripes/Bars) --- */
        .avatar-box { 
            position: relative; 
            width: 100px; 
            height: 100px; 
            margin: 0 auto 15px; 
            border-radius: 50%;
            overflow: hidden; 
        }
        .avatar-img { 
            width: 100%; 
            height: 100%; 
            border-radius: 50%; 
            display: block; 
            position: relative;
            z-index: 10;
        }
        .frame-overlay { 
            position: absolute; 
            top: 0; 
            left: 0; 
            width: 8px; 
            height: 100%;
            border-radius: 0;
            pointer-events: none; 
            transition: 0.3s; 
            z-index: 5;
            background: transparent;
        }
        /* --- КОНЕЦ СТИЛЯ --- */

        /* Context Menu */
        #msg-context { position: absolute; background: #333; border: 1px solid #444; border-radius: 6px; box-shadow: 0 5px 15px rgba(0,0,0,0.5); z-index: 100; display: none; flex-direction: column; min-width: 170px; }
        #msg-context div { padding: 8px 12px; cursor: pointer; font-size: 13px; }
        #msg-context div:hover { background: var(--primary); }

        /* Search Overlay */
        #search-results-overlay { 
            position: absolute; 
            inset: 0; 
            background: rgba(0,0,0,0.9); 
            z-index: 100; 
            padding: 20px; 
            overflow-y: auto; 
            display: none; 
        }
        .search-result-item { 
            padding: 10px; 
            border-bottom: 1px solid #333; 
            cursor: pointer; 
        }
        .search-result-item:hover { background: #333; }
        .search-result-type { font-size: 11px; color: #888; margin-left: 10px; }
        .search-result-info { display: flex; justify-content: space-between; align-items: center; }
        
        /* Shop/Inventory/Market Styles */
        .shop-tabs { display: flex; margin-bottom: 15px; }
        .shop-tab { padding: 10px 15px; cursor: pointer; border-bottom: 2px solid transparent; transition: border-bottom 0.2s; color: var(--text-dim); }
        .shop-tab.active { color: var(--text); border-bottom: 2px solid var(--primary); }
        .shop-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; max-height: 60vh; overflow-y: auto; padding: 5px; }
        .item-card { background: #222; padding: 10px; border-radius: 8px; text-align: center; border: 1px solid #333; }
        .item-card img { width: 80px; height: 80px; border-radius: 4px; object-fit: cover; margin-bottom: 8px; }
        .item-rarity-common { color: #888; }
        .item-rarity-epic { color: var(--primary); }
        .item-rarity-legendary { color: var(--gold); }
        .inventory-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; max-height: 60vh; overflow-y: auto; padding: 5px; }

        /* Mobile adaptation remains the same */
        @media (max-width: 900px) {
            #app-screen { grid-template-columns: 1fr; }
            .sidebar-left, .profile {
                position: fixed; top: 0; height: 100%; z-index: 20; transition: transform 0.3s ease-in-out; box-shadow: 0 0 20px rgba(0,0,0,0.5);
            }
            .sidebar-left { width: var(--mobile-sidebar-width); transform: translateX(-100%); }
            .sidebar-left.open { transform: translateX(0); }
            .profile { width: 300px; right: 0; transform: translateX(100%); border-left: none; }
            .profile.open { transform: translateX(0); }
            .mobile-toggle { display: block !important; margin-right: 10px; }
        }
        .mobile-toggle { display: none; background: transparent; border: none; color: white; font-size: 18px; }

    </style>
</head>
<body>

    <div id="auth-screen" style="display:<?php echo isset($_SESSION['current_user']) ? 'none' : 'flex'; ?>">
        <div class="auth-box">
            <h1>PubTalk v9.5 (JSON/API Fix)</h1>
            <div class="auth-form">
                <input type="text" id="auth-user" placeholder="Логин (PublicCat для админа)">
                <input type="password" id="auth-pass" placeholder="Пароль">
                <button class="btn btn-primary" onclick="auth('login')">Войти</button>
                <button class="btn" style="background:#333; color:#aaa" onclick="auth('register')">Создать аккаунт</button>
            </div>
            <div style="margin-top:20px; font-size:11px; color:#555">
                **ВАЖНО:** Создайте папку **`data/`** и дайте ей права на запись.
            </div>
        </div>
    </div>

    <div id="app-screen" style="display:<?php echo isset($_SESSION['current_user']) ? 'grid' : 'none'; ?>">
        
        <div class="sidebar-left" id="sidebar-left">
            <div class="sidebar-menu">
                <h2 style="color:var(--primary); margin:0 20px 10px;">PubTalk</h2>
                <div class="menu-btn" onclick="openModal('create-chat-modal')">➕ Создать Чат</div>
                <div class="menu-btn" onclick="openModal('search-modal')">🔍 Поиск (Юзеры/Чаты)</div> 
                
                <div class="menu-btn" onclick="openFavoritesModal()">⭐ Избранное</div> 

                <div class="menu-btn" onclick="openShop('frames')">🛒 Магазин (Shop)</div>
                <div class="menu-btn" onclick="openShop('market')">💸 Маркет (P2P)</div>
                <div class="menu-btn" onclick="openShop('inventory')">📦 Инвентарь</div>
            </div>

            <div class="chat-list-container">
                <h4>ЧАТЫ (<span id="chat-count">0</span>)</h4>
                <div id="chat-list"></div>
            </div>
            <div class="exit-container"> 
                <div class="menu-btn" style="color:#ff4757" onclick="logout()">🚪 Выход</div>
            </div>
        </div>

        <div class="chat-area">
            <div class="chat-header">
                <button class="mobile-toggle" onclick="toggleSidebar('sidebar-left')">☰</button>
                <h3 id="chat-title">Площадь | Global</h3>
                <button class="mobile-toggle" onclick="toggleSidebar('profile')">👤</button>
            </div>
            <div class="messages" id="msg-list"></div>
            <div class="input-bar"> 
                <input type="text" id="msg-text" placeholder="Напишите сообщение..." onkeypress="if(event.key==='Enter') sendMsg()">
                <button class="btn btn-primary" onclick="sendMsg()">➤</button>
            </div>
        </div>

        <div class="profile" id="profile">
            <div class="avatar-box">
                <img src="https://api.dicebear.com/7.x/bottts/svg?seed=0" class="avatar-img" id="my-avatar">
                <div class="frame-overlay" id="my-frame"></div>
            </div>
            <div style="text-align:center;">
                <h3 id="u-name">...</h3>
                <div id="u-role" style="font-size:11px; color:var(--accent); font-weight:bold;"></div>
            </div>

            <div class="stats">
                <div class="stat-row"><span>Уровень:</span> <b id="u-lvl">0</b></div>
                <div class="stat-row"><span>XP:</span> <span id="u-xp">0/100</span></div>
                <div class="stat-row"><span>Сообщ. (Всего):</span> <b id="u-msg-count">0</b></div>
                <hr style="border:0; border-top:1px solid #444; margin:5px 0;">
                <div class="stat-row"><span>Звезды:</span> <b style="color:var(--gold)" id="u-stars">0 ⭐</b></div>
                <div class="stat-row"><span>Токены:</span> <b style="color:#aaa" id="u-tokens">0 🔘</b></div>
            </div>
            
            <div id="highlight-status" style="margin-top: 10px; text-align: center;"></div> 

            <button class="btn btn-gold" style="margin-top: 15px;" onclick="buyPremium()">👑 Купить Premium</button>
            <button class="btn btn-accent" style="margin-top: 5px; background: var(--accent); color: white;" onclick="openModal('highlight-modal')">✨ Выделение СМС</button>
            <button class="btn btn-dark" style="margin-top: 5px;" onclick="openEditProfileModal()">🛠️ Редактировать Профиль</button>
            
            <div class="achievements-list">
                <h4 style="color:#76FF03; font-size:12px; margin-bottom: 5px; margin-top: 15px;">🏆 Мои Титулы</h4>
                <div id="my-achievements">Нет титулов.</div>
            </div>

            <div class="admin-panel" id="admin-controls" style="margin-top: 20px; display:none;">
                <h3>⚡ Панель PublicCat</h3>
                <div class="admin-actions" style="display: flex; flex-direction: column; gap: 5px;">
                    <button class="btn btn-gold" style="font-size:10px" onclick="adminAction('add_money')">+10k Валюты</button>
                    <button class="btn btn-danger" style="font-size:10px" onclick="adminAction('clear_chat')">Очистить Чат</button>
                    <button class="btn btn-dark" style="font-size:10px" onclick="openModal('admin-add-item-modal')">➕ Товар в Shop</button>
                    <button class="btn" style="background:#fff; color:#000; font-size:10px;" onclick="openAdminSystemMsgModal()">📣 Системное Смс</button>
                </div>
            </div>
        </div>

        <div id="msg-context">
            </div>
    </div>

    <div id="search-modal" class="modal">
        <div class="modal-content">
            <h2>🔍 Глобальный Поиск</h2>
            <div class="auth-form">
                <input type="text" id="search-query" placeholder="Введите имя пользователя, группы или канала..." onkeyup="searchApp(this.value)">
                <div id="search-results-container" style="max-height: 40vh; overflow-y: auto; margin-top: 10px;">
                    <p style="color:#888;">Результаты появятся здесь.</p>
                </div>
            </div>
        </div>
    </div>

    <div id="highlight-modal" class="modal">
        <div class="modal-content">
            <h2>✨ Выделение СМС (Подписка)</h2>
            <p style="margin-bottom:15px;">Ваши сообщения будут подсвечены ярким цветом (<span style="color:var(--accent);">#FF00E6</span>) в чате.</p>
            <div class="auth-form">
                <button class="btn" style="background:var(--accent); color:white;" onclick="buyHighlight('24h')">24 часа (15 ⭐)</button>
                <button class="btn" style="background:var(--accent); color:white;" onclick="buyHighlight('7d')">7 дней (90 ⭐)</button>
            </div>
            <div id="highlight-info" style="margin-top: 20px; text-align: center;"></div>
        </div>
    </div>

    <div id="shop-modal" class="modal">
        <div class="modal-content">
            <h2 id="modal-title">PublicCat Shop</h2>
            <div class="shop-tabs" id="shop-tabs">
                <div class="shop-tab active" data-view="frames" onclick="switchShopView('frames')">🎨 Полосы</div>
                <div class="shop-tab" data-view="nfts" onclick="switchShopView('nfts')">🖼️ NFT</div>
                <div class="shop-tab" data-view="gifts" onclick="switchShopView('gifts')">🎁 Подарки</div>
                <div class="shop-tab" data-view="inventory" onclick="switchShopView('inventory')">📦 Инвентарь</div>
                <div class="shop-tab" data-view="market" onclick="switchShopView('market')">💸 Маркет (P2P)</div>
            </div>
            <div id="modal-subtitle" style="font-size:12px; color:#aaa; margin-top:-10px; margin-bottom:10px;"></div>

            <div id="shop-content-frames" class="shop-grid"></div>
            <div id="shop-content-nfts" class="shop-grid" style="display:none;"></div>
            <div id="shop-content-gifts" class="shop-grid" style="display:none;"></div>
            <div id="market-container" class="shop-grid" style="display:none;"></div>
            <div id="inventory-container" class="inventory-grid" style="display:none;"></div>
        </div>
    </div>

    <div id="profile-modal" class="modal">
        <div class="modal-content">
            <h2 style="text-align:center;" id="p-u-name">Профиль</h2>
            <div class="avatar-box" style="margin-top:20px;">
                <img src="" class="avatar-img" id="p-avatar">
                <div class="frame-overlay" id="p-frame"></div>
            </div>
            <div class="stats" style="margin-top:20px;">
                <div class="stat-row"><span>Роль:</span> <b id="p-role"></b></div>
                <div class="stat-row"><span>Статус:</span> <b id="p-status"></b></div>
                <div class="stat-row"><span>Уровень:</span> <b id="p-lvl"></b></div>
                <div class="stat-row"><span>Сообщ. (Всего):</span> <b id="p-msg-count"></b></div>
                <hr style="border:0; border-top:1px solid #444; margin:5px 0;">
                <div class="stat-row"><span>Звезды:</span> <b id="p-stars"></b></div>
                <div class="stat-row"><span>Слава:</span> <b id="p-fame"></b></div>
            </div>
            
            <div style="margin-top:15px; display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <button class="btn btn-primary" id="gift-stars-btn" onclick="openGiftStarsModal(document.getElementById('p-u-name').innerText)">🎁 Подарить ⭐</button>
                <button class="btn btn-dark" id="admin-xp-btn" onclick="openAdminXpModal(document.getElementById('p-u-name').innerText)" style="display:none;">🛠️ Изменить XP</button>
            </div>
            <button class="btn btn-primary" id="start-dm-btn" style="margin-top: 10px;" onclick="startDmChat(document.getElementById('p-u-name').innerText)">✉️ Написать ЛС</button>
            
            <div class="achievements-list">
                <h4 style="color:#76FF03; font-size:12px; margin-bottom: 5px; margin-top: 15px;">🏆 Титулы</h4>
                <div id="p-achievements">Нет титулов.</div>
            </div>
        </div>
    </div>
    
    <div id="edit-profile-modal" class="modal"> 
        <div class="modal-content">
            <h2>🛠️ Редактирование Профиля</h2>
            <div class="auth-form" style="margin-top:20px;">
                <input type="password" id="edit-password" placeholder="Новый пароль (оставьте пустым, чтобы не менять)">
                <label style="text-align:left; font-size:12px; margin-top:10px; color:#aaa;">Выбрать Полосу (из инвентаря):</label>
                <select id="edit-frame-select" style="padding:10px; background:#222; color:white; border-radius:6px;"></select>
                <button class="btn btn-primary" onclick="updateProfile()">Сохранить Изменения</button>
            </div>
        </div>
    </div>
    
    <div id="favorites-modal" class="modal">
        <div class="modal-content">
            <h2>⭐ Мое Избранное (Приватный фид)</h2>
            <div id="favorites-list" class="messages" style="max-height: 60vh; overflow-y: auto; padding: 10px; background: #00000030;">
                <p style="color:#aaa; text-align:center;">Ваши избранные сообщения будут здесь.</p>
            </div>
        </div>
    </div>

    <div id="create-chat-modal" class="modal">
        <div class="modal-content">
            <h2>Создать Новый Чат</h2>
            <div class="auth-form" style="margin-top:20px;">
                <input type="text" id="new-chat-name" placeholder="Название (например, 'Торговый Квартал')">
                <select id="new-chat-type" style="padding:10px; background:#222; color:white; border-radius:6px;">
                    <option value="group">Группа (все могут писать)</option>
                    <option value="channel">Канал (только создатель может писать)</option>
                </select>
                <button class="btn btn-primary" onclick="createChat()">Создать</button>
            </div>
        </div>
    </div>

    <div id="gift-stars-modal" class="modal">
        <div class="modal-content">
            <h2>Подарить Звезды</h2>
            <p style="margin-bottom:15px;">Текущий баланс: <span id="my-stars-balance">0 ⭐</span></p>
            <div class="auth-form" style="margin-top:10px;">
                <input type="text" id="gift-stars-target" readonly style="font-weight:bold; color:var(--primary);">
                <input type="number" id="gift-stars-amount" placeholder="Сумма Звезд (⭐)" min="1">
                <button class="btn btn-gold" onclick="giftStars()">Отправить Подарок ⭐</button>
            </div>
        </div>
    </div>

    <div id="admin-xp-modal" class="modal">
        <div class="modal-content">
            <h2>🛠️ Изменить Прогрессию</h2>
            <div class="auth-form" style="margin-top:10px;">
                <input type="text" id="admin-xp-target" readonly style="font-weight:bold; color:var(--danger);">
                <input type="number" id="admin-xp-level" placeholder="Новый Уровень" min="1">
                <input type="number" id="admin-xp-xp" placeholder="Новый XP" min="0">
                <button class="btn btn-danger" onclick="adminSetXp()">Сохранить изменения</button>
            </div>
        </div>
    </div>

    <div id="admin-add-item-modal" class="modal">
        <div class="modal-content">
            <h2>➕ Добавить Товар в Shop</h2>
            <div class="auth-form" style="margin-top:10px;">
                <select id="admin-item-type">
                    <option value="nft">NFT</option>
                    <option value="gift">Подарок</option>
                </select>
                <input type="text" id="admin-item-name" placeholder="Название товара">
                <input type="number" id="admin-item-price" placeholder="Цена в Звездах (⭐)" min="1">
                <input type="text" id="admin-item-image" placeholder="URL/Путь к изображению (400x400)">
                <button class="btn btn-primary" onclick="adminAddShopItem()">Добавить в Магазин</button>
            </div>
        </div>
    </div>


    <script>
        // --- UTF-8 Base64 Helpers for Cyrillic Characters ---
        function utf8_to_b64(str) {
            try { return btoa(unescape(encodeURIComponent(str))); }
            catch (e) { console.error("UTF-8 encoding error:", e); return btoa(str); }
        }

        function b64_to_utf8(str) {
            try { return decodeURIComponent(escape(atob(str))); }
            catch (e) { console.error("Base64 decoding error:", e); return "⛔ Невозможно расшифровать сообщение (ошибка кодировки)."; }
        }
        // --------------------------------------------------

        let currentUser = null;
        let currentChatId = 'global_1';
        let lastMsgCount = 0;
        let activeQuote = null;
        let achievementsMeta = {};
        let currentShopView = 'frames';
        let allChats = {};
        let allShopItems = []; // Contains frames, NFTs, Gifts from server
        let allMarketItems = []; // Current market listings

        let messageOffset = 0; // New: for pagination
        const messageLimit = 50; // New: messages per fetch
        let totalChatMessages = 0; // New: total messages in current chat
        let isFetchingMessages = false; // New: prevent multiple fetches


        const allFrameIds = [
            'frame_0', 'frame_1', 'frame_2', 'frame_3', 'frame_4', 'frame_5', 'frame_6', 'frame_7', 'frame_8', 'frame_9',
            'frame_ach_veteran', 'frame_ach_chatter', 'frame_ach_millionaire'
        ];

        document.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('app-screen').style.display === 'grid') {
                startApp();
            }
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) modal.classList.remove('open');
                });
            });
            document.addEventListener('click', (e) => {
                const contextMenu = document.getElementById('msg-context');
                if (contextMenu.style.display === 'flex' && !contextMenu.contains(e.target)) {
                    contextMenu.style.display = 'none';
                }
            });

            // New: Scroll event listener for chat messages
            document.getElementById('msg-list').addEventListener('scroll', async function() {
                if (this.scrollTop === 0 && !isFetchingMessages && messageOffset + messageLimit < totalChatMessages) {
                    messageOffset += messageLimit;
                    await fetchState(false, true); // Fetch more, do not full render, indicate loadMore
                }
            });
        });

        async function api(data) {
            const res = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            // Critical fix for JSON SyntaxError:
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("API Error: Received non-JSON response:", text);
                alert("Ошибка связи с сервером! Неверный формат ответа. Проверьте консоль.");
                return { status: 'error', msg: 'JSON Parse Error' };
            }
        }

        // 1. Auth & App State
        async function auth(action) {
            const u = document.getElementById('auth-user').value;
            const p = document.getElementById('auth-pass').value;
            if(!u || !p) return alert('Введите данные');
            const res = await api({ action, username: u, password: p });
            if(res.status === 'ok') {
                document.getElementById('auth-screen').style.display = 'none';
                document.getElementById('app-screen').style.display = 'grid';
                currentUser = res.user;
                startApp();
            } else alert(res.msg);
        }

        async function logout() { await api({ action: 'logout' }); location.reload(); }
        function startApp() { fetchState(true); setInterval(() => fetchState(false), 2000); }

        function formatRemainingTime(seconds) {
             if (seconds <= 0) return 'Истекло';
             const days = Math.floor(seconds / (3600 * 24));
             const hours = Math.floor((seconds % (3600 * 24)) / 3600);
             const minutes = Math.floor((seconds % 3600) / 60);

             let result = '';
             if (days > 0) result += `${days} д `;
             if (hours > 0) result += `${hours} ч `;
             if (minutes > 0 && days === 0) result += `${minutes} м`;

             return result.trim() || '< 1 мин';
        }

        async function fetchState(fullRender = true, loadMore = false) {
            if (isFetchingMessages) return;
            isFetchingMessages = true;

            const data = await api({
                action: 'fetch_state',
                chat_id: currentChatId,
                offset: messageOffset, // New
                limit: messageLimit // New
            });
            if(data.status === 'auth_error') {
                 if(document.getElementById('app-screen').style.display === 'grid') location.reload();
                 return;
            }

            currentUser = data.user;
            allChats = data.chats;
            allShopItems = data.shop;
            allMarketItems = data.market;
            currentChatId = data.current_chat_id;
            achievementsMeta = data.achievements_meta;
            totalChatMessages = data.total_messages; // New

             renderProfile(data.user);
             renderChatList(data.chats);
             renderShop(allShopItems, allMarketItems, data.user.inventory); // Always update shop/market/inventory
            renderChat(data.messages, loadMore); // Pass loadMore
            document.getElementById('chat-title').innerText = data.chats[currentChatId].name;

            if(currentUser.role === 'admin') {
                 document.getElementById('admin-controls').style.display = 'block';
            } else {
                 document.getElementById('admin-controls').style.display = 'none';
            }
            isFetchingMessages = false;
        }

        // 2. Chat & UI Renderers

        function renderChatList(chats) {
            const list = document.getElementById('chat-list');
            list.innerHTML = '';
            const chatIds = Object.keys(chats);

            chatIds.forEach(id => {
                const chat = chats[id];
                const isActive = id === currentChatId;
                const div = document.createElement('div');
                div.className = `chat-item ${isActive ? 'active' : ''}`;
                div.setAttribute('data-chat-id', id);
                div.onclick = () => switchChat(id);

                let icon = chat.type === 'channel' ? '📣' : (chat.type === 'group' ? '👥' : '🔒');
                if (id === 'default_dm') icon = '🏠';
                // New: show members in DM chat name for clarity
                if (chat.type === 'dm' && chat.members.length === 2) {
                    const otherMember = chat.members.find(member => member !== currentUser.username);
                    div.innerHTML = `${icon} ${otherMember}`;
                } else {
                    div.innerHTML = `${icon} ${chat.name}`;
                }

                list.appendChild(div);
            });

            document.getElementById('chat-count').innerText = chatIds.length;
        }

        function switchChat(newChatId) {
            if (newChatId !== currentChatId) {
                currentChatId = newChatId;
                messageOffset = 0; // Reset offset for new chat
                lastMsgCount = 0;
                if (window.innerWidth <= 900) {
                    toggleSidebar('sidebar-left', false);
                }
                fetchState(true);
            }
        }

        function renderChat(msgs, loadMore = false) {
            const list = document.getElementById('msg-list');
            const shouldScrollToBottom = list.scrollTop + list.clientHeight >= list.scrollHeight - 20 || msgs.length === 0 || !loadMore; // Scrolled to bottom or initial load/new messages

            if (!loadMore) { // New chat or new messages, clear and re-render
                list.innerHTML = '';
            }

            const highlightUntil = currentUser.highlight_until || 0;
            const now = Math.floor(Date.now() / 1000);

            const fragment = document.createDocumentFragment();
            msgs.forEach(m => {
                const isMe = m.author === currentUser.username;
                const isHighlightActive = highlightUntil > now && m.author === currentUser.username;

                const div = document.createElement('div');
                div.className = `msg ${isMe ? 'mine' : ''} ${m.role === 'admin' ? 'admin' : ''} ${m.is_premium ? 'premium' : ''} ${isHighlightActive ? 'highlight' : ''}`;
                div.setAttribute('data-msg', JSON.stringify(m));
                div.setAttribute('data-msg-id', m.id);

                let decryptedText = '';
                // Conditional decryption for DM/Group chats
                const currentChat = allChats[currentChatId];
                if (currentChat && (currentChat.type === 'dm' || currentChat.type === 'group')) {
                    if (currentChat.members.includes(currentUser.username)) { // Only decrypt if member
                        decryptedText = b64_to_utf8(m.encrypted_text);
                    } else {
                        decryptedText = "🔒 Это приватное сообщение."; // Show locked message if not a member
                    }
                } else if (m.encrypted_text) { // For non-private chats, if encrypted, decrypt
                    decryptedText = b64_to_utf8(m.encrypted_text);
                } else { // Fallback for unencrypted public messages
                    decryptedText = m.text || '';
                }

                let badges = '';
                if(m.is_premium) badges += '<span class="crown">👑</span> ';
                if(m.role === 'admin') badges += '🛡️ ';

                let editedTag = m.edited ? '<span class="msg-edited">(ред.)</span>' : '';

                let headerStyle = '';
                if(m.frame) {
                    const color = getColor(m.frame);
                    headerStyle = `style="color:${color}; text-shadow:0 0 5px ${color}80;"`;
                }

                let quoteHtml = '';
                if (m.quote) {
                    quoteHtml = `<div style="padding: 5px; margin-bottom: 5px; border-left: 3px solid ${isMe ? 'white' : 'var(--primary)'}; opacity: 0.7; font-style: italic; font-size: 12px; background: rgba(0,0,0,0.2);">Цитата: ${m.quote.author}: ${m.quote.text.substring(0, 40)}...</div>`;
                }

                div.innerHTML = `
                    <div class="msg-header">
                        ${badges} <b ${headerStyle} onclick="openProfileModal('${m.author}')">${m.author}</b> • ${m.time} ${editedTag}
                    </div>
                    ${quoteHtml}
                    <div>${decryptedText}</div>
                `;

                div.addEventListener('contextmenu', (e) => {
                    e.preventDefault();
                    showContextMenu(e, m);
                });
                // Fix for mobile/single-click context menu:
                div.addEventListener('click', (e) => {
                    if (e.target.tagName !== 'B' && !e.target.closest('.msg-header')) showContextMenu(e, m);
                });

                if (loadMore) {
                    list.insertBefore(div, list.firstChild); // Insert at the top for pagination
                } else {
                    fragment.appendChild(div);
                }
            });

            if (!loadMore) {
                list.appendChild(fragment);
            }

            if (shouldScrollToBottom || !loadMore) {
                list.scrollTop = list.scrollHeight;
            } else if (loadMore) {
                // Maintain scroll position when loading more messages
                // This is a simplified approach, a more robust solution would involve tracking specific message elements
                 list.scrollTop = list.scrollHeight - (list.oldScrollHeight || list.scrollHeight);
            }
            list.oldScrollHeight = list.scrollHeight; // Store for next loadMore
            lastMsgCount = msgs.length;
        }

        function renderProfile(u) {
             document.getElementById('my-avatar').src = `https://api.dicebear.com/7.x/bottts/svg?seed=${u.username}`;
             document.getElementById('u-name').innerText = u.username;
             document.getElementById('u-name').className = u.is_premium ? 'u-name premium' : 'u-name';

             let badges = u.role === 'admin' ? '🛡️ ADMIN (PublicCat)' : 'Гражданин';
             if(u.is_premium) badges = '👑 PREMIUM | ' + badges;
             document.getElementById('u-role').innerText = badges;

             document.getElementById('u-lvl').innerText = u.level;
             document.getElementById('u-xp').innerText = Math.floor(u.xp) + ' / ' + Math.floor(u.max_xp);
             document.getElementById('u-stars').innerText = u.wallet.stars + ' ⭐';
             document.getElementById('u-tokens').innerText = u.wallet.tokens + ' 🔘';
             document.getElementById('u-msg-count').innerText = u.messages_sent;

             const frameEl = document.getElementById('my-frame');
             frameEl.className = 'frame-overlay';

             if(u.equipped_frame) {
                 const color = getColor(u.equipped_frame);
                 frameEl.style.background = color;
                 frameEl.style.boxShadow = `2px 0 10px ${color}`;
             } else {
                 frameEl.style.background = 'transparent';
                 frameEl.style.boxShadow = 'none';
             }

             const achListEl = document.getElementById('my-achievements');
             if (u.achievements && u.achievements.length > 0) {
                 achListEl.innerHTML = u.achievements.map(id =>
                     `<div class="achievement-item">✅ ${achievementsMeta[id]?.title || id}</div>`
                 ).join('');
             } else {
                  achListEl.innerHTML = 'Нет титулов.';
             }

             const highlightUntil = u.highlight_until || 0;
             const now = Math.floor(Date.now() / 1000);
             const highlightStatusEl = document.getElementById('highlight-status');

             if (highlightUntil > now) {
                  const remainingTime = formatRemainingTime(highlightUntil - now);
                  highlightStatusEl.innerHTML = `<div class="highlight-status">✨ Выделение активно: ${remainingTime}</div>`;
                  document.getElementById('highlight-info').innerHTML = `Активно до: ${new Date(highlightUntil * 1000).toLocaleString()}`;
             } else {
                  highlightStatusEl.innerHTML = '<div class="highlight-status" style="color:#888;">Выделение неактивно.</div>';
                  document.getElementById('highlight-info').innerHTML = 'Приобретите, чтобы выделить ваши сообщения!';
             }

             if(u.role === 'admin') document.getElementById('admin-controls').style.display = 'block';
             else document.getElementById('admin-controls').style.display = 'none';
        }

        // 3. Search Implementation
        async function searchApp(query) {
             const resultsEl = document.getElementById('search-results-container');
             resultsEl.innerHTML = '<p style="color:#aaa; text-align:center;">Загрузка...</p>';

             if (query.length < 2) {
                 resultsEl.innerHTML = '<p style="color:#888;">Введите минимум 2 символа для поиска.</p>';
                 return;
             }

             const res = await api({ action: 'search', query });

             if (res.status === 'ok') {
                 renderSearchResults(res.results, resultsEl);
             } else {
                 resultsEl.innerHTML = `<p style="color:#f00;">Ошибка поиска: ${res.msg}</p>`;
             }
        }

        function renderSearchResults(results, resultsEl) {
             resultsEl.innerHTML = '';
             let hasResults = false;

             // Users
             results.users.forEach(u => {
                 hasResults = true;
                 const div = document.createElement('div');
                 div.className = 'search-result-item';
                 div.onclick = () => { openProfileModal(u.username); openModal('search-modal'); }; // Close search after click
                 div.innerHTML = `
                    <div class="search-result-info">
                        <strong>👤 ${u.username}</strong>
                        <span class="search-result-type">Пользователь</span>
                    </div>
                 `;
                // Add DM button
                if (u.username !== currentUser.username) {
                    const dmButton = document.createElement('button');
                    dmButton.className = 'btn btn-primary';
                    dmButton.style.fontSize = '12px';
                    dmButton.style.padding = '5px 10px';
                    dmButton.style.marginTop = '5px';
                    dmButton.innerText = '✉️ Написать ЛС';
                    dmButton.onclick = (e) => {
                        e.stopPropagation(); // Prevent opening profile modal
                        startDmChat(u.username);
                        openModal('search-modal'); // Close search modal
                    };
                    div.appendChild(dmButton);
                }
                 resultsEl.appendChild(div);
             });

             // Chats
             results.chats.forEach(c => {
                 hasResults = true;
                 const div = document.createElement('div');
                 div.className = 'search-result-item';
                 div.onclick = () => { switchChat(c.id); openModal('search-modal'); };
                 const icon = c.type === 'channel' ? '📣' : '👥';
                 div.innerHTML = `
                     <div class="search-result-info">
                        <strong>${icon} ${c.name}</strong>
                        <span class="search-result-type">${c.type === 'channel' ? 'Канал' : 'Группа'}</span>
                    </div>
                 `;
                 resultsEl.appendChild(div);
             });

             if (!hasResults) {
                 resultsEl.innerHTML = '<p style="color:#888;">Ничего не найдено.</p>';
             }
        }

        // 4. Message Actions

        function showContextMenu(e, msg) {
            e.stopPropagation();
            const menu = document.getElementById('msg-context');
            menu.style.display = 'flex';

            const x = e.pageX + 180 > window.innerWidth ? e.pageX - 180 : e.pageX;
            const y = e.pageY + 100 > window.innerHeight ? e.pageY - 100 : e.pageY;

            menu.style.left = x + 'px';
            menu.style.top = y + 'px';
            activeQuote = msg;

            menu.innerHTML = '';

            if (msg.author === currentUser.username) {
                menu.innerHTML += `<div onclick="editMsgPrompt()">✏️ Редактировать</div>`;
                menu.innerHTML += `<div onclick="deleteMsg(activeQuote.id)">❌ Удалить</div><hr style="border:0; border-top:1px solid #444;">`;
            }

            menu.innerHTML += `
                <div onclick="quoteMsg()">📝 Цитировать</div>
                <div onclick="openProfileModal(activeQuote.author)">👤 Профиль автора</div>
                <div onclick="openGiftStarsModal(activeQuote.author)">🎁 Подарить ⭐</div>
                <div onclick="addToFavorites(activeQuote)">⭐ Добавить в Избранное</div>
            `;
        }

        function editMsgPrompt() {
            if (!activeQuote || !activeQuote.encrypted_text) {
                alert("Невозможно редактировать: нет зашифрованного текста.");
                return;
            }
            const currentText = b64_to_utf8(activeQuote.encrypted_text);
            const newText = prompt("Отредактируйте сообщение:", currentText);

            if (newText && newText.trim() !== currentText.trim()) {
                editMessage(activeQuote.id, newText);
            }
            document.getElementById('msg-context').style.display = 'none';
        }

        async function editMessage(msgId, newText) {
             const res = await api({
                 action: 'edit_message',
                 message_id: msgId,
                 new_text: newText
             });
             alert(res.msg);
             if (res.status === 'ok') fetchState();
        }

        async function deleteMsg(msgId) {
            if (!confirm("Вы уверены, что хотите удалить это сообщение?")) return;
            const res = await api({ action: 'delete_message', message_id: msgId });
            alert(res.msg);
            if (res.status === 'ok') fetchState();
        }

        function quoteMsg() {
             if (!activeQuote) return;
             const author = activeQuote.author;

             let text;
             if (activeQuote.encrypted_text) {
                  text = b64_to_utf8(activeQuote.encrypted_text);
             } else {
                  text = activeQuote.text || 'Не удалось получить текст.';
             }

             document.getElementById('msg-text').value = `"${text.substring(0, 40).replace(/"/g, '')}..." (c) ${author}: `;
             activeQuote = null;
             document.getElementById('msg-context').style.display = 'none';
        }

        async function addToFavorites(msg) {
             document.getElementById('msg-context').style.display = 'none';

            let text = msg.text || '';
            if (msg.encrypted_text) {
                 text = b64_to_utf8(msg.encrypted_text);
            }

            const res = await api({
                 action: 'add_to_favorites',
                 author: msg.author,
                 text: text,
                 time: msg.time,
                 chat_id: msg.chat_id
            });

            alert(res.msg);
            activeQuote = null;
            if (res.status === 'ok') fetchState(true);
        }

        // 5. Highlight Service
        async function buyHighlight(duration) {
            const res = await api({ action: 'buy_highlight', duration });
            alert(res.msg);
            if (res.status === 'ok') {
                openModal('highlight-modal');
                fetchState(true);
            }
        }

        // 6. Admin System Message Fix
        function openAdminSystemMsgModal() {
            const text = prompt("Введите системное сообщение для Global Chat:");
            if (text) {
                adminAction('system_msg', text);
            }
        }

        // 7. Core App Actions

        async function sendMsg() {
             const input = document.getElementById('msg-text');
             const txt = input.value;
             if(!txt) return;

             const encryptedText = utf8_to_b64(txt);

             const quoteMatch = txt.match(/"(.*?)\..." \(c\) (.*?): /);
             let quoteData = null;
             if (quoteMatch) {
                 quoteData = {
                     text: quoteMatch[1],
                     author: quoteMatch[2]
                 };
             }

             const res = await api({
                 action: 'send_message',
                 text: txt,
                 encrypted_text: encryptedText,
                 chat_id: currentChatId,
                 quote: quoteData
             });

             if (res.status === 'ok') { // Only reset offset and fetch state if message sent successfully
                 messageOffset = 0; // Reset offset after sending new message
                 fetchState();
             } else if (res.msg) {
                 alert(res.msg);
             } else {
                 alert('Ошибка отправки сообщения. Проверьте подключение или права на запись.');
             }
        }

        async function createChat() {
            const name = document.getElementById('new-chat-name').value;
            const type = document.getElementById('new-chat-type').value;

            if (name.length < 3) return alert('Название слишком короткое.');

            const res = await api({ action: 'create_chat', name, type });

            alert(res.msg || 'Чат создан!');

            if (res.status === 'ok') {
                document.getElementById('create-chat-modal').classList.remove('open');
                switchChat(res.chat_id);
            }
        }

        // New function to start a DM chat
        async function startDmChat(targetUsername) {
            const res = await api({ action: 'create_dm_chat', target_username: targetUsername });
            if (res.status === 'ok') {
                openModal('profile-modal'); // Close profile modal
                openModal('search-modal'); // Close search modal if open
                switchChat(res.chat_id); // Switch to the new/existing DM chat
            } else {
                alert(res.msg);
            }
        }

        async function buyPremium() {
             const res = await api({ action: 'buy_premium' });
             alert(res.msg);
             if (res.status === 'ok') fetchState(true);
        }

        async function adminAction(sub, text = '', chat_id = 'global_1') {
             const res = await api({ action: 'admin_action', sub_action: sub, text: text, chat_id: chat_id });
             if(res.msg) alert(res.msg);
             fetchState();
        }

        // 8. Profile Modals & Actions

        async function openProfileModal(username) {
             const res = await api({ action: 'get_profile', target_user: username });
             if (res.status !== 'ok') return alert(res.msg);

             const u = res.profile;
             document.getElementById('p-u-name').innerText = u.username;
             document.getElementById('p-avatar').src = `https://api.dicebear.com/7.x/bottts/svg?seed=${u.username}`;
             document.getElementById('p-role').innerText = u.role === 'admin' ? '🛡️ Администратор' : 'Гражданин';
             document.getElementById('p-status').innerText = u.is_premium ? '👑 Premium' : 'Обычный';
             document.getElementById('p-lvl').innerText = u.level;
             document.getElementById('p-msg-count').innerText = u.messages_sent;
             document.getElementById('p-stars').innerText = u.wallet.stars + ' ⭐';
             document.getElementById('p-tokens').innerText = u.wallet.tokens + ' 🔘';
             document.getElementById('p-fame').innerText = u.wallet.fame + ' 🏆';

             const frameEl = document.getElementById('p-frame');
             if(u.equipped_frame) {
                 const color = getColor(u.equipped_frame);
                 frameEl.style.background = color;
                 frameEl.style.boxShadow = `2px 0 10px ${color}`;
             } else {
                 frameEl.style.background = 'transparent';
                 frameEl.style.boxShadow = 'none';
             }

             const achListEl = document.getElementById('p-achievements');
             if (u.achievements && u.achievements.length > 0) {
                 achListEl.innerHTML = u.achievements.map(id =>
                     `<div class="achievement-item">✅ ${achievementsMeta[id]?.title || id}</div>`
                 ).join('');
             } else {
                 achListEl.innerHTML = 'Нет титулов.';
             }

             document.getElementById('gift-stars-btn').style.display = (u.username !== currentUser.username) ? 'block' : 'none';
             document.getElementById('admin-xp-btn').style.display = (currentUser.role === 'admin' && u.username !== currentUser.username) ? 'block' : 'none';
             // Update DM button display and functionality
             const startDmBtn = document.getElementById('start-dm-btn');
             if (u.username !== currentUser.username) {
                 startDmBtn.style.display = 'block';
                 startDmBtn.onclick = () => startDmChat(u.username);
             } else {
                 startDmBtn.style.display = 'none';
             }

             openModal('profile-modal');
        }

        function openEditProfileModal() {
            const select = document.getElementById('edit-frame-select');
            select.innerHTML = '<option value="">Нет (Снять полосу)</option>';

            // Filter frames from inventory
            const frameItems = currentUser.inventory.filter(id => allFrameIds.includes(id));

            frameItems.forEach(id => {
                 const name = allShopItems.find(i => i.id === id)?.name || achievementsMeta[id.replace('frame_','ach_')]?.title || id;
                 const option = document.createElement('option');
                 option.value = id;
                 option.innerText = name;
                 if (id === currentUser.equipped_frame) {
                      option.selected = true;
                 }
                 select.appendChild(option);
            });

            openModal('edit-profile-modal');
        }

        async function updateProfile() {
            const newPassword = document.getElementById('edit-password').value;
            const newFrame = document.getElementById('edit-frame-select').value;

            const res = await api({
                action: 'update_profile',
                new_password: newPassword,
                new_frame: newFrame
            });

            alert(res.msg);
            if (res.status === 'ok') {
                document.getElementById('edit-password').value = '';
                openModal('edit-profile-modal'); // Close modal
                fetchState(true);
            }
        }

        function openGiftStarsModal(targetUsername) {
            if (targetUsername === currentUser.username) {
                 return alert('Вы не можете подарить звезды себе.');
            }
            document.getElementById('my-stars-balance').innerText = currentUser.wallet.stars + ' ⭐';
            document.getElementById('gift-stars-target').value = targetUsername;
            document.getElementById('gift-stars-amount').value = '';
            openModal('gift-stars-modal');
        }

        async function giftStars() {
            const target = document.getElementById('gift-stars-target').value;
            const amount = parseInt(document.getElementById('gift-stars-amount').value);

            if (isNaN(amount) || amount <= 0) return alert('Введите корректную сумму.');

            const res = await api({ action: 'gift_stars', target_user: target, amount });

            alert(res.msg);
            if (res.status === 'ok') {
                openModal('gift-stars-modal'); // Close modal
                fetchState(true);
            }
        }

        function openAdminXpModal(targetUsername) {
            document.getElementById('admin-xp-target').value = targetUsername;
            openModal('admin-xp-modal');
        }

        async function adminSetXp() {
             const target = document.getElementById('admin-xp-target').value;
             const level = document.getElementById('admin-xp-level').value;
             const xp = document.getElementById('admin-xp-xp').value;

             if (!level || !xp) return alert('Введите и уровень, и XP.');

             const res = await api({ action: 'admin_set_xp', target_user: target, level: parseInt(level), xp: parseInt(xp) });

             alert(res.msg);
             if (res.status === 'ok') {
                 openModal('admin-xp-modal');
                 fetchState(true);
             }
        }

        async function adminAddShopItem() {
            const type = document.getElementById('admin-item-type').value;
            const name = document.getElementById('admin-item-name').value;
            const price = document.getElementById('admin-item-price').value;
            const image = document.getElementById('admin-item-image').value;

            if (!name || !price || parseInt(price) <= 0) return alert('Заполните все поля корректно.');

            const res = await api({ action: 'admin_add_shop_item', type, name, price, image });

            alert(res.msg);
            if (res.status === 'ok') {
                openModal('admin-add-item-modal');
                fetchState(false);
            }
        }

        // 9. Shop/Market/Inventory Logic

        function openShop(view) {
            currentShopView = view;
            openModal('shop-modal');
            switchShopView(view);
            fetchState(false);
        }

        function switchShopView(view) {
            currentShopView = view;
            document.querySelectorAll('.shop-tab').forEach(tab => tab.classList.remove('active'));
            document.querySelector(`.shop-tab[data-view="${view}"]`).classList.add('active');

            document.getElementById('shop-content-frames').style.display = 'none';
            document.getElementById('shop-content-nfts').style.display = 'none';
            document.getElementById('shop-content-gifts').style.display = 'none';
            document.getElementById('market-container').style.display = 'none';
            document.getElementById('inventory-container').style.display = 'none';

            if (view === 'frames') document.getElementById('shop-content-frames').style.display = 'grid';
            if (view === 'nfts') document.getElementById('shop-content-nfts').style.display = 'grid';
            if (view === 'gifts') document.getElementById('shop-content-gifts').style.display = 'grid';
            if (view === 'market') document.getElementById('market-container').style.display = 'grid';
            if (view === 'inventory') document.getElementById('inventory-container').style.display = 'grid';

            const titles = {
                'frames': '🎨 Полосы (Рамки) для аватара',
                'nfts': '🖼️ NFT-арты',
                'gifts': '🎁 Виртуальные Подарки',
                'market': '💸 Маркет P2P',
                'inventory': '📦 Ваш Инвентарь'
            };
            document.getElementById('modal-title').innerText = titles[view];
            document.getElementById('modal-subtitle').innerText = '';
        }

        function renderShop(shopItems, marketItems, inventory) {
            const frameGrid = document.getElementById('shop-content-frames');
            const nftGrid = document.getElementById('shop-content-nfts');
            const giftGrid = document.getElementById('shop-content-gifts');
            const inventoryGrid = document.getElementById('inventory-container');
            const marketGrid = document.getElementById('market-container');

            frameGrid.innerHTML = ''; nftGrid.innerHTML = ''; giftGrid.innerHTML = ''; inventoryGrid.innerHTML = ''; marketGrid.innerHTML = '';

            const renderItemCard = (item, actionHtml) => {
                const colorClass = `item-rarity-${item.rarity || 'common'}`;
                const card = document.createElement('div');
                card.className = 'item-card';
                card.innerHTML = `
                    <img src="${item.image || `https://api.dicebear.com/7.x/bottts/svg?seed=${item.id}`}" alt="${item.name}">
                    <div style="font-weight:bold; font-size:14px; margin-bottom: 5px;">${item.name}</div>
                    <div class="${colorClass}" style="font-size:11px; margin-bottom: 8px;">РЕДКОСТЬ: ${item.rarity ? item.rarity.toUpperCase() : 'COMMON'}</div>
                    ${actionHtml}
                `;
                return card;
            };

            // 1. Shop (Frames, NFT, Gifts)
            shopItems.forEach(item => {
                if (item.id.startsWith('frame_ach_')) return; // Skip achievement frames from shop view

                let actionHtml = '';
                const isOwned = inventory.includes(item.id);

                if (item.type === 'frame') {
                    if (isOwned) {
                        actionHtml = `<button class="btn btn-dark" onclick="equipItem('${item.id}')">Надеть (Полоса)</button>`;
                    } else {
                        actionHtml = `<button class="btn btn-gold" onclick="buyShopItem('${item.id}')">${item.price} ⭐ Купить</button>`;
                    }
                    frameGrid.appendChild(renderItemCard(item, actionHtml));
                } else {
                    if (isOwned) {
                        actionHtml = `<div style="font-weight:bold; color:#76FF03;">В ИНВЕНТАРЕ</div>`;
                    } else {
                        actionHtml = `<button class="btn btn-gold" onclick="buyShopItem('${item.id}')">${item.price} ⭐ Купить</button>`;
                    }
                    if (item.type === 'nft') nftGrid.appendChild(renderItemCard(item, actionHtml));
                    if (item.type === 'gift') giftGrid.appendChild(renderItemCard(item, actionHtml));
                }
            });

            // 2. Inventory
            inventory.forEach(itemId => {
                const item = shopItems.find(i => i.id === itemId) || {
                    id: itemId,
                    name: allFrameIds.includes(itemId) ? allShopItems.find(i => i.id === itemId)?.name || 'Полоса Достижения' : itemId,
                    type: allFrameIds.includes(itemId) ? 'frame' : 'item',
                    rarity: 'common'
                };

                let actionHtml = '';
                if (item.type === 'frame') {
                    const isActive = itemId === currentUser.equipped_frame;
                    actionHtml += `<button class="btn ${isActive ? 'btn-primary' : 'btn-dark'}" onclick="equipItem('${itemId}')" style="margin-bottom: 5px;">${isActive ? 'Надето' : 'Надеть'}</button>`;
                }
                actionHtml += `<button class="btn btn-danger" style="font-size: 10px;" onclick="promptSell('${itemId}')">Продать (Market)</button>`;

                inventoryGrid.appendChild(renderItemCard(item, actionHtml));
            });

            // 3. Market
            const marketListings = Object.values(marketItems);
            if (marketListings.length === 0) {
                 marketGrid.innerHTML = '<p style="text-align:center; color:#888;">На Маркете пока нет товаров.</p>';
            }
            marketListings.forEach(listing => {
                 const item = shopItems.find(i => i.id === listing.item_id) || { id: listing.item_id, name: 'Неизвестный Предмет', type: 'item', rarity: 'common' };
                 let actionHtml = '';
                 if (listing.seller === currentUser.username) {
                     actionHtml = `<div style="font-weight:bold; color:var(--primary);">Ваше объявление</div>`;
                 } else {
                     actionHtml = `<button class="btn btn-gold" onclick="buyMarketItem('${listing.id}')">${listing.price} ⭐ Купить</button>`;
                 }

                 const card = renderItemCard(item, actionHtml);
                 card.style.border = `2px solid ${getColor(item.id)}`;
                 marketGrid.appendChild(card);
            });
        }

        // --- Shop/Market Actions ---

        async function buyShopItem(itemId) {
            const item = allShopItems.find(i => i.id === itemId);
            if (!item) return;

            if (confirm(`Вы уверены, что хотите купить ${item.name} за ${item.price} ⭐?`)) {
                const res = await api({ action: 'buy_shop_item', item_id: itemId });
                alert(res.msg);
                if (res.status === 'ok') {
                    fetchState(true);
                }
            }
        }

        async function equipItem(itemId) {
            if (itemId === currentUser.equipped_frame) {
                // Unequip logic (sends empty string/null)
                const res = await api({ action: 'update_profile', new_frame: '' });
                alert(res.msg);
            } else {
                 const res = await api({ action: 'equip_item', item_id: itemId });
                 alert(res.msg);
            }
            if (res.status === 'ok') {
                fetchState(true);
            }
        }

        function promptSell(itemId) {
            const item = allShopItems.find(i => i.id === itemId) || { name: itemId };
            const price = prompt(`За сколько Звезд (⭐) вы хотите выставить ${item.name} на Маркет?`);
            const priceInt = parseInt(price);

            if (priceInt > 0) {
                listItem(itemId, priceInt);
            } else if (price !== null) {
                alert('Некорректная цена.');
            }
        }

        async function listItem(itemId, price) {
             const res = await api({ action: 'list_item', item_id: itemId, price });
             alert(res.msg);
             if (res.status === 'ok') {
                 fetchState(true);
                 switchShopView('market');
             }
        }

        async function buyMarketItem(listingId) {
             if (!confirm('Вы уверены, что хотите купить этот предмет?')) return;
             const res = await api({ action: 'buy_market_item', listing_id: listingId });
             alert(res.msg);
             if (res.status === 'ok') {
                 fetchState(true);
                 switchShopView('inventory');
             }
        }

        // 10. Utility Functions

        function getColor(id) {
             const baseColors = ['#3498db', '#e74c3c', '#2ecc71', '#fd79a8', '#00cec9', '#9c27b0', '#ffd700', '#ff7675', '#ffffff', '#00d2d3'];
             const achColors = {
                 'frame_ach_veteran': '#00FFFF', // Cyan
                 'frame_ach_chatter': '#FFC0CB', // Pink
                 'frame_ach_millionaire': '#FFD700', // Gold
                 'frame_9': '#00d2d3' // Divine (special)
             };
             if (id.startsWith('frame_ach_')) return achColors[id] || '#777';

             const idx = parseInt(id.split('_')[1]);
             return baseColors[idx] || '#fff';
        }

        function openModal(id) {
            document.querySelectorAll('.modal').forEach(m => m.classList.remove('open'));
            document.getElementById(id).classList.add('open');
        }

        function toggleSidebar(id, force) {
             const el = document.getElementById(id);
             if (force === undefined) {
                 el.classList.toggle('open');
             } else if (force) {
                 el.classList.add('open');
             } else {
                 el.classList.remove('open');
             }

             if (window.innerWidth <= 900 && el.classList.contains('open')) {
                 const otherId = id === 'sidebar-left' ? 'profile' : 'sidebar-left';
                 document.getElementById(otherId).classList.remove('open');
             }
        }

        function openFavoritesModal() {
             openModal('favorites-modal');
             renderFavorites(currentUser.favorites || []);
        }

        function renderFavorites(favorites) {
            const list = document.getElementById('favorites-list');
            list.innerHTML = '';

            if (favorites.length === 0) {
                 list.innerHTML = '<p style="color:#aaa; text-align:center;">В Избранном пока пусто.</p>';
                 return;
            }

            favorites.slice().reverse().forEach(f => {
                const chatName = allChats[f.chat_id]?.name || f.chat_id;
                const div = document.createElement('div');
                div.className = `msg`;
                div.style.maxWidth = '100%';
                div.style.background = '#3e2e50';

                div.innerHTML = `
                    <div class="msg-header" style="color:#FFD700;">
                        ⭐ ИЗБРАННОЕ
                    </div>
                    <div style="font-size:12px; color:#aaa; margin-bottom:5px;">
                        [Чат: ${chatName}] ${f.author} • ${f.time}
                    </div>
                    <div>${f.text}</div>
                `;
                list.appendChild(div);
            });
        }
    </script>
</body>
</html>