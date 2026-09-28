(function () {
  "use strict";

  // Header scroll state
  var header = document.getElementById("site-header");
  function onScroll() {
    if (!header) return;
    header.classList.toggle("is-scrolled", window.scrollY > 8);
  }
  onScroll();
  window.addEventListener("scroll", onScroll, { passive: true });

  // Mobile nav toggle
  var navToggle = document.getElementById("nav-toggle");
  var mainNav = document.getElementById("main-nav");
  if (navToggle && mainNav) {
    navToggle.addEventListener("click", function () {
      var isOpen = mainNav.classList.toggle("is-open");
      navToggle.classList.toggle("is-active", isOpen);
      navToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
      document.body.style.overflow = isOpen ? "hidden" : "";
    });
    mainNav.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        mainNav.classList.remove("is-open");
        navToggle.classList.remove("is-active");
        navToggle.setAttribute("aria-expanded", "false");
        document.body.style.overflow = "";
      });
    });
  }

  // Scroll reveal
  var revealEls = document.querySelectorAll(".reveal");
  if ("IntersectionObserver" in window && revealEls.length) {
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-in");
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12 }
    );
    revealEls.forEach(function (el) {
      io.observe(el);
    });
  } else {
    revealEls.forEach(function (el) {
      el.classList.add("is-in");
    });
  }

  // Sticky radio player
  var playerAudio = document.getElementById("player-audio");
  var playBtns = document.querySelectorAll("[data-player-toggle]");
  var iconPlayBtns = document.querySelectorAll(".player__play");
  var statusEls = document.querySelectorAll("[data-player-status]");
  var waveforms = document.querySelectorAll(".waveform");
  var streamUrl = playerAudio ? playerAudio.getAttribute("data-src") : "";
  var isPlaying = false;

  function setStatus(text, live) {
    statusEls.forEach(function (el) {
      el.textContent = text;
      el.classList.toggle("is-live", !!live);
    });
  }

  function setPlayingUI(playing) {
    isPlaying = playing;
    iconPlayBtns.forEach(function (btn) {
      btn.innerHTML = playing ? ICON_PAUSE : ICON_PLAY;
      btn.setAttribute("aria-label", playing ? "Pausar" : "Reproducir");
    });
    waveforms.forEach(function (wf) {
      wf.classList.toggle("is-paused", !playing);
    });
  }

  var ICON_PLAY = '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M8 5.5v13l11-6.5z"/></svg>';
  var ICON_PAUSE = '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>';

  if (playerAudio) {
    if (!streamUrl) {
      setStatus("Transmisión no disponible", false);
    } else {
      setStatus("Listo para transmitir", false);
    }

    playerAudio.addEventListener("waiting", function () {
      setStatus("Conectando…", false);
    });
    playerAudio.addEventListener("playing", function () {
      setStatus("En vivo", true);
      setPlayingUI(true);
    });
    playerAudio.addEventListener("pause", function () {
      setPlayingUI(false);
    });
    playerAudio.addEventListener("error", function () {
      setStatus("Transmisión no disponible", false);
      setPlayingUI(false);
    });
  }

  playBtns.forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (!playerAudio || !streamUrl) {
        setStatus("Transmisión no disponible", false);
        return;
      }
      if (isPlaying) {
        playerAudio.pause();
      } else {
        if (!playerAudio.src) playerAudio.src = streamUrl;
        setStatus("Conectando…", false);
        playerAudio.play().catch(function () {
          setStatus("Transmisión no disponible", false);
        });
      }
    });
  });

  var volumeInput = document.querySelector("[data-player-volume]");
  if (volumeInput && playerAudio) {
    volumeInput.addEventListener("input", function () {
      playerAudio.volume = parseInt(volumeInput.value, 10) / 100;
    });
  }

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
