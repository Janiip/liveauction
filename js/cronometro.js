// LiveAuction — cronometro.js
// Uso en HTML:
//   <span class="js-cronometro" data-id="5" data-segundos="3600">--:--</span>
// Cuenta hacia atrás en el navegador y cada 5 s se re-sincroniza con la BD
// (api_subasta_tiempo.php), así se refleja cualquier extensión del cronómetro.

(function () {
  const INTERVALO_SYNC_MS = 5000;

  function formatear(seg) {
    const d = Math.floor(seg / 86400);
    const h = Math.floor((seg % 86400) / 3600);
    const m = Math.floor((seg % 3600) / 60);
    const s = seg % 60;
    const dos = (n) => String(n).padStart(2, '0');
    if (d > 0) return d + 'd ' + dos(h) + 'h';
    return dos(h) + ':' + dos(m) + ':' + dos(s);
  }

  function iniciar(el) {
    let segundos = parseInt(el.dataset.segundos, 10) || 0;
    const id = el.dataset.id;
    let terminado = false;

    function pintar() {
      if (segundos <= 0) {
        terminado = true;
        el.textContent = 'Finalizada';
        el.classList.add('urgent');
        el.dispatchEvent(new CustomEvent('cronometro:fin', { bubbles: true }));
        return;
      }
      el.textContent = formatear(segundos);
      el.classList.toggle('urgent', segundos <= 300);
    }

    pintar();

    const tic = setInterval(function () {
      if (terminado) { clearInterval(tic); return; }
      segundos--;
      pintar();
    }, 1000);

    if (!id) return;

    const sync = setInterval(function () {
      if (terminado) { clearInterval(sync); return; }
      fetch('api_subasta_tiempo.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data) return;
          segundos = data.estado === 'activa' ? data.segundos_restantes : 0;
          pintar();
        })
        .catch(function () { /* sin red: sigue con la cuenta local */ });
    }, INTERVALO_SYNC_MS);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-cronometro').forEach(iniciar);
  });
})();