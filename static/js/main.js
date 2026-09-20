(function () {
  "use strict";

  // Mobile nav toggle
  var navToggle = document.getElementById("nav-toggle");
  var mainNav = document.getElementById("main-nav");
  if (navToggle && mainNav) {
    navToggle.addEventListener("click", function () {
      var isOpen = mainNav.classList.toggle("is-open");
      navToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });
  }

  // Player modal
  var modal = document.getElementById("player-modal");
  function openPlayer() {
    if (modal) modal.hidden = false;
  }
  function closePlayer() {
    if (modal) modal.hidden = true;
  }
  document.querySelectorAll("[data-close-player]").forEach(function (el) {
    el.addEventListener("click", closePlayer);
  });
  [
    "listen-bar-btn",
    "nav-listen-btn",
    "hero-listen-btn",
    "programacion-listen-btn",
    "mensajes-listen-btn",
  ].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) {
      el.addEventListener("click", function (e) {
        e.preventDefault();
        openPlayer();
      });
    }
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") closePlayer();
  });

  // Share buttons
  var pageUrl = window.location.href;
  document.querySelectorAll("[data-share]").forEach(function (btn) {
    btn.addEventListener("click", function (e) {
      var container = btn.closest("[data-share-text]");
      var text = container ? container.getAttribute("data-share-text") : document.title;
      var network = btn.getAttribute("data-share");

      if (network === "native") {
        if (navigator.share) {
          navigator.share({ title: document.title, text: text, url: pageUrl }).catch(function () {});
        } else {
          navigator.clipboard && navigator.clipboard.writeText(text + " " + pageUrl);
          alert("Enlace copiado. ¡Compártelo donde quieras!");
        }
        return;
      }

      e.preventDefault();
      var encodedText = encodeURIComponent(text);
      var encodedUrl = encodeURIComponent(pageUrl);
      var shareUrls = {
        whatsapp: "https://api.whatsapp.com/send?text=" + encodedText + "%20" + encodedUrl,
        facebook: "https://www.facebook.com/sharer/sharer.php?u=" + encodedUrl,
        x: "https://twitter.com/intent/tweet?text=" + encodedText + "&url=" + encodedUrl,
      };
      var target = shareUrls[network];
      if (target) window.open(target, "_blank", "noopener,noreferrer,width=600,height=500");
    });
  });

  // Forms (newsletter + testimony) via fetch
  function bindForm(form, endpoint, feedback) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var formData = new FormData(form);
      var submitBtn = form.querySelector("button[type=submit]");
      if (submitBtn) submitBtn.disabled = true;

      fetch(endpoint, { method: "POST", body: formData })
        .then(function (res) {
          return res.json().then(function (data) {
            return { ok: res.ok, data: data };
          });
        })
        .then(function (result) {
          if (feedback) {
            feedback.textContent = result.data.mensaje || "";
            feedback.style.color = result.ok ? "#3fbf6f" : "#e05252";
          }
          if (result.ok) form.reset();
        })
        .catch(function () {
          if (feedback) {
            feedback.textContent = "Ocurrió un error. Intenta de nuevo.";
            feedback.style.color = "#e05252";
          }
        })
        .finally(function () {
          if (submitBtn) submitBtn.disabled = false;
        });
    });
  }

  document.querySelectorAll("[data-newsletter-form]").forEach(function (form) {
    bindForm(form, "/suscribirse", form.parentElement.querySelector("[data-newsletter-feedback]"));
  });

  var testimonyForm = document.querySelector("[data-testimony-form]");
  if (testimonyForm) {
    bindForm(testimonyForm, "/testimonio", testimonyForm.querySelector("[data-testimony-feedback]"));
  }
})();
