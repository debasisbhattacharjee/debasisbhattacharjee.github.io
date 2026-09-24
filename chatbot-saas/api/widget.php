<?php
/**
 * Serves the embeddable widget as a single self-contained JS file
 * (`<script src="/api/widget.php?key=...">`). Builds its own DOM and CSS
 * at runtime so a customer's site only ever needs that one script tag.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$key = trim((string)($_GET['key'] ?? ''));
$stmt = db()->prepare('SELECT * FROM bots WHERE widget_key = ? AND is_active = 1');
$stmt->execute([$key]);
$bot = $stmt->fetch();

if (!$bot) {
    echo "console.warn('ChatBot widget: unknown or inactive widget key.');";
    exit;
}

$config = [
    'widgetKey' => $bot['widget_key'],
    'botName' => $bot['name'],
    'welcome' => $bot['welcome_message'],
    'color' => $bot['color'],
    'avatar' => $bot['avatar_url'],
    'leadCapture' => (bool)$bot['lead_capture'],
    'chatEndpoint' => APP_URL . '/api/chat.php',
    'leadEndpoint' => APP_URL . '/api/lead.php',
];
$configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
(function () {
  var CFG = <?= $configJson ?>;
  var STORAGE_KEY = 'cbs_' + CFG.widgetKey;
  var state = {};
  try { state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { state = {}; }
  if (!state.visitorId) {
    state.visitorId = 'v_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
  }
  function saveState() {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
  }
  saveState();

  var css = ''
    + '.cbs-bubble{position:fixed;bottom:20px;right:20px;width:60px;height:60px;border-radius:50%;'
    + 'background:' + CFG.color + ';box-shadow:0 4px 14px rgba(0,0,0,.25);cursor:pointer;z-index:999999;'
    + 'display:flex;align-items:center;justify-content:center;transition:transform .15s ease;}'
    + '.cbs-bubble:hover{transform:scale(1.06);}'
    + '.cbs-bubble svg{width:28px;height:28px;fill:#fff;}'
    + '.cbs-panel{position:fixed;bottom:92px;right:20px;width:350px;max-width:92vw;height:480px;max-height:76vh;'
    + 'background:#fff;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.25);display:none;flex-direction:column;'
    + 'overflow:hidden;z-index:999999;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}'
    + '.cbs-panel.cbs-open{display:flex;}'
    + '.cbs-head{background:' + CFG.color + ';color:#fff;padding:14px 16px;font-weight:600;display:flex;justify-content:space-between;align-items:center;}'
    + '.cbs-head span.cbs-close{cursor:pointer;font-size:20px;line-height:1;opacity:.85;}'
    + '.cbs-body{flex:1;overflow-y:auto;padding:12px;background:#f7f7f9;}'
    + '.cbs-msg{margin-bottom:10px;max-width:85%;padding:9px 12px;border-radius:12px;font-size:14px;line-height:1.4;white-space:pre-wrap;word-wrap:break-word;}'
    + '.cbs-msg.cbs-bot{background:#fff;border:1px solid #e5e5ea;border-bottom-left-radius:2px;}'
    + '.cbs-msg.cbs-user{background:' + CFG.color + ';color:#fff;margin-left:auto;border-bottom-right-radius:2px;}'
    + '.cbs-typing{font-size:13px;color:#888;padding:0 12px 8px;}'
    + '.cbs-inputrow{display:flex;border-top:1px solid #eee;padding:8px;gap:6px;}'
    + '.cbs-inputrow input{flex:1;border:1px solid #ddd;border-radius:20px;padding:9px 14px;font-size:14px;outline:none;}'
    + '.cbs-inputrow button{background:' + CFG.color + ';color:#fff;border:none;border-radius:20px;padding:0 16px;cursor:pointer;font-size:14px;}'
    + '.cbs-leadlink{font-size:12px;color:#888;text-align:center;padding:4px 0 8px;cursor:pointer;text-decoration:underline;}'
    + '.cbs-leadform{padding:12px;background:#fff;}'
    + '.cbs-leadform input,.cbs-leadform textarea{width:100%;box-sizing:border-box;margin-bottom:8px;padding:8px 10px;border:1px solid #ddd;border-radius:8px;font-size:13px;font-family:inherit;}'
    + '.cbs-leadform button{width:100%;background:' + CFG.color + ';color:#fff;border:none;border-radius:8px;padding:9px;cursor:pointer;font-size:14px;}';
  var styleEl = document.createElement('style');
  styleEl.textContent = css;
  document.head.appendChild(styleEl);

  var bubble = document.createElement('div');
  bubble.className = 'cbs-bubble';
  bubble.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.03 2 11c0 2.6 1.23 4.94 3.2 6.6L4 22l4.83-1.6C10 20.8 11 21 12 21c5.52 0 10-4.03 10-9S17.52 2 12 2z"/></svg>';
  document.body.appendChild(bubble);

  var panel = document.createElement('div');
  panel.className = 'cbs-panel';
  panel.innerHTML =
    '<div class="cbs-head"><span>' + escapeHtml(CFG.botName) + '</span><span class="cbs-close">&times;</span></div>' +
    '<div class="cbs-body" id="cbs-body"></div>' +
    '<div class="cbs-typing" id="cbs-typing" style="display:none;">Typing...</div>' +
    (CFG.leadCapture ? '<div class="cbs-leadlink" id="cbs-leadlink">Prefer to leave your details instead?</div>' : '') +
    '<div class="cbs-leadform" id="cbs-leadform" style="display:none;">' +
      '<input type="text" id="cbs-lf-name" placeholder="Your name">' +
      '<input type="email" id="cbs-lf-email" placeholder="Your email">' +
      '<input type="text" id="cbs-lf-phone" placeholder="Phone (optional)">' +
      '<textarea id="cbs-lf-msg" rows="2" placeholder="What can we help with?"></textarea>' +
      '<button id="cbs-lf-submit">Send</button>' +
    '</div>' +
    '<div class="cbs-inputrow" id="cbs-inputrow">' +
      '<input type="text" id="cbs-input" placeholder="Type a message...">' +
      '<button id="cbs-send">Send</button>' +
    '</div>';
  document.body.appendChild(panel);

  var bodyEl = document.getElementById('cbs-body');
  var typingEl = document.getElementById('cbs-typing');

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function addMessage(role, text) {
    var div = document.createElement('div');
    div.className = 'cbs-msg ' + (role === 'user' ? 'cbs-user' : 'cbs-bot');
    div.textContent = text;
    bodyEl.appendChild(div);
    bodyEl.scrollTop = bodyEl.scrollHeight;
  }

  var greeted = false;
  bubble.addEventListener('click', function () {
    panel.classList.toggle('cbs-open');
    if (panel.classList.contains('cbs-open') && !greeted) {
      addMessage('bot', CFG.welcome);
      greeted = true;
    }
  });
  panel.querySelector('.cbs-close').addEventListener('click', function () {
    panel.classList.remove('cbs-open');
  });

  function sendMessage() {
    var input = document.getElementById('cbs-input');
    var text = input.value.trim();
    if (!text) return;
    addMessage('user', text);
    input.value = '';
    typingEl.style.display = 'block';

    fetch(CFG.chatEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        widget_key: CFG.widgetKey,
        message: text,
        conversation_id: state.conversationId || 0,
        visitor_id: state.visitorId,
        page_url: window.location.href,
      }),
    }).then(function (r) { return r.json(); }).then(function (data) {
      typingEl.style.display = 'none';
      if (data.error) {
        addMessage('bot', 'Sorry - ' + data.error);
        return;
      }
      state.conversationId = data.conversation_id;
      saveState();
      addMessage('bot', data.reply);
    }).catch(function () {
      typingEl.style.display = 'none';
      addMessage('bot', 'Sorry, something went wrong. Please try again.');
    });
  }

  document.getElementById('cbs-send').addEventListener('click', sendMessage);
  document.getElementById('cbs-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') sendMessage();
  });

  if (CFG.leadCapture) {
    var leadLink = document.getElementById('cbs-leadlink');
    var leadForm = document.getElementById('cbs-leadform');
    var inputRow = document.getElementById('cbs-inputrow');
    leadLink.addEventListener('click', function () {
      leadForm.style.display = leadForm.style.display === 'none' ? 'block' : 'none';
      inputRow.style.display = leadForm.style.display === 'block' ? 'none' : 'flex';
    });
    document.getElementById('cbs-lf-submit').addEventListener('click', function () {
      var name = document.getElementById('cbs-lf-name').value.trim();
      var email = document.getElementById('cbs-lf-email').value.trim();
      var phone = document.getElementById('cbs-lf-phone').value.trim();
      var msg = document.getElementById('cbs-lf-msg').value.trim();
      if (!name && !email && !phone) {
        alert('Please enter at least a name, email, or phone number.');
        return;
      }
      fetch(CFG.leadEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          widget_key: CFG.widgetKey,
          conversation_id: state.conversationId || 0,
          name: name, email: email, phone: phone, message: msg,
        }),
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (data.error) { alert(data.error); return; }
        leadForm.style.display = 'none';
        inputRow.style.display = 'flex';
        addMessage('bot', "Thanks! We'll be in touch soon.");
      });
    });
  }
})();
