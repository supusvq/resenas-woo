document.addEventListener('DOMContentLoaded', function () {

  // ── "Leer más" — sólo expande el card que se pulsa ───────────────────
  document.addEventListener('click', function (e) {
    if (!e.target.classList.contains('mrg-read-more')) return;

    var btn = e.target;
    var content = btn.previousElementSibling;
    if (!content) return;

    var expanded = btn.getAttribute('data-expanded') === '1';

    if (expanded) {
      content.style.webkitLineClamp = '5';
      content.style.overflow = 'hidden';
      content.style.display = '-webkit-box';
      btn.setAttribute('data-expanded', '0');
      btn.textContent = 'Leer más';
    } else {
      content.style.webkitLineClamp = 'unset';
      content.style.overflow = 'visible';
      content.style.display = 'block';
      btn.setAttribute('data-expanded', '1');
      btn.textContent = 'Leer menos';
    }
  });

  // ── Auto-scroll infinito o Navegación Manual ───────────────────────────────────────────────
  document.querySelectorAll('.mrg-reviews-widget').forEach(function (widget) {
    var track = widget.querySelector('.mrg-reviews-track');
    var wrapper = widget.querySelector('.mrg-carousel-wrapper');
    if (!track || !wrapper) return;

    var mode = widget.getAttribute('data-mode') || 'auto';

    if (mode === 'manual') {
      // ── MODO MANUAL ──
      var prevBtn = widget.querySelector('.mrg-nav-prev');
      var nextBtn = widget.querySelector('.mrg-nav-next');

      // La cantidad a scrollear: Ancho de una tarjeta + gap
      // Obtenemos el gap y el ancho dinámicamente o usamos constantes aproximadas
      function getScrollAmount() {
        var firstCard = track.querySelector('.mrg-review-card');
        if (firstCard) {
          // width + gap
          return firstCard.offsetWidth + 16;
        }
        return 416; // 400px + 16px fallback
      }

      if (prevBtn) {
        prevBtn.addEventListener('click', function () {
          wrapper.scrollBy({ left: -getScrollAmount(), behavior: 'smooth' });
        });
      }

      if (nextBtn) {
        nextBtn.addEventListener('click', function () {
          wrapper.scrollBy({ left: getScrollAmount(), behavior: 'smooth' });
        });
      }

      // Evitamos clonar tarjetas o animaciones en modo manual
      return;
    }

    // ── MODO AUTOMÁTICO ──
    // Leer velocidad inyectada por PHP (data attribute en el widget)
    var speedAttr = widget.getAttribute('data-speed');
    var speed = speedAttr ? parseFloat(speedAttr) : 0.6;

    // Clonar todos los cards para el bucle infinito
    var origCards = Array.from(track.children);
    if (origCards.length === 0) return;
    origCards.forEach(function (card) {
      track.appendChild(card.cloneNode(true));
    });

    var pos = 0;
    var paused = false;
    var gap = 16;   // debe coincidir con el gap del CSS
    var setW = 0;

    function calcSetWidth() {
      var w = 0;
      for (var i = 0; i < origCards.length; i++) {
        var card = track.children[i];
        if (card) w += card.getBoundingClientRect().width + gap;
      }
      return w;
    }

    function step() {
      if (!paused) {
        pos += speed;
        if (pos >= setW) pos -= setW;
        track.style.transform = 'translateX(-' + pos + 'px)';
      }
      requestAnimationFrame(step);
    }

    function init() {
      setW = calcSetWidth();
      if (setW <= 0) {
        setTimeout(init, 100);
        return;
      }
      requestAnimationFrame(step);
    }

    widget.addEventListener('mouseenter', function () { paused = true; });
    widget.addEventListener('mouseleave', function () { paused = false; });
    widget.addEventListener('touchstart', function () { paused = true; }, { passive: true });
    widget.addEventListener('touchend', function () { paused = false; }, { passive: true });

    setTimeout(init, 300);
  });

});
