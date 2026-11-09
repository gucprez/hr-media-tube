(function () {
  'use strict';
  var EMAIL = 'farodeluzradio.com@gmail.com';

  // Menú móvil
  var boton = document.getElementById('menu-boton');
  var menu = document.getElementById('menu');
  function cerrar() { menu.classList.remove('abierto'); boton.setAttribute('aria-expanded', 'false'); }
  boton.addEventListener('click', function () {
    var abierto = menu.classList.toggle('abierto');
    boton.setAttribute('aria-expanded', String(abierto));
  });
  menu.addEventListener('click', function (e) { if (e.target.closest('a')) cerrar(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && menu.classList.contains('abierto')) { cerrar(); boton.focus(); }
  });

  // Carrusel de actividades
  var pista = document.getElementById('carrusel-pista');
  var ant = document.getElementById('carrusel-ant');
  var sig = document.getElementById('carrusel-sig');
  var estado = document.getElementById('carrusel-estado');
  var items = pista.querySelectorAll('.tarjeta');
  function paso() {
    var w = items[0].getBoundingClientRect().width;
    return w + parseFloat(getComputedStyle(pista).columnGap || 20);
  }
  function actualizar() {
    var max = pista.scrollWidth - pista.clientWidth - 2;
    ant.disabled = pista.scrollLeft <= 2;
    sig.disabled = pista.scrollLeft >= max;
    var i = Math.min(items.length, Math.round(pista.scrollLeft / paso()) + 1);
    estado.textContent = 'Actividad ' + i + ' de ' + items.length;
  }
  ant.addEventListener('click', function () { pista.scrollBy({ left: -paso(), behavior: 'smooth' }); });
  sig.addEventListener('click', function () { pista.scrollBy({ left: paso(), behavior: 'smooth' }); });
  pista.addEventListener('scroll', function () { window.requestAnimationFrame(actualizar); }, { passive: true });
  window.addEventListener('resize', actualizar);
  actualizar();

  // Formulario: prepara un correo (sitio estático, sin servidor)
  var form = document.getElementById('form-oracion');
  var error = document.getElementById('f-error');
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = form.elements.mensaje.value.trim();
    if (!msg) { error.hidden = false; form.elements.mensaje.focus(); return; }
    error.hidden = true;
    var nombre = form.elements.nombre.value.trim();
    var cuerpo = msg + (nombre ? '\n\n— ' + nombre : '');
    window.location.href = 'mailto:' + EMAIL + '?subject=' + encodeURIComponent(form.elements.tipo.value) +
      '&body=' + encodeURIComponent(cuerpo);
  });

  document.getElementById('anio').textContent = new Date().getFullYear();
})();
