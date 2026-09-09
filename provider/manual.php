<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
require ROOT_PATH . '/lib/Helpers.php';
require ROOT_PATH . '/lib/Jalali.php';
require ROOT_PATH . '/lib/Settings.php';
require ROOT_PATH . '/lib/Auth.php';
require ROOT_PATH . '/lib/Notify.php';
require ROOT_PATH . '/lib/Booking.php';
require ROOT_PATH . '/lib/Payment.php';
require ROOT_PATH . '/lib/Share.php';
require_installed();
require __DIR__ . '/_guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'book') {
    require_csrf();
    try {
        $branch = biz_require_branch(post_int('branch_id'));
        $service = biz_require_service(post_int('service_id'));
        $staffId = post_int('staff_id');
        if ($staffId <= 0) {
            // حالت «بدون ترجیح»: متخصصِ ساعت انتخاب‌شده از لیست ساعت‌ها می‌آید
            $staffId = post_int('slot_staff');
        }
        if ($staffId <= 0 || !biz_owns_staff($staffId)) {
            throw new BookingException('متخصص معتبر نیست.');
        }
        $phone = normalize_phone(post('customer_phone'));
        $existing = find_user_by_phone($phone);
        $booking = create_booking([
            'branch_id' => (int) $branch['id'],
            'service_id' => (int) $service['id'],
            'staff_id' => $staffId,
            'date' => post('date'),
            'start' => substr(post('start'), 0, 5),
            'customer_name' => post('customer_name'),
            'customer_phone' => $phone,
            'customer_id' => $existing ? (int) $existing['id'] : null,
            'notes' => post('notes'),
            'admin_notes' => post('admin_notes'),
            'force_confirmed' => true, // ثبت دستی: بدون نیاز به پرداخت آنلاین
            'amount_paid' => max(0, post_int('amount_paid')),
            'pay_method' => in_array(post('pay_method'), ['cash', 'pos', 'card'], true) ? post('pay_method') : 'cash',
        ]);
        flash('success', 'نوبت دستی با کد ' . $booking['code'] . ' ثبت و تأیید شد. پیامک تأیید برای مشتری ارسال می‌شود.');
        redirect(u('provider/booking.php?id=' . $booking['id']));
    } catch (BookingException | SlotTakenException $e) {
        flash('error', $e->getMessage());
    }
    redirect(u('provider/manual.php'));
}

$branches = biz_branches();

$pageTitle = 'ثبت نوبت دستی (تلفنی/حضوری)';
$active = 'manual';
require __DIR__ . '/_layout.php';
?>

<div class="card">
  <p class="small muted">💡 برای مشتریانی که تلفنی یا حضوری نوبت می‌خواهند: خدمت، متخصص، تاریخ و ساعت را انتخاب کنید؛ نوبت بلافاصله تأیید و پیامک می‌شود. مبلغ دریافتی نقدی/کارتخوان را هم می‌توانید همان‌جا ثبت کنید.</p>
  <form method="post" id="manual-form">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="book">
    <div class="form-row r3">
      <div class="form-group"><label>شعبه *</label>
        <select class="form-control" name="branch_id" id="m-branch">
          <?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>خدمت *</label><select class="form-control" name="service_id" id="m-service"></select></div>
      <div class="form-group"><label>متخصص</label><select class="form-control" name="staff_id" id="m-staff"></select></div>
    </div>
    <div class="form-row r3">
      <div class="form-group"><label>تاریخ *</label><input type="date" class="form-control" name="date" id="m-date" value="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>" required></div>
      <div class="form-group"><label>ساعت *</label><select class="form-control" name="start" id="m-slot"></select><input type="hidden" name="slot_staff" id="m-slotstaff" value="0"></div>
      <div class="form-group"><label>مبلغ خدمت</label><input class="form-control" id="m-price" disabled></div>
    </div>
    <div id="m-msg"></div>
    <div class="form-row r3">
      <div class="form-group"><label>نام مشتری *</label><input class="form-control" name="customer_name" required placeholder="مثلاً سارا محمدی"></div>
      <div class="form-group"><label>موبایل مشتری *</label><input class="form-control" name="customer_phone" dir="ltr" required inputmode="numeric" placeholder="09xxxxxxxxx"></div>
      <div class="form-group"><label>توضیح سفارش (اختیاری)</label><input class="form-control" name="notes" placeholder="مثلاً درخواست خاص مشتری"></div>
    </div>
    <div class="form-row r3">
      <div class="form-group"><label>مبلغ دریافتی (تومان)</label><input class="form-control" type="number" min="0" name="amount_paid" id="m-paid" value="0"></div>
      <div class="form-group"><label>نحوه دریافت</label>
        <select class="form-control" name="pay_method"><option value="cash">نقدی</option><option value="pos">کارتخوان</option><option value="card">کارت‌به‌کارت</option></select>
      </div>
      <div class="form-group"><label>یادداشت داخلی</label><input class="form-control" name="admin_notes" placeholder="فقط برای شما"></div>
    </div>
    <button class="btn btn-primary" id="m-submit">📞 ثبت و تأیید نوبت</button>
  </form>
