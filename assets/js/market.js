/* نوبت‌یار — صفحه‌ی اصلی مارکت‌پلیس: دسته‌بندی‌ها، جستجو و لیست کسب‌وکارها */
(function () {
  'use strict';

  const state = { category: '', city: '', q: '' };

  function availBadge(b) {
    if (!b.next) return '<span class="avail-badge none">بدون ظرفیت در ۷ روز آینده</span>';
    if (b.next.today > 0) return '<span class="avail-badge today">امروز ' + b.next.today.toLocaleString('fa-IR') + ' ساعت خالی</span>';
    return '<span class="avail-badge next">نزدیک‌ترین نوبت: ' + NB.esc(b.next.date_label) + '</span>';
  }

  function card(b) {
    return '<article class="biz-card" data-id="' + b.id + '">' +
      '<div class="biz-card-top"><span class="cat-badge">' + NB.esc(b.category) + '</span>' + availBadge(b) + '</div>' +
      '<h3>' + NB.esc(b.name) + '</h3>' +
      '<p class="biz-desc">' + NB.esc(b.description || '') + '</p>' +
      '<div class="biz-meta"><span>📍 ' + NB.esc(b.city || '') + '</span><span>🏪 ' + b.stats.branches.toLocaleString('fa-IR') + ' شعبه</span>' +
      '<span>💈 ' + b.stats.services.toLocaleString('fa-IR') + ' خدمت</span><span>👥 ' + b.stats.staff.toLocaleString('fa-IR') + ' متخصص</span></div>' +
      '<div class="biz-card-foot"><span class="biz-done">✅ ' + b.stats.done.toLocaleString('fa-IR') + ' نوبت موفق</span>' +
      '<a class="btn btn-primary btn-sm" href="' + NB.esc(b.book_url) + '">مشاهده و رزرو</a></div></article>';
  }

  async function loadBusinesses() {
    const box = document.getElementById('biz-list');
    box.innerHTML = '<div class="loading-box"><span class="spinner dark"></span></div>';
    try {
      const d = await NB.api('businesses', { category: state.category, city: state.city, q: state.q });
      document.getElementById('biz-count').textContent = d.businesses.length.toLocaleString('fa-IR') + ' کسب‌وکار';
      box.innerHTML = d.businesses.length
        ? '<div class="biz-grid">' + d.businesses.map(card).join('') + '</div>'
        : '<div class="empty-box">کسب‌وکاری با این مشخصات یافت نشد.</div>';
    } catch (e) {
      box.innerHTML = '<div class="alert alert-error">' + NB.esc(e.message) + '</div>';
    }
  }

  function renderCategories(cats, cities) {
    const pills = document.getElementById('cat-pills');
    const all = [{ name: '', count: cats.reduce((s, c) => s + c.count, 0) }, ...cats];
    pills.innerHTML = all.map((c) =>
      '<button class="pill' + (state.category === c.name ? ' active' : '') + '" data-cat="' + NB.esc(c.name) + '">' +
      (c.name === '' ? 'همه' : NB.esc(c.name)) + ' <span class="cnt">' + c.count.toLocaleString('fa-IR') + '</span></button>'
    ).join('');
    pills.querySelectorAll('[data-cat]').forEach((p) => p.onclick = () => {
      state.category = p.dataset.cat;
      pills.querySelectorAll('.pill').forEach((x) => x.classList.toggle('active', x === p));
      loadBusinesses();
    });
    const sel = document.getElementById('city-filter');
    sel.innerHTML = '<option value="">همه‌ی شهرها</option>' + cities.map((c) => '<option value="' + NB.esc(c) + '">' + NB.esc(c) + '</option>').join('');
    sel.onchange = () => { state.city = sel.value; loadBusinesses(); };
  }

  document.addEventListener('DOMContentLoaded', async () => {
    try {
      const boot = await NB.api('bootstrap');
      NB.csrf = boot.csrf;
      NB.customer = boot.customer;
      NB.renderNavUser(() => NB.loadMy());
      const cats = await NB.api('categories');
      renderCategories(cats.categories, cats.cities);
      await loadBusinesses();
      NB.initTrack();
      NB.loadMy();
    } catch (e) {
      document.getElementById('biz-list').innerHTML = '<div class="alert alert-error">' + NB.esc(e.message) + '</div>';
    }
    const search = document.getElementById('biz-search');
    let t;
    search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { state.q = search.value.trim(); loadBusinesses(); }, 350); });
  });
})();
