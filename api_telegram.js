import { Telegraf } from 'telegraf';
import fs from 'fs/promises';
import path from 'path';

// إعدادات البوت والقناة
const BOT_TOKEN = '7465262401:AAGN-vBzFsBSWe8vqy_YNlrvVfHNa7vPkHM';
const ADMIN_ID = 6873334348;
const CHANNEL_ID = -1002530096487;

// مسارات التخزين داخل مجلد .vercel (لحفظ البيانات)
const USERS_FILE = path.resolve('/tmp/users.json');
const PENDING_FILE = path.resolve('/tmp/pending.json');

// تحميل/حفظ ملفات JSON
async function loadJson(file, fallback = {}) {
  try {
    let data = await fs.readFile(file, 'utf-8');
    return JSON.parse(data);
  } catch (e) {
    return fallback;
  }
}
async function saveJson(file, data) {
  await fs.writeFile(file, JSON.stringify(data, null, 2), 'utf-8');
}

// دوال الاشتراك/تجديد/إشعار/طرد
async function addPending(user) {
  let pending = await loadJson(PENDING_FILE, {});
  pending[user.id] = user;
  await saveJson(PENDING_FILE, pending);
}
async function activateUser(user_id) {
  let pending = await loadJson(PENDING_FILE, {});
  let users = await loadJson(USERS_FILE, {});
  if (!pending[user_id]) return false;
  users[user_id] = {
    id: user_id,
    start_date: Date.now(),
    notify_25: false,
    renew_pending: false
  };
  delete pending[user_id];
  await saveJson(USERS_FILE, users);
  await saveJson(PENDING_FILE, pending);
  return true;
}
async function renewUser(user_id) {
  let users = await loadJson(USERS_FILE, {});
  if (!users[user_id]) return false;
  users[user_id].start_date = Date.now();
  users[user_id].notify_25 = false;
  users[user_id].renew_pending = false;
  await saveJson(USERS_FILE, users);
  return true;
}

// حذف مستخدم من القناة
async function kickUser(bot, user_id) {
  try {
    await bot.telegram.banChatMember(CHANNEL_ID, user_id);
  } catch { }
}

// فحص الاشتراكات (تنفذ عند كل webhook)
async function checkSubscriptions(bot) {
  let users = await loadJson(USERS_FILE, {});
  let changed = false;
  let now = Date.now();
  for (let uid in users) {
    let user = users[uid];
    let days = Math.floor((now - user.start_date) / 86400000);
    if (days >= 25 && !user.notify_25) {
      try {
        await bot.telegram.sendMessage(uid,
          "⚠️ متبقي 5 أيام فقط على انتهاء اشتراكك. يرجى التجديد حتى لا يتم طردك من القناة تلقائياً.",
          {
            reply_markup: {
              inline_keyboard: [
                [{ text: "تجديد الاشتراك", callback_data: "renew" }]
              ]
            }
          }
        );
      } catch { }
      user.notify_25 = true;
      changed = true;
    }
    if (days >= 30) {
      await kickUser(bot, uid);
      try {
        await bot.telegram.sendMessage(uid, "تم طردك من القناة لانتهاء الاشتراك ولم تقم بالتجديد.");
      } catch { }
      delete users[uid];
      changed = true;
    }
  }
  if (changed) await saveJson(USERS_FILE, users);
}

// إنشاء البوت
const bot = new Telegraf(BOT_TOKEN, { telegram: { webhookReply: true } });

// /start
bot.start(async (ctx) => {
  let users = await loadJson(USERS_FILE, {});
  if (users[ctx.from.id]) {
    ctx.reply('اشتراكك مفعل. ✅');
    return;
  }
  ctx.reply('مرحباً بك! اضغط على زر الاشتراك لتقديم طلب اشتراك للقناة.',
    {
      reply_markup: {
        inline_keyboard: [
          [{ text: "🔔 اشتراك", callback_data: "subscribe" }]
        ]
      }
    });
});

// زر الاشتراك
bot.action('subscribe', async (ctx) => {
  await addPending(ctx.from);
  ctx.reply('تم تقديم طلب الاشتراك بنجاح. انتظر موافقة المشرف.');
  // إخطار المشرف
  bot.telegram.sendMessage(ADMIN_ID,
    `طلب اشتراك جديد من: <a href="tg://user?id=${ctx.from.id}">${ctx.from.first_name}</a>\nID: ${ctx.from.id}`,
    {
      parse_mode: 'HTML',
      reply_markup: {
        inline_keyboard: [
          [{ text: "✅ موافقة", callback_data: "approve:" + ctx.from.id }],
          [{ text: "❌ رفض", callback_data: "reject:" + ctx.from.id }]
        ]
      }
    });
  ctx.answerCbQuery();
});

// زر تجديد الاشتراك
bot.action('renew', async (ctx) => {
  let users = await loadJson(USERS_FILE, {});
  if (users[ctx.from.id]) {
    users[ctx.from.id].renew_pending = true;
    await saveJson(USERS_FILE, users);
    ctx.reply('تم تقديم طلب تجديد الاشتراك للمشرف، يرجى الانتظار.');
    // إخطار المشرف
    bot.telegram.sendMessage(ADMIN_ID,
      `طلب تجديد من: <a href="tg://user?id=${ctx.from.id}">${ctx.from.first_name}</a>\nID: ${ctx.from.id}`,
      {
        parse_mode: 'HTML',
        reply_markup: {
          inline_keyboard: [
            [{ text: "✅ موافقة التجديد", callback_data: "approve_renew:" + ctx.from.id }]
          ]
        }
      });
  } else {
    ctx.reply('لا يوجد اشتراك مفعل.');
  }
  ctx.answerCbQuery();
});

// أزرار المشرف
bot.action(/^(approve|reject|approve_renew):(\d+)$/, async (ctx) => {
  if (ctx.from.id !== ADMIN_ID) return ctx.answerCbQuery();
  const [, action, uid] = ctx.match;
  if (action === 'approve') {
    if (await activateUser(uid)) {
      bot.telegram.sendMessage(uid, "تم تفعيل اشتراكك لمدة 30 يوماً! ✅");
      bot.telegram.sendMessage(uid, "رابط القناة: https://t.me/c/" + String(CHANNEL_ID).replace('-100', ''));
      ctx.reply('تمت الموافقة.');
    }
  }
  if (action === 'reject') {
    bot.telegram.sendMessage(uid, "تم رفض طلب اشتراكك من قبل المشرف.");
    let pending = await loadJson(PENDING_FILE, {});
    delete pending[uid];
    await saveJson(PENDING_FILE, pending);
    ctx.reply('تم الرفض.');
  }
  if (action === 'approve_renew') {
    if (await renewUser(uid)) {
      bot.telegram.sendMessage(uid, "تم تجديد اشتراكك لمدة 30 يوماً إضافية! ✅");
      ctx.reply('تمت الموافقة على التجديد.');
    }
  }
  ctx.answerCbQuery();
});

// عند كل Webhook افحص الاشتراكات
bot.use(async (ctx, next) => {
  await checkSubscriptions(bot);
  return next();
});

// التصدير بشكل HTTP handler (Vercel)
export default async function handler(req, res) {
  if (req.method === 'POST') {
    await bot.handleUpdate(req.body);
    res.status(200).send('OK');
  } else {
    res.status(200).send('Hello Telegram Bot!');
  }
}
export const config = { api: { bodyParser: true } };