<?php
require 'db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?need=1');
    exit;
}
$uid = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

// Client can cancel their own booking, only while it is still Pending
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')
    && ($_POST['action'] ?? '') === 'cancel') {
    $bid  = (int) ($_POST['booking_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE bookings SET status='Cancelled' WHERE id=? AND user_id=? AND status='Pending'");
    $stmt->bind_param('ii', $bid, $uid);
    $stmt->execute();
    header('Location: mybooking.php?cancelled=1');
    exit;
}

$stmt = $conn->prepare('SELECT id, booking_date, booking_time, service, price, downpayment, payment_method, status, created_at
                        FROM bookings WHERE user_id = ? ORDER BY booking_date DESC');
$stmt->bind_param('i', $uid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$today = date('Y-m-d');
$counts = ['Pending' => 0, 'Confirmed' => 0, 'Completed' => 0];
$next = null;
foreach ($rows as $r) {
    if (isset($counts[$r['status']])) { $counts[$r['status']]++; }
    if ($r['status'] === 'Confirmed' && $r['booking_date'] >= $today) {
        if ($next === null || $r['booking_date'] < $next['booking_date']) { $next = $r; }
    }
}
function peso($n) { return '₱' . number_format((float) $n, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Bookings | Gio San Captures Co.</title>
  <link rel="icon" type="image/x-icon" href="images/icon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Allura&family=Merriweather:wght@400;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="dashboard.css">
</head>
<body>

<header class="topbar">
  <div class="topbar__in">
    <a href="index.php" class="brand"><b>Gio San Captures Co.</b><span>photography</span></a>
    <nav class="nav">
      <a href="index.php">Home</a>
      <a href="booking.php">Book a session</a>
      <a href="mybooking.php" class="on">My bookings</a>
      <a href="logout.php">Log out</a>
    </nav>
  </div>
</header>

<main class="wrap">
  <h1>Hi, <?= e($_SESSION['user_name']) ?></h1>
  <p class="sub">Track your sessions and payments in one place.</p>

  <?php if (isset($_GET['cancelled'])): ?><div class="flash">Booking cancelled.</div><?php endif; ?>
  <?php if (isset($_GET['booked'])): ?><div class="flash">Booking received, <?= e($_SESSION['user_name']) ?>! Status: Pending. We'll contact you once your downpayment is confirmed.</div><?php endif; ?>

  <?php if ($next): ?>
    <section class="next">
      <div>
        <small>Your next session</small>
        <h2><?= e(date('l, F j, Y', strtotime($next['booking_date']))) ?></h2>
        <div><?= e($next['service']) ?></div>
      </div>
      <div class="num">
        <small>Balance due on the day</small><br>
        <strong style="font-family:'Merriweather',serif;font-size:22px"><?= peso($next['price'] - $next['downpayment']) ?></strong>
      </div>
    </section>
  <?php endif; ?>

  <section class="stats">
    <div class="stat"><small>Pending</small><strong><?= $counts['Pending'] ?></strong></div>
    <div class="stat"><small>Confirmed</small><strong><?= $counts['Confirmed'] ?></strong></div>
    <div class="stat"><small>Completed</small><strong><?= $counts['Completed'] ?></strong></div>
  </section>

  <section class="panel">
    <div class="panel__head">
      <h2>Your bookings</h2>
      <a class="btn btn--main" href="booking.php">New booking</a>
    </div>

    <?php if (!$rows): ?>
      <div class="empty">
        You haven't booked a session yet.<br>
        <a class="btn btn--main" href="booking.php">Book your first session</a>
      </div>
    <?php else: ?>
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Session date</th><th>Service</th>
              <th class="num">Price</th><th class="num">Downpayment</th><th class="num">Balance</th>
              <th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e(date('M j, Y', strtotime($r['booking_date']))) ?><?php if (!empty($r['booking_time'])): ?><small><?= e(date('g:i A', strtotime($r['booking_time']))) ?></small><?php endif; ?>
                  <small>Booked <?= e(date('M j', strtotime($r['created_at']))) ?></small></td>
              <td><?= e($r['service']) ?><small>Paid via <?= e($r['payment_method']) ?></small></td>
              <td class="num"><?= peso($r['price']) ?></td>
              <td class="num"><?= peso($r['downpayment']) ?></td>
              <td class="num"><?= peso($r['price'] - $r['downpayment']) ?></td>
              <td><span class="badge b-<?= e(strtolower($r['status'])) ?>"><?= e($r['status']) ?></span></td>
              <td>
                <?php if ($r['status'] === 'Pending'): ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="booking_id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn--danger" type="submit" data-confirm="Cancel this booking?" data-detail="<?= e($r['service']) ?> · <?= e(date('M j, Y', strtotime($r['booking_date']))) ?>" data-yes="Yes, cancel" data-no="Keep booking">Cancel</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <p class="sub">Pending means we're checking your downpayment. You'll be contacted once it's confirmed.</p>
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
