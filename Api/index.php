<?php

// إعدادات البوت
define('BOT_TOKEN', '7465262401:AAGN-vBzFsBSWe8vqy_YNlrvVfHNa7vPkHM');
define('ADMIN_ID', 6873334348);
define('CHANNEL_ID', '-1002530096487');
define('JSON_FILE', __DIR__ . '/subscribers.json');

// تحميل بيانات المشتركين
function load_subscribers() {
    if (!file_exists(JSON_FILE)) return [];
    return json_decode(file_get_contents(JSON_FILE), true) ?: [];
}

// حفظ بيانات المشتركين
function save_subscribers($data) {
    file_put_contents(JSON_FILE, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

// إرسال رسالة تلجرام
function send_message($chat_id, $text, $keyboard = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    file_get_contents('https://api.telegram.org/bot'.BOT_TOKEN.'/sendMessage?' . http_build_query($data));
}

// إرسال للمشرف
function send_admin($text, $keyboard = null) {
    send_message(ADMIN_ID, $text, $keyboard);
}

// إضافة أو تحديث مشترك
function update_subscriber($user_id, $info) {
    $subs = load_subscribers();
    $subs[$user_id] = $info;
    save_subscribers($subs);
}

// حذف مشترك
function remove_subscriber($user_id) {
    $subs = load_subscribers();
    unset($subs[$user_id]);
    save_subscribers($subs);
}

// التحقق من الاشتراك بالقناة
function is_member($user_id) {
    $url = "https://api.telegram.org/bot".BOT_TOKEN."/getChatMember?chat_id=".CHANNEL_ID."&user_id={$user_id}";
    $res = json_decode(file_get_contents($url), true);
    if(isset($res['result']['status'])){
        return in_array($res['result']['status'], ['member','administrator','creator']);
    }
    return false;
}

// طرد المستخدم من القناة
function kick_from_channel($user_id) {
    $url = "https://api.telegram.org/bot".BOT_TOKEN."/banChatMember?chat_id=".CHANNEL_ID."&user_id={$user_id}";
    file_get_contents($url);
    // يمكنك فك الحظر تلقائيا (إذا أردت) بعد ثوانٍ ليبقى فقط الطرد وليس الحظر الدائم
    $url2 = "https://api.telegram.org/bot".BOT_TOKEN."/unbanChatMember?chat_id=".CHANNEL_ID."&user_id={$user_id}";
    file_get_contents($url2);
}

// معالجة التحديثات
$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['message'])) {
    $msg = $update['message'];
    $user_id = $msg['from']['id'];
    $fname = $msg['from']['first_name'] ?? '';
    $text = $msg['text'] ?? '';

    if ($text == '/start') {
        send_message($user_id, "👋 أهلاً بك $fname\nاشترك في القناة واضغط زر الاشتراك:",
            ['inline_keyboard'=>[[['text'=>'✅ طلب اشتراك','callback_data'=>'subscribe']]]]);
    }
}

if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $user_id = $cb['from']['id'];
    $fname = $cb['from']['first_name'] ?? '';
    $data = $cb['data'];

    if ($data == 'subscribe') {
        if (!is_member($user_id)) {
            send_message($user_id, "🚫 عليك الاشتراك أولاً في القناة.");
        } else {
            // إضافة طلب اشتراك
            $subs = load_subscribers();
            if (isset($subs[$user_id]) && $subs[$user_id]['status'] == 'active') {
                send_message($user_id, "✅ أنت مشترك بالفعل.");
                exit;
            }
            $subs[$user_id] = [
                'name' => $fname,
                'status' => 'pending',
                'start' => null,
                'end' => null,
            ];
            save_subscribers($subs);
            send_message($user_id, "تم إرسال طلب الاشتراك للمشرف. انتظر الموافقة.");
            send_admin("🔔 طلب اشتراك جديد من: $fname\nID: $user_id",
                ['inline_keyboard'=>[[['text'=>'✅ تفعيل 30 يوم','callback_data'=>"approve_$user_id"]]]]);
        }
    }

    if (strpos($data, 'approve_') === 0 && $user_id == ADMIN_ID) {
        $target_id = str_replace('approve_', '', $data);
        $subs = load_subscribers();
        if (isset($subs[$target_id])) {
            $subs[$target_id]['status'] = 'active';
            $subs[$target_id]['start'] = time();
            $subs[$target_id]['end'] = time() + 30*24*60*60;
            save_subscribers($subs);
            send_message($target_id, "✅ تم تفعيل اشتراكك لمدة 30 يوماً.\nستصلك رسالة قبل انتهاء الاشتراك.");
            send_admin("✅ تم تفعيل اشتراك $target_id لمدة 30 يوم.");
        }
    }

    if ($data == 'renew') {
        $subs = load_subscribers();
        if (isset($subs[$user_id]) && $subs[$user_id]['status']=='active') {
            $subs[$user_id]['status'] = 'renew_pending';
            save_subscribers($subs);
            send_message($user_id, "تم إرسال طلب تجديد الاشتراك للمشرف.");
            send_admin("🔁 طلب تجديد اشتراك من: $fname\nID: $user_id",
                ['inline_keyboard'=>[[['text'=>'✅ تجديد 30 يوم','callback_data'=>"renew_$user_id"]]]]);
        } else {
            send_message($user_id, "ليس لديك اشتراك نشط.");
        }
    }

    if (strpos($data, 'renew_') === 0 && $user_id == ADMIN_ID) {
        $target_id = str_replace('renew_', '', $data);
        $subs = load_subscribers();
        if (isset($subs[$target_id])) {
            $subs[$target_id]['status'] = 'active';
            $subs[$target_id]['start'] = time();
            $subs[$target_id]['end'] = time() + 30*24*60*60;
            save_subscribers($subs);
            send_message($target_id, "تم تجديد اشتراكك لمدة 30 يوماً.");
            send_admin("تم تجديد اشتراك $target_id.");
        }
    }
}

// --- كرون يومي: للتحقق من الاشتراكات ---
// شغّل هذا الجزء عبر كرون Vercel أو زر يدوي كل يوم (مثلاً عبر https://YOUR-VERCEL-DOMAIN/api/index.php?cron=1)
if (isset($_GET['cron']) && $_GET['cron'] == '1') {
    $subs = load_subscribers();
    foreach($subs as $id=>$info){
        // إشعار بعد 25 يوم
        if($info['status']=='active' && $info['end']-time() <= 5*24*60*60 && $info['end']-time() > 4*24*60*60){
            send_message($id, "⏰ بقي 5 أيام على نهاية اشتراكك! يرجى التجديد لتجنب الطرد.", [
                'inline_keyboard'=>[[['text'=>'🔁 تجديد الاشتراك','callback_data'=>'renew']]]
            ]);
        }
        // الطرد بعد 30 يوم
        if($info['status']=='active' && $info['end']<time()){
            send_message($id, "❌ انتهى اشتراكك وتم طردك من القناة تلقائياً.");
            kick_from_channel($id);
            remove_subscriber($id);
        }
    }
    echo "Cron Done";
}
?>