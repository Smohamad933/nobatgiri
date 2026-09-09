/* سیستم نوبت‌دهی — ویزارد رزرو مشتری */
(function () {
  'use strict';

  const API = window.APP.api;
  let CSRF = '';
  let BOOT = null;

  const S = {
    step: 0,
    branch: null, branches: [],
    services: [], service: null,
    staffList: [], staff: null, // null = بدون ترجیح
    ym: '', minYm: '', maxYm: '', grid: null, date: null, dateLabel: '',
    slots: [], slot: null, slotStaff: null,
    name: '', phone: '', notes: '',
    customer: null,
    payKind: 'full',
    otpSent: false, otpWait: 0, verified: false,
    afterLogin: null,
  };

  const STEPS = [
    { id: 'service', t: 'انتخاب خدمت' },
    { id: 'staff', t: 'متخصص' },
    { id: 'date', t: 'تاریخ' },
    { id: 'time', t: 'ساعت' },
    { id: 'info', t: 'مشخصات' },
    { id: 'payment', t: 'پرداخت' },
  ];

  const $ = (s) => document.querySelector(s);
  const stepBody = $('#step-body'), stepsEl = $('#steps'), summaryEl = $('#summary');
  const btnNext = $('#btn-next'), btnPrev = $('#btn-prev');

  function toast(msg, type) {
    const el = $('#toast');
    el.innerHTML = '<div class="alert alert-' + (type || 'info') + '">' + escapeHtml(msg) + '</div>';
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('show'), 4000);
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  async function api(action, params, method) {
    method = method || 'GET';
    const url = method === 'GET' && params
      ? API + '?action=' + action + '&' + new URLSearchParams(params)
      : API + '?action=' + action;
    const opt = { method, headers: { 'X-CSRF-Token': CSRF } };
    if (method === 'POST') {
      const fd = new FormData();
      if (params) for (const k in params) fd.append(k, params[k]);
      opt.body = fd;
    }
    const res = await fetch(url, opt);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'خطا');
    return data;
  }

  function loading(msg) {
    stepBody.innerHTML = '<div class="loading-box"><span class="spinner dark"></span> ' + escapeHtml(msg || 'در حال بارگذاری…') + '</div>';
  }

  /* ---------- راه‌اندازی ---------- */
  async function boot() {
    try {
      BOOT = await api('bootstrap');
      CSRF = BOOT.csrf;
      S.branches = BOOT.branches || [];
      S.branch = S.branches[0] || null;
      S.customer = BOOT.customer;
      if (S.customer) {
        S.name = S.customer.name || '';
        S.phone = S.customer.phone || '';
        S.verified = true;
      }
      renderNavUser();
      render();
      loadMyBookings();
    } catch (e) {
      stepBody.innerHTML = '<div class="alert alert-error">خطا در اتصال به سرور. لطفاً صفحه را تازه‌سازی کنید.</div>';
    }
  }

  function renderNavUser() {
    const el = $('#nav-user');
    if (S.customer) {
      el.innerHTML = '<span style="font-size:.85rem;color:var(--muted)">' + escapeHtml(S.customer.name || S.customer.phone) + '</span> <button class="linklike" id="logout-btn" style="color:var(--accent)">خروج</button>';
      $('#logout-btn').onclick = async () => {
        await api('logout', {}, 'POST').catch(() => {});
        S.customer = null; S.verified = false; S.otpSent = false;
        renderNavUser(); render(); loadMyBookings();
      };
    } else el.innerHTML = '';
  }

  /* ---------- رندر کلی ---------- */
  function render() {
    renderSteps();
    renderSummary();
    btnPrev.style.visibility = S.step === 0 ? 'hidden' : 'visible';
    btnNext.style.display = '';
    const id = STEPS[S.step].id;
    if (id === 'service') renderService();
    else if (id === 'staff') renderStaff();
    else if (id === 'date') renderDate();
    else if (id === 'time') renderTime();
    else if (id === 'info') renderInfo();
    else if (id === 'payment') renderPayment();
  }

  function renderSteps() {
    stepsEl.innerHTML = STEPS.map((s, i) => {
      const cls = i === S.step ? 'active' : (i < S.step ? 'done' : '');
      return '<div class="step-dot ' + cls + '"><span class="n">' + (i + 1) + '</span>' + s.t + '</div>';
    }).join('');
  }

  function renderSummary() {
    const rows = [];
    if (S.branch) rows.push(['شعبه', S.branch.name]);
    if (S.service) rows.push(['خدمت', S.service.name]);
    if (S.service) rows.push(['مدت', S.service.duration_label]);
    rows.push(['متخصص', S.staff ? S.staff.name : 'بدون ترجیح']);
    if (S.date) rows.push(['تاریخ', S.dateLabel]);
    if (S.slot) rows.push(['ساعت', S.slot.label + (S.slotStaff ? ' (' + S.slotStaff.name + ')' : '')]);
    if (S.service) rows.push(['مبلغ', S.service.price_label]);
    let h = '<h3>خلاصه رزرو</h3>';
    h += rows.map((r) => '<div class="summary-row"><span class="k">' + escapeHtml(r[0]) + '</span><span class="v">' + escapeHtml(r[1]) + '</span></div>').join('');
    if (BOOT && BOOT.policy) {
      h += '<div class="policy-note">لغو رایگان تا ' + BOOT.policy.free_cancel_hours + ' ساعت قبل؛ پس از آن ' + BOOT.policy.late_fee_percent + '٪ جریمه.</div>';
    }
    summaryEl.innerHTML = h;
  }

  /* ---------- مرحله ۱: خدمت ---------- */
  async function renderService() {
    btnNext.disabled = !S.service;
    btnNext.innerHTML = 'مرحله بعد ←';
    if (!S.branch) { stepBody.innerHTML = '<div class="alert alert-warning">شعبه‌ای فعال نیست.</div>'; return; }
    loading('در حال دریافت خدمات…');
    try {
      const d = await api('services', { branch_id: S.branch.id });
      S.services = d.services;
    } catch (e) { stepBody.innerHTML = '<div class="alert alert-error">' + escapeHtml(e.message) + '</div>'; return; }
    // حفظ انتخاب قبلی
    if (S.service) S.service = S.services.find((x) => x.id === S.service.id) || null;

    let h = '';
    if (S.branches.length > 1) {
      h += '<div class="form-group"><label>انتخاب شعبه</label><div class="pills" id="branch-pills">' +
        S.branches.map((b) => '<button class="pill' + (S.branch.id === b.id ? ' selected' : '') + '" data-id="' + b.id + '">' + escapeHtml(b.name) + '</button>').join('') + '</div></div>';
    }
    const cats = [...new Set(S.services.map((s) => s.category))];
    if (!S.services.length) h += '<div class="alert alert-warning">خدمتی در این شعبه ثبت نشده است.</div>';
    for (const c of cats) {
      h += '<div class="cat-title">' + escapeHtml(c) + '</div><div class="svc-grid">';
      for (const s of S.services.filter((x) => x.category === c)) {
        h += '<div class="svc-card' + (S.service && S.service.id === s.id ? ' selected' : '') + '" data-id="' + s.id + '">' +
          '<h4>' + escapeHtml(s.name) + '</h4><p>' + escapeHtml(s.description || '') + '</p>' +
          '<div class="svc-meta"><span class="price">' + escapeHtml(s.price_label) + '</span><span class="dur">' + escapeHtml(s.duration_label) + '</span></div></div>';
      }
      h += '</div>';
    }
    stepBody.innerHTML = h;
    stepBody.querySelectorAll('#branch-pills .pill').forEach((p) => p.onclick = () => {
      S.branch = S.branches.find((b) => b.id == p.dataset.id);
      S.service = null; S.staff = null; S.date = null; S.slot = null;
      renderService(); renderSummary();
    });
    stepBody.querySelectorAll('.svc-card').forEach((c) => c.onclick = () => {
      S.service = S.services.find((x) => x.id == c.dataset.id);
      S.staff = null; S.date = null; S.slot = null;
      S.payKind = 'full';
      stepBody.querySelectorAll('.svc-card').forEach((x) => x.classList.remove('selected'));
      c.classList.add('selected');
      btnNext.disabled = false;
      renderSummary();
    });
  }

  /* ---------- مرحله ۲: متخصص ---------- */
  async function renderStaff() {
    btnNext.innerHTML = 'مرحله بعد ←';
    loading('در حال دریافت متخصصان…');
    try {
      const d = await api('staff', { branch_id: S.branch.id, service_id: S.service.id });
      S.staffList = d.staff;
    } catch (e) { stepBody.innerHTML = '<div class="alert alert-error">' + escapeHtml(e.message) + '</div>'; return; }
    if (S.staff) S.staff = S.staffList.find((x) => x.id === S.staff.id) || null;
    let h = '<div class="staff-grid">';
    h += '<div class="staff-card' + (!S.staff ? ' selected' : '') + '" data-id="0"><div class="avatar">✦</div><h4>بدون ترجیح</h4><div class="spec">اولین متخصص آزاد</div></div>';
    for (const p of S.staffList) {
      const initial = (p.name || '?').trim().charAt(0);
      h += '<div class="staff-card' + (S.staff && S.staff.id === p.id ? ' selected' : '') + '" data-id="' + p.id + '">' +
        '<div class="avatar">' + escapeHtml(initial) + '</div><h4>' + escapeHtml(p.name) + '</h4>' +
        '<div class="title">' + escapeHtml(p.title || '') + '</div><div class="spec">' + escapeHtml(p.specialty || '') + '</div></div>';
    }
    h += '</div>';
    if (!S.staffList.length) h += '<div class="alert alert-warning">برای این خدمت متخصص فعالی تعریف نشده است.</div>';
    stepBody.innerHTML = h;
    stepBody.querySelectorAll('.staff-card').forEach((c) => c.onclick = () => {
      const id = parseInt(c.dataset.id, 10);
      S.staff = id ? S.staffList.find((x) => x.id === id) : null;
      S.date = null; S.slot = null;
      stepBody.querySelectorAll('.staff-card').forEach((x) => x.classList.remove('selected'));
      c.classList.add('selected');
      renderSummary();
    });
  }

  /* ---------- مرحله ۳: تقویم ---------- */
  function localYm(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  }

  async function renderDate() {
    btnNext.disabled = !S.date;
    btnNext.innerHTML = 'مرحله بعد ←';
    if (!S.ym) S.ym = localYm(new Date());
    loading('در حال بررسی ظرفیت روزها…');
    try {
      const d = await api('month', { branch_id: S.branch.id, service_id: S.service.id, staff_id: S.staff ? S.staff.id : 0, ym: S.ym });
      S.grid = d.grid; S.minYm = d.min_ym; S.maxYm = d.max_ym;
    } catch (e) { stepBody.innerHTML = '<div class="alert alert-error">' + escapeHtml(e.message) + '</div>'; return; }

    const dows = ['شنبه', '۱شنبه', '۲شنبه', '۳شنبه', '۴شنبه', '۵شنبه', 'جمعه'];
    let h = '<div class="cal"><div class="cal-head">' +
      '<button class="btn btn-ghost btn-sm" id="cal-prev"' + (S.ym <= S.minYm ? ' disabled' : '') + '>→ ماه قبل</button>' +
      '<b>' + escapeHtml(S.grid.caption) + '</b>' +
      '<button class="btn btn-ghost btn-sm" id="cal-next"' + (S.ym >= S.maxYm ? ' disabled' : '') + '>ماه بعد ←</button></div>';
    h += '<div class="cal-grid">' + dows.map((d) => '<div class="cal-dow">' + d + '</div>').join('');
    for (const w of S.grid.weeks) {
      for (const c of w) {
        if (!c.in_month) { h += '<div class="cal-day out"><span class="d">' + c.jday + '</span></div>'; continue; }
        const open = c.status === 'open';
        const sel = S.date === c.date ? ' selected' : '';
        const cls = open ? 'open' : 'disabled';
        const tag = open ? (c.free + ' ظرفیت') : (c.status === 'holiday' ? 'تعطیل' : (c.status === 'full' ? 'تکمیل' : '—'));
        h += '<button class="cal-day ' + cls + (c.is_today ? ' today' : '') + sel + '" data-date="' + c.date + '" data-label="' + escapeHtml(c.label) + '"' + (open ? '' : ' disabled title="' + escapeHtml(c.reason || tag) + '"') + '>' +
          '<span class="d">' + c.jday + '</span><span class="tag">' + escapeHtml(tag) + '</span></button>';
      }
    }
    h += '</div></div>';
    h += '<div class="cal-legend"><span><i class="dot" style="background:var(--ok)"></i>دارای ظرفیت</span><span><i class="dot" style="background:#cbd5e1"></i>تکمیل / تعطیل</span></div>';
    stepBody.innerHTML = h;
    $('#cal-prev').onclick = () => shiftMonth(-1);
    $('#cal-next').onclick = () => shiftMonth(1);
    stepBody.querySelectorAll('.cal-day.open').forEach((b) => b.onclick = () => {
      S.date = b.dataset.date; S.dateLabel = b.dataset.label; S.slot = null;
      renderSummary();
      go(3); // انتخاب تاریخ → پرش خودکار به ساعت
    });
  }

  function shiftMonth(d) {
    const [y, m] = S.ym.split('-').map(Number);
    const dt = new Date(y, m - 1 + d, 1);
    S.ym = localYm(dt);
    renderDate();
  }

  /* ---------- مرحله ۴: ساعت ---------- */
  async function renderTime() {
    btnNext.disabled = !S.slot;
    btnNext.innerHTML = 'مرحله بعد ←';
    loading('در حال دریافت ساعت‌های خالی…');
    let d;
    try {
      d = await api('slots', { branch_id: S.branch.id, service_id: S.service.id, staff_id: S.staff ? S.staff.id : 0, date: S.date });
      S.slots = d.slots || [];
    } catch (e) { stepBody.innerHTML = '<div class="alert alert-error">' + escapeHtml(e.message) + '</div>'; return; }

    let h = '<div class="day-title">🕐 ' + escapeHtml(S.dateLabel) + '</div>';
    if (!S.slots.length) {
      h += '<div class="alert alert-warning">' + escapeHtml(d.reason || 'ظرفیتی باقی نمانده است.') + '</div>';
      h += '<div class="card" style="box-shadow:none"><b>عضویت در صف انتظار</b><p style="color:var(--muted);font-size:.9rem">اگر نوبتی لغو شود، اولین نفر باخبر می‌شوید.</p><button class="btn btn-accent" id="waiting-btn">ثبت در صف انتظار</button></div>';
      stepBody.innerHTML = h;
      btnNext.style.display = 'none';
      $('#waiting-btn').onclick = joinWaiting;
      return;
    }
    h += '<div class="slots-grid">';
    for (const s of S.slots) {
      const staffName = s.staff.length === 1 ? s.staff[0].name : (s.staff.length + ' متخصص آزاد');
      const sel = S.slot && S.slot.start === s.start ? ' selected' : '';
      h += '<button class="slot' + sel + '" data-start="' + s.start + '"><b>' + escapeHtml(s.label) + '</b><small>' + escapeHtml(staffName) + '</small></button>';
    }
    h += '</div>';
    stepBody.innerHTML = h;
    stepBody.querySelectorAll('.slot').forEach((b) => b.onclick = () => {
      const s = S.slots.find((x) => x.start === b.dataset.start);
      S.slot = s;
      // اگر چند متخصص آزادند، اولی انتخاب می‌شود (ساده و سریع)
      S.slotStaff = S.staff || (s.staff[0] ? { id: s.staff[0].id, name: s.staff[0].name } : null);
      renderSummary();
      go(4); // انتخاب ساعت → پرش خودکار به مشخصات
    });
  }

  async function joinWaiting() {
    if (!S.customer) {
      S.afterLogin = () => joinWaiting();
      toast('برای عضویت در صف انتظار ابتدا وارد شوید.', 'warning');
      go(4);
      return;
    }
    try {
      await api('join_waiting', {
        branch_id: S.branch.id, service_id: S.service.id,
        staff_id: S.staff ? S.staff.id : 0, date_from: S.date, name: S.name,
      }, 'POST');
      toast('شما در صف انتظار ثبت شدید. به‌محض آزاد شدن ظرفیت پیامک می‌گیرید.', 'success');
    } catch (e) { toast(e.message, 'error'); }
  }

  /* ---------- مرحله ۵: مشخصات + ورود ---------- */
  function renderInfo() {
    btnNext.innerHTML = S.verified ? 'مرحله بعد ←' : 'تأیید و ادامه ←';
    const loggedAs = S.customer && S.verified && S.phone === S.customer.phone;
    let h = '<div class="form-row">' +
      '<div class="form-group"><label>نام و نام خانوادگی *</label><input class="form-control" id="f-name" value="' + escapeHtml(S.name) + '" placeholder="مثلاً سارا محمدی"></div>' +
      '<div class="form-group"><label>شماره موبایل *</label><input class="form-control" id="f-phone" inputmode="numeric" value="' + escapeHtml(S.phone) + '" placeholder="09xxxxxxxxx" dir="ltr" style="text-align:left"></div></div>' +
      '<div class="form-group"><label>توضیح (اختیاری)</label><textarea class="form-control" id="f-notes" placeholder="مثلاً حساسیت دارویی، درخواست خاص…">' + escapeHtml(S.notes) + '</textarea></div>';

    if (loggedAs) {
      h += '<div class="alert alert-success">✓ وارد شده‌اید (' + escapeHtml(S.customer.phone) + '). برای تغییر شماره، آن را ویرایش کنید.</div>';
    } else {
      h += '<div class="card" style="box-shadow:none;background:#f8fafc"><b>تأیید شماره با پیامک</b>' +
        '<p style="color:var(--muted);font-size:.88rem;margin:4px 0 10px">برای جلوگیری از رزروهای غیرواقعی، کد تأیید به موبایلتان پیامک می‌شود.</p>' +
        '<button class="btn btn-ghost" id="otp-send">ارسال کد تأیید</button> <span id="otp-timer" style="font-size:.85rem;color:var(--muted)"></span>' +
        '<div id="otp-area" style="margin-top:10px;' + (S.otpSent ? '' : 'display:none') + '">' +
        '<div class="otp-box"><input class="form-control" id="f-code" inputmode="numeric" maxlength="5" placeholder="کد ۵ رقمی" dir="ltr"><button class="btn btn-primary" id="otp-verify">تأیید کد</button></div>' +
        '<div class="form-hint" id="otp-demo"></div></div></div>';
    }
    stepBody.innerHTML = h;
    $('#f-name').oninput = (e) => S.name = e.target.value;
    $('#f-phone').oninput = (e) => {
      const v = e.target.value;
      if (S.customer && v !== S.customer.phone) { S.verified = false; S.otpSent = false; }
      else if (S.customer && v === S.customer.phone) { S.verified = true; }
      S.phone = v;
    };
    $('#f-notes').oninput = (e) => S.notes = e.target.value;
    const sendBtn = $('#otp-send');
    if (sendBtn) sendBtn.onclick = sendOtp;
    const verifyBtn = $('#otp-verify');
    if (verifyBtn) verifyBtn.onclick = verifyOtp;
    if (S.otpWait > 0) tickOtp();
  }

  async function sendOtp() {
    S.name = $('#f-name').value.trim(); S.phone = $('#f-phone').value.trim();
    if (!S.name) return toast('نام را وارد کنید.', 'warning');
    if (!/^09\d{9}$/.test(S.phone.replace(/[\s-]/g, ''))) return toast('شماره موبایل معتبر نیست.', 'warning');
    S.phone = S.phone.replace(/[\s-]/g, '');
    const btn = $('#otp-send');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> در حال ارسال…';
    try {
      const d = await api('send_otp', { phone: S.phone }, 'POST');
      S.otpSent = true; S.otpWait = d.wait || 60;
      $('#otp-area').style.display = '';
      if (d.demo_code) $('#otp-demo').textContent = 'حالت نمایشی — کد شما: ' + d.demo_code;
      toast('کد تأیید ارسال شد.', 'success');
      tickOtp();
    } catch (e) {
      const m = e.message.match(/wait=(\d+)/);
      toast(e.message, 'error');
    } finally { btn.disabled = false; btn.textContent = 'ارسال مجدد کد'; }
  }

  function tickOtp() {
    const t = $('#otp-timer'), btn = $('#otp-send');
    if (!t) return;
    if (btn) btn.disabled = true;
    const iv = setInterval(() => {
      S.otpWait--;
      if (S.otpWait <= 0) { clearInterval(iv); t.textContent = ''; if (btn) btn.disabled = false; return; }
      t.textContent = 'ارسال مجدد تا ' + S.otpWait + ' ثانیه';
    }, 1000);
    t.textContent = 'ارسال مجدد تا ' + S.otpWait + ' ثانیه';
  }

  async function verifyOtp() {
    const code = $('#f-code').value.trim();
    if (code.length < 4) return toast('کد را کامل وارد کنید.', 'warning');
    try {
      const d = await api('verify_otp', { phone: S.phone, code, name: S.name }, 'POST');
      S.customer = d.customer; S.verified = true;
      renderNavUser();
      toast('شماره تأیید شد. خوش آمدید!', 'success');
      if (S.afterLogin) { const f = S.afterLogin; S.afterLogin = null; f(); return; }
      renderInfo();
    } catch (e) { toast(e.message, 'error'); }
  }

  /* ---------- مرحله ۶: پرداخت ---------- */
  function renderPayment() {
    btnNext.innerHTML = S.service.price > 0 ? 'ثبت و پرداخت ←' : 'ثبت نهایی نوبت ←';
    const price = S.service.price;
    let h = '<div class="card" style="box-shadow:none;background:#f8fafc;margin-bottom:14px">';
    h += '<div class="summary-row"><span class="k">خدمت</span><span class="v">' + escapeHtml(S.service.name) + '</span></div>';
    h += '<div class="summary-row"><span class="k">متخصص</span><span class="v">' + escapeHtml(S.slotStaff ? S.slotStaff.name : (S.staff ? S.staff.name : 'بدون ترجیح')) + '</span></div>';
    h += '<div class="summary-row"><span class="k">زمان</span><span class="v">' + escapeHtml(S.dateLabel) + ' — ' + escapeHtml(S.slot.label) + '</span></div>';
    h += '<div class="summary-row"><span class="k">مشتری</span><span class="v">' + escapeHtml(S.name) + ' (' + escapeHtml(S.phone) + ')</span></div></div>';

    if (price > 0 && S.service.deposit > 0 && S.service.deposit < price) {
      h += '<label class="radio-card' + (S.payKind === 'full' ? ' selected' : '') + '"><input type="radio" name="paykind" value="full"' + (S.payKind === 'full' ? ' checked' : '') + '> <b>پرداخت کامل — ' + escapeHtml(S.service.price_label) + '</b><small>تسویه کامل هزینه‌ی خدمت</small></label>';
      h += '<label class="radio-card' + (S.payKind === 'deposit' ? ' selected' : '') + '"><input type="radio" name="paykind" value="deposit"' + (S.payKind === 'deposit' ? ' checked' : '') + '> <b>پرداخت بیعانه — ' + escapeHtml(S.service.deposit_label) + '</b><small>مابقی (' + escapeHtml(moneyFa(price - S.service.deposit)) + ') حضوری پرداخت می‌شود</small></label>';
    } else if (price > 0) {
      h += '<div class="alert alert-info">مبلغ قابل پرداخت: <b>' + escapeHtml(S.service.price_label) + '</b></div>';
      S.payKind = 'full';
    } else {
      h += '<div class="alert alert-success">این خدمت رایگان است و بدون پرداخت ثبت می‌شود.</div>';
    }
    if (BOOT && BOOT.policy) {
      h += '<div class="policy-note">با ثبت نوبت، <b>قوانین لغو</b> را می‌پذیرید: لغو رایگان تا ' + BOOT.policy.free_cancel_hours + ' ساعت قبل از نوبت؛ لغو دیرتر شامل ' + BOOT.policy.late_fee_percent + '٪ جریمه از مبلغ خدمت می‌شود.</div>';
    }
    stepBody.innerHTML = h;
    stepBody.querySelectorAll('input[name=paykind]').forEach((r) => r.onchange = () => {
      S.payKind = r.value;
      stepBody.querySelectorAll('.radio-card').forEach((x) => x.classList.remove('selected'));
      r.closest('.radio-card').classList.add('selected');
    });
  }

  function moneyFa(n) {
    return Number(n).toLocaleString('fa-IR') + ' تومان';
  }

  async function submitBooking() {
    btnNext.disabled = true;
    btnNext.innerHTML = '<span class="spinner"></span> در حال ثبت نوبت…';
    try {
      const d = await api('create_booking', {
        branch_id: S.branch.id, service_id: S.service.id,
        staff_id: S.slotStaff ? S.slotStaff.id : (S.staff ? S.staff.id : 0),
        date: S.date, start: S.slot.start,
        name: S.name, phone: S.phone, notes: S.notes, pay_kind: S.payKind,
      }, 'POST');
      if (d.pay_url) { window.location.href = d.pay_url; return; }
      showSuccess(d.booking);
    } catch (e) {
      toast(e.message, 'error');
      btnNext.disabled = false;
      btnNext.innerHTML = 'ثبت و پرداخت ←';
      // اگر ساعت پر شده، برگرد به انتخاب ساعت
      if (e.message.indexOf('پر شد') > -1) { S.slot = null; setTimeout(() => go(3), 1200); }
    }
  }

  function showSuccess(b) {
    document.querySelector('.wizard-nav').style.display = 'none';
    stepsEl.innerHTML = '<div class="step-dot done" style="width:100%;justify-content:center">✓ نوبت شما با موفقیت ثبت شد</div>';
    stepBody.innerHTML = '<div class="success-hero"><div class="big">🎉</div>' +
      '<h2>نوبت شما ثبت شد!</h2><p>کد پیگیری:</p><div class="code">' + escapeHtml(b.code) + '</div>' +
      '<div class="kv" style="text-align:right;max-width:560px;margin:16px auto">' +
      '<div><span>خدمت: </span><b>' + escapeHtml(b.service) + '</b></div>' +
      '<div><span>متخصص: </span><b>' + escapeHtml(b.staff) + '</b></div>' +
      '<div><span>زمان: </span><b>' + escapeHtml(b.date_label) + ' — ' + escapeHtml(b.time_label) + '</b></div>' +
      '<div><span>شعبه: </span><b>' + escapeHtml(b.branch) + '</b></div></div>' +
      '<div class="result-actions" style="justify-content:center">' +
      '<button class="btn btn-ghost" onclick="document.getElementById(\'track\').scrollIntoView()">پیگیری نوبت</button> ' +
      '<button class="btn btn-ghost" onclick="location.reload()">رزرو جدید</button></div></div>';
    summaryEl.innerHTML = '<h3>✓ ثبت شد</h3><div class="summary-row"><span class="k">کد پیگیری</span><span class="v">' + escapeHtml(b.code) + '</span></div>';
    loadMyBookings();
  }

  /* ---------- ناوبری ---------- */
  function go(i) {
    S.step = i;
    document.querySelector('.wizard-nav').style.display = '';
    render();
    document.getElementById('wizard').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  btnNext.onclick = () => {
    const id = STEPS[S.step].id;
    if (id === 'service' && !S.service) return toast('یک خدمت انتخاب کنید.', 'warning');
    if (id === 'info') {
      S.name = ($('#f-name') || {}).value ? $('#f-name').value.trim() : S.name;
      S.phone = ($('#f-phone') || {}).value ? $('#f-phone').value.trim().replace(/[\s-]/g, '') : S.phone;
      S.notes = ($('#f-notes') || {}).value || '';
      if (!S.name) return toast('نام را وارد کنید.', 'warning');
      const loggedAs = S.customer && S.verified && S.phone === S.customer.phone;
      if (!loggedAs) return toast('ابتدا شماره موبایل را با کد تأیید کنید.', 'warning');
      go(5); return;
    }
    if (id === 'payment') { submitBooking(); return; }
    if (S.step < STEPS.length - 1) go(S.step + 1);
  };
  btnPrev.onclick = () => { if (S.step > 0) go(S.step - 1); };

  /* ---------- پیگیری نوبت ---------- */
  $('#track-btn').onclick = async () => {
    const code = $('#track-code').value.trim(), phone = $('#track-phone').value.trim();
    const box = $('#track-result');
    if (!code || !phone) { box.innerHTML = '<div class="alert alert-warning">کد پیگیری و موبایل را وارد کنید.</div>'; return; }
    box.innerHTML = '<div class="loading-box"><span class="spinner dark"></span></div>';
    try {
      const d = await api('booking_lookup', { code, phone });
      renderTrackResult(box, d.booking);
    } catch (e) { box.innerHTML = '<div class="alert alert-error">' + escapeHtml(e.message) + '</div>'; }
  };

  function renderTrackResult(box, b) {
    let h = '<div class="booking-result"><span class="status-badge st-' + b.status + '">' + escapeHtml(b.status_label) + '</span> <span class="code">' + escapeHtml(b.code) + '</span>';
    h += '<div class="kv"><div><span>خدمت: </span><b>' + escapeHtml(b.service) + '</b></div>' +
      '<div><span>متخصص: </span><b>' + escapeHtml(b.staff) + '</b></div>' +
      '<div><span>شعبه: </span><b>' + escapeHtml(b.branch) + '</b></div>' +
      '<div><span>زمان: </span><b>' + escapeHtml(b.date_label) + '</b></div>' +
      '<div><span>ساعت: </span><b>' + escapeHtml(b.time_label) + '</b></div>' +
      '<div><span>مبلغ: </span><b>' + escapeHtml(b.price_label) + '</b></div>' +
      '<div><span>پرداخت‌شده: </span><b>' + escapeHtml(b.paid_label) + '</b></div></div>';
    h += '<div class="result-actions" id="track-actions">';
    if (b.can_pay) h += '<button class="btn btn-primary btn-sm" data-act="pay">پرداخت ' + escapeHtml(b.due_label) + '</button>';
    if (b.can_cancel) h += '<button class="btn btn-ghost btn-sm" data-act="cancel" style="color:var(--accent)">لغو نوبت</button>';
    if (b.gcal) h += '<a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="' + escapeHtml(b.gcal) + '">＋ گوگل کلندر</a>';
    if (b.ics_url) h += '<a class="btn btn-ghost btn-sm" href="' + escapeHtml(b.ics_url) + '">⬇ تقویم (ICS)</a>';
    h += '</div><div id="track-msg"></div></div>';
    box.innerHTML = h;
    box.querySelectorAll('#track-actions [data-act]').forEach((btn) => btn.onclick = () => trackAction(btn.dataset.act, b));
  }

  async function trackAction(act, b) {
    const msg = $('#track-msg');
    if (act === 'pay') {
      try {
        const d = await api('ensure_payment', { code: b.code, phone: $('#track-phone').value.trim(), kind: 'full' }, 'POST');
        window.location.href = d.pay_url;
      } catch (e) { msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(e.message) + '</div>'; }
    } else if (act === 'cancel') {
      if (!confirm('از لغو این نوبت مطمئن هستید؟')) return;
      try {
        const d = await api('cancel_mine', { code: b.code, phone: $('#track-phone').value.trim() }, 'POST');
        msg.innerHTML = '<div class="alert alert-success">نوبت لغو شد.' + (d.refund > 0 ? ' مبلغ ' + escapeHtml(d.refund_label) + ' مسترد می‌گردد.' : '') + '</div>';
        $('#track-btn').click();
      } catch (e) { msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(e.message) + '</div>'; }
    }
  }

  /* ---------- نوبت‌های من ---------- */
  async function loadMyBookings() {
    const box = $('#my-list');
    if (!S.customer) { box.innerHTML = '<p style="color:var(--muted)">برای مشاهده‌ی نوبت‌ها، در مرحله‌ی «مشخصات» رزرو با کد تأیید وارد شوید.</p>'; return; }
    try {
      const d = await api('my_bookings');
      if (!d.bookings.length) { box.innerHTML = '<p style="color:var(--muted)">هنوز نوبتی ثبت نکرده‌اید.</p>'; return; }
      box.innerHTML = '<div class="my-bookings">' + d.bookings.map((b) =>
        '<div class="my-booking"><span class="status-badge st-' + b.status + '">' + escapeHtml(b.status_label) + '</span>' +
        '<div class="grow"><b>' + escapeHtml(b.service) + '</b> — ' + escapeHtml(b.date_label) + '، ' + escapeHtml(b.time_label) +
        '<br><small style="color:var(--muted)">' + escapeHtml(b.staff) + ' | کد: ' + escapeHtml(b.code) + '</small></div>' +
        '<button class="btn btn-ghost btn-sm" data-code="' + escapeHtml(b.code) + '" data-phone="' + escapeHtml(b.customer_phone) + '">جزئیات</button></div>'
      ).join('') + '</div>';
      box.querySelectorAll('[data-code]').forEach((btn) => btn.onclick = () => {
        $('#track-code').value = btn.dataset.code;
        $('#track-phone').value = btn.dataset.phone;
        document.getElementById('track').scrollIntoView({ behavior: 'smooth' });
        $('#track-btn').click();
      });
    } catch (e) { box.innerHTML = ''; }
  }

  boot();
})();
