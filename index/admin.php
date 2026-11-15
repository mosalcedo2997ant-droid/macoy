<?php
require 'db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?need=1');
    exit;
}

// Check the role in the database every time, never trust the browser
$uid  = (int) $_SESSION['user_id'];
$stmt = $conn->prepare('SELECT role FROM users WHERE id = ?');
$stmt->bind_param('i', $uid);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
if (!$me || $me['role'] !== 'admin') {
    header('Location: mybooking.php');
    exit;
}

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

// ---- Update status ----
$allowed = ['confirm' => 'Confirmed', 'decline' => 'Declined', 'complete' => 'Completed'];
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')
    && isset($allowed[$_POST['action'] ?? ''])) {
    $bid    = (int) ($_POST['booking_id'] ?? 0);
    $status = $allowed[$_POST['action']];
    $stmt = $conn->prepare('UPDATE bookings SET status = ? WHERE id = ?');
    $stmt->bind_param('si', $status, $bid);
    $stmt->execute();
    header('Location: admin.php?' . http_build_query(['done' => $status, 'f' => $_POST['f'] ?? '', 'q' => $_POST['q'] ?? '']));
    exit;
}

// ---- Filters ----
$f = $_GET['f'] ?? '';
$q = trim($_GET['q'] ?? '');
$valid = ['Pending', 'Confirmed', 'Completed', 'Declined', 'Cancelled'];

$sql = 'SELECT b.*, u.fullname, u.email
        FROM bookings b JOIN users u ON u.id = b.user_id WHERE 1=1';
