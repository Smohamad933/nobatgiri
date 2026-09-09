  </div>
</div>
<script>
async function adminApi(action, params, method) {
  method = method || 'GET';
  const base = <?= json_encode(u('api.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const url = method === 'GET' && params ? base + '?action=' + action + '&' + new URLSearchParams(params) : base + '?action=' + action;
  const opt = { method, headers: { 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> } };
  if (method === 'POST') {
    const fd = new FormData();
    for (const k in (params || {})) fd.append(k, params[k]);
    opt.body = fd;
  }
  const res = await fetch(url, opt);
  return res.json();
}
</script>
</body>
</html>