</div>

<script>
(function () {
  const branch = document.getElementById('m-branch');
  const service = document.getElementById('m-service');
  const staff = document.getElementById('m-staff');
  const date = document.getElementById('m-date');
  const slot = document.getElementById('m-slot');
  const price = document.getElementById('m-price');
  const paid = document.getElementById('m-paid');
  const msg = document.getElementById('m-msg');
  const submit = document.getElementById('m-submit');
  let svcMap = {};

  function err(e) { msg.innerHTML = '<div class="alert alert-error">' + (e.error || e.message || 'خطا') + '</div>'; }

  async function loadServices() {
    msg.innerHTML = '';
    try {
      const d = await providerApi('services', { branch_id: branch.value });
      svcMap = {};
      service.innerHTML = '';
      d.groups.forEach((g) => g.services.forEach((s) => {
        svcMap[s.id] = s;
        const o = document.createElement('option');
        o.value = s.id;
        o.textContent = s.name + ' — ' + s.price_label;
        service.appendChild(o);
      }));
      if (!service.options.length) service.innerHTML = '<option value="">خدمتی فعال نیست</option>';
      await loadStaff();
    } catch (e) { err(e); }
  }

  async function loadStaff() {
    staff.innerHTML = '<option value="0">بدون ترجیح</option>';
    if (!service.value) return;
    try {
      const d = await providerApi('staff', { branch_id: branch.value, service_id: service.value });
      d.staff.forEach((p) => {
        const o = document.createElement('option');
        o.value = p.id;
        o.textContent = p.name + (p.title ? ' (' + p.title + ')' : '');
        staff.appendChild(o);
      });
      updatePrice();
      await loadSlots();
    } catch (e) { err(e); }
  }

  function updatePrice() {
    const s = svcMap[service.value];
    price.value = s ? s.price_label : '';
    if (s) paid.value = s.price;
  }

  async function loadSlots() {
    slot.innerHTML = '';
    msg.innerHTML = '';
    submit.disabled = true;
    if (!service.value || !date.value) return;
    try {
      const d = await providerApi('slots', { branch_id: branch.value, service_id: service.value, staff_id: staff.value || 0, date: date.value });
      if (d.status !== 'open' || !d.slots.length) {
        msg.innerHTML = '<div class="alert alert-warning">' + (d.reason || 'ظرفیتی در این تاریخ نیست.') + '</div>';
        return;
      }
      d.slots.forEach((s) => {
        const o = document.createElement('option');
        o.value = s.start;
        const names = (s.staff || []).map((p) => p.name).join('، ');
        o.textContent = s.label + (names ? ' — ' + names : '');
        if (s.staff && s.staff[0]) o.dataset.staff = s.staff[0].id;
        slot.appendChild(o);
      });
      submit.disabled = false;
    } catch (e) { err(e); }
  }

  function syncSlotStaff() {
    const o = slot.options[slot.selectedIndex];
    document.getElementById('m-slotstaff').value = (o && o.dataset.staff) ? o.dataset.staff : staff.value;
  }
  slot.onchange = syncSlotStaff;
  const _loadSlots = loadSlots;
  loadSlots = async function () { await _loadSlots(); syncSlotStaff(); };
  branch.onchange = loadServices;
  service.onchange = loadStaff;
  staff.onchange = loadSlots;
  date.onchange = loadSlots;
  loadServices();
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
