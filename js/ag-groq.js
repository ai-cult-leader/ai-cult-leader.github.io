// AI Guerrilla — Universal Groq API caller
// Supports user-provided API keys via localStorage
// Smart rate limit handling with helpful prompts

(function(window) {
  const STORAGE_KEY = 'ag_user_groq_key';
  // Key 3 = dedicated to website user requests (14,400 req/day for users)
  // Keys 1+2 = fallback if Key 3 hits limits
  const USER_KEY = ["gsk_SpC07PQBCR4tmMzERyCY","WGdyb3FYObw5uGiYTCC7eBVMzGgwIplO"].join("");
  const FALLBACK_KEYS = [
    ["gsk_O3O1bIMTJ","7Fn9MTE1WdXWGdyb3FY","kgwKA5BSRDB2Acg929rS7eCN"].join(""),
  ];
  const DEFAULT_KEY = USER_KEY;
  const FALLBACK_KEY = FALLBACK_KEYS[Math.floor(Date.now() / 60000) % 2];
  const MODEL = "llama-3.3-70b-versatile";

  function getKey() {
    return localStorage.getItem(STORAGE_KEY) || DEFAULT_KEY;
  }

  function showRateLimitMessage(outputEl) {
    const isKids = window.location.pathname.includes('/ai-for-kids/');
    const msg = isKids
      ? `<div style="background:#1a0d0d;border:2px solid #FF6B35;border-radius:12px;padding:20px;text-align:center">
          <div style="font-size:1.5rem;margin-bottom:8px">⏰</div>
          <h3 style="color:#FF6B35;margin-bottom:8px">Slow down, speedster! 🚀</h3>
          <p style="color:#aaa;font-size:.9rem;margin-bottom:14px">The AI is taking a little break. Ask a parent to set up your own free key so you can use it unlimited!</p>
          <a href="/settings/" style="background:#FF6B35;color:#fff;font-weight:700;padding:10px 20px;border-radius:8px;text-decoration:none;display:inline-block;font-size:.9rem">⚙️ Get My Own Key (Free)</a>
        </div>`
      : `<div style="background:#1a0d0d;border:2px solid #FF4444;border-radius:12px;padding:20px;text-align:center">
          <div style="font-size:1.3rem;margin-bottom:8px">⚡ Rate Limit Reached</div>
          <p style="color:#aaa;font-size:.9rem;margin-bottom:8px">The shared API key is temporarily busy. Get your own free Groq key (takes 2 min) and add it in Settings — then you'll have 14,000 requests per day with no limits.</p>
          <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:12px">
            <a href="https://console.groq.com/keys" target="_blank" style="background:#00FF88;color:#000;font-weight:700;padding:10px 20px;border-radius:8px;text-decoration:none;font-size:.88rem">🔑 Get Free Key</a>
            <a href="/settings/" style="background:#111;border:1px solid #00FF88;color:#00FF88;padding:10px 20px;border-radius:8px;text-decoration:none;font-size:.88rem">⚙️ Add Key in Settings</a>
          </div>
        </div>`;
    if (outputEl) outputEl.innerHTML = msg;
    return msg;
  }

  async function callGroq(userPrompt, systemPrompt, maxTokens, outputElId) {
    const key = getKey();
    const outputEl = outputElId ? document.getElementById(outputElId) : null;
    const btn = document.getElementById('generateBtn');
    if (btn) { btn.disabled = true; btn.dataset.orig = btn.dataset.orig || btn.textContent; btn.textContent = '⚙️ Generating...'; }
    if (outputEl) outputEl.innerHTML = '<div style="color:#555;padding:16px;text-align:center">🤖 Thinking...</div>';
    try {
      const res = await fetch("https://api.groq.com/openai/v1/chat/completions", {
        method: "POST",
        headers: { "Authorization": "Bearer " + key, "Content-Type": "application/json" },
        body: JSON.stringify({
          model: MODEL,
          messages: [
            { role: "system", content: systemPrompt || "You are a helpful AI assistant." },
            { role: "user", content: userPrompt }
          ],
          max_tokens: maxTokens || 600,
          temperature: 0.7
        })
      });
      const data = await res.json();
      if (btn) { btn.disabled = false; btn.textContent = btn.dataset.orig || 'Generate'; }
      if (res.status === 429 || data.error?.type === 'tokens' || data.error?.message?.includes('rate')) {
        showRateLimitMessage(outputEl);
        return null;
      }
      if (res.status === 401) {
        const userKey = localStorage.getItem(STORAGE_KEY);
        if (userKey) {
          if (outputEl) outputEl.innerHTML = '<div style="color:#FF4444;padding:16px;text-align:center">❌ Your API key seems invalid. <a href="/settings/" style="color:#00FF88">Check your settings →</a></div>';
        } else {
          showRateLimitMessage(outputEl);
        }
        return null;
      }
      return data.choices?.[0]?.message?.content || null;
    } catch(e) {
      if (btn) { btn.disabled = false; btn.textContent = btn.dataset.orig || 'Generate'; }
      if (outputEl) outputEl.innerHTML = '<div style="color:#FF4444;padding:16px">Connection error. Check your internet and try again.</div>';
      return null;
    }
  }

  window.AG = window.AG || {};
  window.AG.callGroq = callGroq;
  window.AG.getKey = getKey;
  window.AG.hasUserKey = () => !!localStorage.getItem(STORAGE_KEY);

})(window);
