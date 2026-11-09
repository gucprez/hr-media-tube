(function () {
  'use strict';

  // Menú móvil
  var boton = document.getElementById('menu-boton');
  var menu = document.getElementById('menu');
  if (boton && menu) {
    var cerrar = function () { menu.classList.remove('abierto'); boton.setAttribute('aria-expanded', 'false'); };
    boton.addEventListener('click', function () {
      boton.setAttribute('aria-expanded', String(menu.classList.toggle('abierto')));
    });
    menu.addEventListener('click', function (e) { if (e.target.closest('a')) cerrar(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.classList.contains('abierto')) { cerrar(); boton.focus(); }
    });
  }

  // Carrusel de actividades
  var pista = document.getElementById('carrusel-pista');
  if (pista) {
    var ant = document.getElementById('carrusel-ant');
    var sig = document.getElementById('carrusel-sig');
    var estado = document.getElementById('carrusel-estado');
    var items = pista.querySelectorAll('.tarjeta');
    var paso = function () {
      return items[0].getBoundingClientRect().width + (parseFloat(getComputedStyle(pista).columnGap) || 20);
    };
    var actualizar = function () {
      ant.disabled = pista.scrollLeft <= 2;
      sig.disabled = pista.scrollLeft >= pista.scrollWidth - pista.clientWidth - 2;
      var i = Math.min(items.length, Math.round(pista.scrollLeft / paso()) + 1);
      estado.textContent = 'Actividad ' + i + ' de ' + items.length;
    };
    ant.addEventListener('click', function () { pista.scrollBy({ left: -paso(), behavior: 'smooth' }); });
    sig.addEventListener('click', function () { pista.scrollBy({ left: paso(), behavior: 'smooth' }); });
    pista.addEventListener('scroll', function () { window.requestAnimationFrame(actualizar); }, { passive: true });
    window.addEventListener('resize', actualizar);
    actualizar();
  }

  // Formulario: prepara un correo (la web no guarda los mensajes)
  var form = document.getElementById('form-oracion');
  if (form) {
    var error = document.getElementById('f-error');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = form.elements.mensaje.value.trim();
      if (!msg) { error.hidden = false; form.elements.mensaje.focus(); return; }
      error.hidden = true;
      var nombre = form.elements.nombre.value.trim();
      window.location.href = 'mailto:' + form.dataset.correo +
        '?subject=' + encodeURIComponent(form.elements.tipo.value) +
        '&body=' + encodeURIComponent(msg + (nombre ? '\n\n— ' + nombre : ''));
    });
  }

  var anio = document.getElementById('anio');
  if (anio) anio.textContent = new Date().getFullYear();
})();
