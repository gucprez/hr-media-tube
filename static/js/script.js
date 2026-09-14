document.addEventListener("DOMContentLoaded", () => {
  const hamburger = document.getElementById("hamburger");
  const navLinks = document.getElementById("navLinks");

  if (hamburger && navLinks) {
    hamburger.addEventListener("click", () => {
      navLinks.classList.toggle("open");
    });
    navLinks.querySelectorAll("a").forEach((link) => {
      link.addEventListener("click", () => navLinks.classList.remove("open"));
    });
  }

  const yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  const filterBar = document.getElementById("airbnbFilters");
  const airbnbGrid = document.getElementById("airbnbGrid");
  const airbnbEmpty = document.getElementById("airbnbEmpty");
  if (filterBar && airbnbGrid) {
    const cards = airbnbGrid.querySelectorAll("[data-zone]");
    filterBar.addEventListener("click", (e) => {
      const btn = e.target.closest(".filter-btn");
      if (!btn) return;
      filterBar.querySelectorAll(".filter-btn").forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      const zone = btn.dataset.zone;
      let visibleCount = 0;
      cards.forEach((card) => {
        const matches = zone === "todos" || card.dataset.zone === zone;
        card.hidden = !matches;
        if (matches) visibleCount++;
      });
      if (airbnbEmpty) airbnbEmpty.hidden = visibleCount !== 0;
    });
  }

  const form = document.getElementById("contactForm");
  const note = document.getElementById("formNote");
  if (form && note) {
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      note.textContent = "Enviando...";
      try {
        const res = await fetch(form.action, {
          method: "POST",
          body: new FormData(form),
        });
        const data = await res.json();
        note.textContent = data.mensaje || "¡Solicitud enviada!";
        form.reset();
      } catch (err) {
        note.textContent =
          "No se pudo enviar el formulario. Escríbenos directo por WhatsApp.";
      }
    });
  }
});