$types = ''; $args = [];
if (in_array($f, $valid, true)) { $sql .= ' AND b.status = ?'; $types .= 's'; $args[] = $f; }
if ($q !== '') {
    $sql .= ' AND (u.fullname LIKE ? OR u.email LIKE ? OR b.service LIKE ? OR b.phone LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssss'; array_push($args, $like, $like, $like, $like);
}
$sql .= " ORDER BY FIELD(b.status,'Pending','Confirmed','Completed','Declined','Cancelled'), b.booking_date ASC";
$stmt = $conn->prepare($sql);
if ($args) { $stmt->bind_param($types, ...$args); }
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ---- Summary numbers (always across all bookings) ----
$sum = $conn->query("SELECT
    SUM(status='Pending') AS pending,
    SUM(status='Confirmed') AS confirmed,
    SUM(status='Completed') AS completed,
    COALESCE(SUM(CASE WHEN status IN ('Confirmed','Completed') THEN downpayment END),0) AS collected,
    COALESCE(SUM(CASE WHEN status='Confirmed' THEN price - downpayment END),0) AS balance
    FROM bookings")->fetch_assoc();
$nx = $conn->query("SELECT booking_date FROM bookings WHERE status='Confirmed' AND booking_date >= CURDATE() ORDER BY booking_date LIMIT 1")->fetch_assoc();

// Dates already taken by a confirmed booking (to warn about double booking)
$taken = [];
foreach ($conn->query("SELECT booking_date, COUNT(*) c FROM bookings WHERE status='Confirmed' GROUP BY booking_date") as $t) {
    $taken[$t['booking_date']] = (int) $t['c'];
}
function peso($n) { return '₱' . number_format((float) $n, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Owner Dashboard | Gio San Captures Co.</title>
  <link rel="icon" type="image/x-icon" href="images/icon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Allura&family=Merriweather:wght@400;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="dashboard.css">
<style>
/* admin table: keep text on one line */
.panel table th, .panel table td { white-space: nowrap; }
.panel table td:nth-child(2) { white-space: normal; min-width: 240px; max-width: 340px; }
.panel table { min-width: 960px; }
.wrap--wide { max-width: 1400px; }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar__in">
    <a href="index.php" class="brand"><b>Gio San Captures Co.</b><span>owner dashboard</span></a>
    <nav class="nav">
      <a href="admin.php" class="on">Bookings</a>
      <a href="index.php">View website</a>
      <a href="logout.php">Log out</a>
    </nav>
  </div>
</header>

<main class="wrap wrap--wide">
  <h1>Bookings</h1>
  <p class="sub">Review payments, confirm sessions, and keep your calendar clear.</p>

  <?php if (!empty($_GET['done'])): ?><div class="flash">Booking marked as <?= e($_GET['done']) ?>.</div><?php endif; ?>

  <section class="stats">
    <div class="stat stat--lead"><small>Needs your review</small><strong><?= (int) $sum['pending'] ?></strong></div>
    <div class="stat"><small>Confirmed sessions</small><strong><?= (int) $sum['confirmed'] ?></strong></div>
    <div class="stat"><small>Downpayments collected</small><strong><?= peso($sum['collected']) ?></strong></div>
    <div class="stat"><small>Balance to collect</small><strong><?= peso($sum['balance']) ?></strong></div>
    <div class="stat"><small>Next session</small><strong><?= $nx ? e(date('M j', strtotime($nx['booking_date']))) : '—' ?></strong></div>
  </section>

  <section class="panel">
    <div class="panel__head">
      <h2>All bookings</h2>
      <form class="filters" method="get">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, service or number" aria-label="Search">
        <select name="f" aria-label="Filter by status" onchange="this.form.submit()">
          <option value="">All status</option>
          <?php foreach ($valid as $s): ?>
            <option value="<?= $s ?>" <?= $f === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Search</button>
      </form>
    </div>

    <?php if (!$rows): ?>
      <div class="empty">No bookings match this view.</div>
    <?php else: ?>
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Client</th><th>Service</th><th>Date &amp; time</th><th>Phone</th>
              <th class="num">Price</th><th class="num">Downpayment</th>
              <th>Proof</th><th>Status</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r):
              $clash = $r['status'] === 'Pending' && !empty($taken[$r['booking_date']]); ?>
            <tr>
              <td><?= e($r['fullname']) ?><small><?= e($r['email']) ?></small></td>
              <td><?= e($r['service']) ?><small><?= e($r['payment_method']) ?></small></td>
              <td><?= e(date('M j, Y', strtotime($r['booking_date']))) ?><?php if (!empty($r['booking_time'])): ?><small><?= e(date('g:i A', strtotime($r['booking_time']))) ?></small><?php endif; ?>
                  <?php if ($clash): ?><span class="warn">Date already taken</span><?php endif; ?></td>
              <td><?= e($r['phone'] ?: '—') ?></td>
              <td class="num"><?= peso($r['price']) ?></td>
              <td class="num"><?= peso($r['downpayment']) ?></td>
              <td><a href="uploads/<?= e($r['proof_file']) ?>" target="_blank" rel="noopener">View</a></td>
              <td><span class="badge b-<?= e(strtolower($r['status'])) ?>"><?= e($r['status']) ?></span></td>
              <td>
                <div class="actions">
                <?php
                  $buttons = [];
                  if ($r['status'] === 'Pending')   { $buttons = ['confirm' => 'Confirm', 'decline' => 'Decline']; }
                  if ($r['status'] === 'Confirmed') { $buttons = ['complete' => 'Mark completed', 'decline' => 'Decline']; }
                  foreach ($buttons as $act => $label): ?>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                      <input type="hidden" name="booking_id" value="<?= (int) $r['id'] ?>">
                      <input type="hidden" name="action" value="<?= $act ?>">
                      <input type="hidden" name="f" value="<?= e($f) ?>">
                      <input type="hidden" name="q" value="<?= e($q) ?>">
                      <button type="submit" class="btn <?= $act === 'confirm' ? 'btn--main' : ($act === 'decline' ? 'btn--danger' : '') ?>"
                        <?= $act === 'decline' ? 'data-confirm="Decline this booking?" data-yes="Yes, decline" data-no="Go back" data-detail="' . e($r['fullname'] . ' · ' . $r['service']) . '"' : '' ?>><?= $label ?></button>
                    </form>
                <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>

<div class="modal" id="cmModal" hidden>
  <div class="modal__box" role="dialog" aria-modal="true" aria-labelledby="cmTitle">
    <h3 id="cmTitle"></h3>
    <p id="cmText"></p>
    <div class="modal__actions">
      <button type="button" class="btn" id="cmNo"></button>
      <button type="button" class="btn btn--solid-danger" id="cmYes"></button>
    </div>
  </div>
</div>
<style>
.modal{position:fixed;inset:0;background:rgba(20,28,32,.55);display:flex;align-items:center;justify-content:center;padding:20px;z-index:100}
.modal[hidden]{display:none}
.modal__box{background:#fff;border-radius:12px;max-width:400px;width:100%;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.modal__box h3{margin:0 0 6px;font-family:'Merriweather',serif;font-size:19px}
.modal__box p{margin:0 0 20px;color:#667076;font-size:14px}
.modal__actions{display:flex;gap:8px;justify-content:flex-end;margin-top:18px}
.btn--solid-danger{background:#b3261e;border-color:#b3261e;color:#fff}
.btn--solid-danger:hover{background:#8f1f19}
</style>
<script>
(function () {
  var m = document.getElementById('cmModal'), title = document.getElementById('cmTitle'),
      text = document.getElementById('cmText'), yes = document.getElementById('cmYes'),
      no = document.getElementById('cmNo'), pending = null;
  function close() { m.hidden = true; pending = null; }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm]');
    if (!b) return;
    e.preventDefault();
    pending = b.form;
    title.textContent = b.dataset.confirm;
    text.textContent = b.dataset.detail || '';
    text.style.display = text.textContent ? '' : 'none';
    yes.textContent = b.dataset.yes || 'Yes';
    no.textContent = b.dataset.no || 'Cancel';
    m.hidden = false;
    no.focus();
  });
  yes.addEventListener('click', function () { if (pending) pending.submit(); });
  no.addEventListener('click', close);
  m.addEventListener('click', function (e) { if (e.target === m) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !m.hidden) close(); });
})();
</script>
</body>
</html>
