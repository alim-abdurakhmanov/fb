<?php

require_once __DIR__ . '/config.php';

if (!empty($_SESSION['user_id'])) {
    redirectByRole();
}

require_once __DIR__ . '/includes/public/landing_data.php';
require_once __DIR__ . '/includes/public/landing_calculator.php';
require_once __DIR__ . '/includes/mail.php';

$landingCalc = [
    'searched' => false,
    'product_type' => 'bg',
    'products' => [],
    'params' => [],
    'error' => null,
];

// Лид-форма из CTA (модалка): отправляем заявку в поддержку и делаем PRG редирект.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['finbuild_lead'] ?? '') === '1') {
    $name = trim((string) ($_POST['lead_name'] ?? ''));
    $phone = trim((string) ($_POST['lead_phone'] ?? ''));

    $cleanPhone = preg_replace('/[^\d+]+/', '', $phone) ?? $phone;
    $subject = 'FinBuild: заявка с лендинга';
    $html = '<p>Новая заявка с лендинга.</p>';
    $html .= '<p><strong>Как обращаться:</strong> ' . htmlspecialchars($name !== '' ? $name : '—', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '<br>';
    $html .= '<strong>Телефон:</strong> ' . htmlspecialchars($cleanPhone !== '' ? $cleanPhone : $phone, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';

    finbuild_send_mail('info@p-fg.com', $subject, $html);

    $wantsJson = false;
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (stripos($accept, 'application/json') !== false) {
        $wantsJson = true;
    }
    if (((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'XMLHttpRequest') {
        $wantsJson = true;
    }

    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: index.php?lead=sent', true, 303);
    exit;
}

// Подбор выполняется по GET (форма на лендинге отправляется методом GET).
if (($_GET['finbuild_landing_calc'] ?? '') === '1') {
    try {
        $landingCalc = finbuild_landing_execute_calculator(getPDO(), $_GET);
    } catch (Throwable $e) {
        $landingCalc['searched'] = true;
        $landingCalc['error'] = 'Не удалось выполнить подбор. Попробуйте позже.';
    }
}

?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FinBuild — банковские гарантии 44-ФЗ, 223-ФЗ, 185-ФЗ: кабинет агента и заказчика</title>
    <meta name="description" content="FinBuild: виды БГ по полю ФЗ, подбор программ банков как в личном кабинете, заявки, статусы, документы. Без заглушек «Другой банк» и КОРП-сегментов в публичном каталоге.">
    <link rel="icon" href="assets/favicons/favicon.ico" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/landing.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/landing.css') ?>">
</head>
<body class="landing-body">

<?php
require __DIR__ . '/includes/public/landing_nav.php';
require __DIR__ . '/includes/public/landing_hero.php';
echo '<div class="landing-band-group" aria-label="Для кого и банки">';
require __DIR__ . '/includes/public/landing_audience.php';
require __DIR__ . '/includes/public/landing_banks.php';
require __DIR__ . '/includes/public/landing_banks_cta.php';
echo '</div>';
require __DIR__ . '/includes/public/landing_bg_types.php';
require __DIR__ . '/includes/public/landing_selection.php';
require __DIR__ . '/includes/public/landing_features.php';
require __DIR__ . '/includes/public/landing_steps.php';
require __DIR__ . '/includes/public/landing_footer.php';
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const form = document.getElementById('landingCalcForm');
  if (form) {
    form.addEventListener('submit', () => {
      try {
        sessionStorage.setItem('finbuildLandingCalcScrollY', String(window.scrollY || 0));
      } catch (e) {}
    });
  }

  // Restore scroll position after GET reload from calc form.
  const params = new URLSearchParams(window.location.search);
  if (params.get('finbuild_landing_calc') === '1') {
    let y = null;
    try {
      const raw = sessionStorage.getItem('finbuildLandingCalcScrollY');
      if (raw !== null) y = Number(raw);
      sessionStorage.removeItem('finbuildLandingCalcScrollY');
    } catch (e) {}
    if (typeof y === 'number' && Number.isFinite(y)) {
      // Use rAF to ensure layout is ready before scrolling.
      requestAnimationFrame(() => window.scrollTo({ top: y, left: 0, behavior: 'auto' }));
    }
  }

  // Auto-open lead modal on successful submit, then clean URL.
  if (params.get('lead') === 'sent') {
    const modalEl = document.getElementById('landingLeadModal');
    if (modalEl && window.bootstrap?.Modal) {
      try {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
      } catch (e) {}
    }
    try {
      params.delete('lead');
      const next = window.location.pathname + (params.toString() ? `?${params.toString()}` : '') + window.location.hash;
      window.history.replaceState({}, '', next);
    } catch (e) {}
  }

  // AJAX submit lead form (no page reload).
  const leadForm = document.getElementById('landingLeadForm');
  const leadSuccess = document.getElementById('landingLeadSuccess');
  const leadSubmitBtn = document.getElementById('landingLeadSubmitBtn');
  const leadCloseBtn = document.getElementById('landingLeadCloseBtn');
  if (leadForm && leadSuccess && leadSubmitBtn && leadCloseBtn) {
    leadForm.addEventListener('submit', async function onLeadSubmit(e) {
      e.preventDefault();
      leadSubmitBtn.setAttribute('disabled', 'disabled');
      try {
        const res = await fetch(leadForm.action || window.location.pathname, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: new FormData(leadForm),
        });
        if (!res.ok) throw new Error('bad_status');
        const data = await res.json().catch(() => ({}));
        if (!data || data.ok !== true) throw new Error('bad_json');

        leadForm.classList.add('d-none');
        leadSuccess.classList.remove('d-none');
        leadSubmitBtn.classList.add('d-none');
        leadCloseBtn.classList.remove('d-none');
        leadForm.reset();
      } catch (err) {
        // fallback to normal submit if fetch fails
        leadForm.removeEventListener('submit', onLeadSubmit);
        leadForm.submit();
      } finally {
        leadSubmitBtn.removeAttribute('disabled');
      }
    });

    // Reset modal state when reopened
    const modalEl = document.getElementById('landingLeadModal');
    if (modalEl) {
      modalEl.addEventListener('hidden.bs.modal', () => {
        leadSuccess.classList.add('d-none');
        leadForm.classList.remove('d-none');
        leadSubmitBtn.classList.remove('d-none');
        leadCloseBtn.classList.add('d-none');
        leadSubmitBtn.removeAttribute('disabled');
      });
    }
  }

  // Mobile offcanvas menu links: close menu then scroll to anchor.
  const offcanvasEl = document.getElementById('landingNavCanvas');
  if (offcanvasEl && window.bootstrap?.Offcanvas) {
    offcanvasEl.addEventListener('click', (e) => {
      const a = e.target?.closest?.('a[href^="#"]');
      if (!a) return;
      const href = a.getAttribute('href') || '';
      const id = href.slice(1);
      if (!id) return;
      const target = document.getElementById(id);
      if (!target) return;

      e.preventDefault();
      try {
        const oc = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
        oc.hide();
      } catch (err) {}

      // Wait for offcanvas to close before scrolling.
      const doScroll = () => {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        offcanvasEl.removeEventListener('hidden.bs.offcanvas', doScroll);
      };
      offcanvasEl.addEventListener('hidden.bs.offcanvas', doScroll);
    });
  }
})();
</script>
</body>
</html>
