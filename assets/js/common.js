/* نوبت‌یار — توابع مشترک فرانت‌اند: ارتباط با API، پیام، پیگیری نوبت، نوبت‌های من */
window.NB = (function () {
  'use strict';

  const API = window.APP.api;
  const NB = { csrf: '', customer: null };

  NB.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  };

  NB.toast = function (msg, type) {
    const el = document.getElementById('toast');
    if (!el) return;
    el.innerHTML = '<div class="alert alert-' + (type || 'info') + '">' + NB.esc(msg) + '</div>';
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('show'), 4000);
  };

  NB.moneyFa = function (n) {
    return Number(n).toLocaleString('fa-IR') + ' تومان';
  };

  NB.api = async function (action, params, method) {
    method = method || 'GET';
    const url = method === 'GET' && params
      ? API + '?action=' + action + '&' + new URLSearchParams(params)
      : API + '?action=' + action;
    const opt = { method, headers: { 'X-CSRF-Token': NB.csrf } };
    if (method === 'POST') {
      const fd = new FormData();
      if (params) for (const k in params) fd.append(k, params[k]);
      opt.body = fd;
    }
    const res = await fetch(url, opt);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'خطا');
    return data;
  };

  /* ---------- پیگیری نوبت ---------- */
  NB.initTrack = function () {
    const btn = document.getElementById('track-btn');
    if (!btn) return;
    btn.onclick = async () => {
      const code = document.getElementById('track-code').value.trim();
      const phone = document.getElementById('track-phone').value.trim();
      const box = document.getElementById('track-result');
      if (!code || !phone) { box.innerHTML = '<div class="alert alert-warning">کد پیگیری و موبایل را وارد کنید.</div>'; return; }
      box.innerHTML = '<div class="loading-box"><span class="spinner dark"></span></div>';
      try {
        const d = await NB.api('booking_lookup', { code, phone });
        NB.renderTrackResult(box, d.booking);
      } catch (e) { box.innerHTML = '<div class="alert alert-error">' + NB.esc(e.message) + '</div>'; }
    };
  };

  NB.renderTrackResult = function (box, b) {
    let h = '<div class="booking-result"><span class="status-badge st-' + b.status + '">' + NB.esc(b.status_label) + '</span> <span class="code">' + NB.esc(b.code) + '</span>';
    h += '<div class="kv">' +
      (b.business ? '<div><span>کسب‌وکار: </span><b>' + NB.esc(b.business) + '</b></div>' : '') +
      '<div><span>خدمت: </span><b>' + NB.esc(b.service) + '</b></div>' +
      '<div><span>متخصص: </span><b>' + NB.esc(b.staff) + '</b></div>' +
      '<div><span>شعبه: </span><b>' + NB.esc(b.branch) + '</b></div>' +
      '<div><span>زمان: </span><b>' + NB.esc(b.date_label) + '</b></div>' +
      '<div><span>ساعت: </span><b>' + NB.esc(b.time_label) + '</b></div>' +
      '<div><span>مبلغ: </span><b>' + NB.esc(b.price_label) + '</b></div>' +
      '<div><span>پرداخت‌شده: </span><b>' + NB.esc(b.paid_label) + '</b></div></div>';
    h += '<div class="result-actions" id="track-actions">';
    if (b.can_pay) h += '<button class="btn btn-primary btn-sm" data-act="pay">پرداخت ' + NB.esc(b.due_label) + '</button>';
    if (b.can_cancel) h += '<button class="btn btn-ghost btn-sm" data-act="cancel" style="color:var(--accent)">لغو نوبت</button>';
    if (b.gcal) h += '<a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="' + NB.esc(b.gcal) + '">＋ گوگل کلندر</a>';
    if (b.ics_url) h += '<a class="btn btn-ghost btn-sm" href="' + NB.esc(b.ics_url) + '">⬇ تقویم (ICS)</a>';
    h += '</div><div id="track-msg"></div></div>';
    box.innerHTML = h;
    box.querySelectorAll('#track-actions [data-act]').forEach((btn) => btn.onclick = () => NB.trackAction(btn.dataset.act, b));
  };

  NB.trackAction = async function (act, b) {
    const msg = document.getElementById('track-msg');
    if (act === 'pay') {
      try {
        const d = await NB.api('ensure_payment', { code: b.code, phone: document.getElementById('track-phone').value.trim(), kind: 'full' }, 'POST');
        window.location.href = d.pay_url;
      } catch (e) { msg.innerHTML = '<div class="alert alert-error">' + NB.esc(e.message) + '</div>'; }
    } else if (act === 'cancel') {
      if (!confirm('از لغو این نوبت مطمئن هستید؟')) return;
      try {
        const d = await NB.api('cancel_mine', { code: b.code, phone: document.getElementById('track-phone').value.trim() }, 'POST');
        msg.innerHTML = '<div class="alert alert-success">نوبت لغو شد.' + (d.refund > 0 ? ' مبلغ ' + NB.esc(d.refund_label) + ' مسترد می‌گردد.' : '') + '</div>';
        document.getElementById('track-btn').click();
      } catch (e) { msg.innerHTML = '<div class="alert alert-error">' + NB.esc(e.message) + '</div>'; }
    }
  };

  /* ---------- نوبت‌های من ---------- */
  NB.loadMy = async function () {
    const box = document.getElementById('my-list');
    if (!box) return;
    if (!NB.customer) { box.innerHTML = '<p style="color:var(--muted)">برای مشاهده‌ی نوبت‌ها، هنگام رزرو با کد تأیید وارد شوید.</p>'; return; }
    try {
      const d = await NB.api('my_bookings');
      if (!d.bookings.length) { box.innerHTML = '<p style="color:var(--muted)">هنوز نوبتی ثبت نکرده‌اید.</p>'; return; }
      box.innerHTML = '<div class="my-bookings">' + d.bookings.map((b) =>
        '<div class="my-booking"><span class="status-badge st-' + b.status + '">' + NB.esc(b.status_label) + '</span>' +
        '<div class="grow"><b>' + NB.esc(b.service) + '</b>' + (b.business ? ' — ' + NB.esc(b.business) : '') + ' — ' + NB.esc(b.date_label) + '، ' + NB.esc(b.time_label) +
        '<br><small style="color:var(--muted)">' + NB.esc(b.staff) + ' | کد: ' + NB.esc(b.code) + '</small></div>' +
        '<button class="btn btn-ghost btn-sm" data-code="' + NB.esc(b.code) + '" data-phone="' + NB.esc(b.phone) + '">جزئیات</button></div>'
      ).join('') + '</div>';
      box.querySelectorAll('[data-code]').forEach((btn) => btn.onclick = () => {
        document.getElementById('track-code').value = btn.dataset.code;
        document.getElementById('track-phone').value = btn.dataset.phone;
        document.getElementById('track').scrollIntoView({ behavior: 'smooth' });
        document.getElementById('track-btn').click();
      });
    } catch (e) { box.innerHTML = ''; }
  };

  NB.renderNavUser = function (onLogout) {
    const el = document.getElementById('nav-user');
    if (!el) return;
    if (NB.customer) {
      el.innerHTML = '<span style="font-size:.85rem;color:var(--muted)">' + NB.esc(NB.customer.name || NB.customer.phone) + '</span> <button class="linklike" id="logout-btn" style="color:var(--accent)">خروج</button>';
      document.getElementById('logout-btn').onclick = async () => {
        await NB.api('logout', {}, 'POST').catch(() => {});
        NB.customer = null;
        NB.renderNavUser(onLogout);
        if (onLogout) onLogout();
      };
    } else el.innerHTML = '';
  };

  return NB;
})();
