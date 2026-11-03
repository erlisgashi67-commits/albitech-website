/* Albitech site script: navigation, scroll reveal, counters, contact form. */
(function () {
  "use strict";

  var root = document.documentElement;
  root.classList.add("js");
  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- Sticky header shadow ---------- */
  var header = document.querySelector(".site-header");
  function onScroll() {
    if (header) header.classList.toggle("is-scrolled", window.scrollY > 8);
  }
  onScroll();
  window.addEventListener("scroll", onScroll, { passive: true });

  /* ---------- Mobile navigation ---------- */
  var toggle = document.querySelector(".nav-toggle");
  var nav = document.getElementById("site-nav");
  if (toggle && nav) {
    var setOpen = function (open) {
      toggle.setAttribute("aria-expanded", String(open));
      toggle.setAttribute("aria-label", open ? "Close menu" : "Open menu");
      nav.classList.toggle("is-open", open);
      document.body.classList.toggle("nav-open", open);
    };
    toggle.addEventListener("click", function () {
      setOpen(toggle.getAttribute("aria-expanded") !== "true");
    });
    nav.addEventListener("click", function (e) {
      if (e.target.closest("a")) setOpen(false);
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && nav.classList.contains("is-open")) {
        setOpen(false);
        toggle.focus();
      }
    });
    var desktop = window.matchMedia("(min-width: 961px)");
    var onBreakpoint = function (e) { if (e.matches) setOpen(false); };
    if (desktop.addEventListener) desktop.addEventListener("change", onBreakpoint);
    else if (desktop.addListener) desktop.addListener(onBreakpoint);
  }

  /* ---------- Animated counters ---------- */
  function formatNumber(value, decimals) {
    return value.toLocaleString("en-US", {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals
    });
  }
  function runCounter(el) {
    var target = parseFloat(el.getAttribute("data-count"));
    var decimals = parseInt(el.getAttribute("data-decimals") || "0", 10);
    if (isNaN(target)) return;
    if (reduceMotion) { el.textContent = formatNumber(target, decimals); return; }
    var duration = 1800;
    var start = null;
    function step(now) {
      if (start === null) start = now;
      var p = Math.min((now - start) / duration, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = formatNumber(target * eased, decimals);
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  /* ---------- Scroll reveal ---------- */
  var revealEls = document.querySelectorAll("[data-reveal], [data-observe]");
  var counters = document.querySelectorAll("[data-count]");

  function reveal(el) {
    el.classList.add("is-visible");
    var delay = parseFloat(getComputedStyle(el).getPropertyValue("--delay")) || 0;
    window.setTimeout(function () { el.classList.add("reveal-done"); }, (delay + 0.9) * 1000);
  }

  if (reduceMotion || !("IntersectionObserver" in window)) {
    revealEls.forEach(function (el) { el.classList.add("is-visible", "reveal-done"); });
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        reveal(entry.target);
        io.unobserve(entry.target);
      });
    }, { threshold: 0.15, rootMargin: "0px 0px -40px 0px" });
    revealEls.forEach(function (el) { io.observe(el); });

    // Counters start from zero and count up once they scroll into view.
    counters.forEach(function (el) { el.textContent = formatNumber(0, parseInt(el.getAttribute("data-decimals") || "0", 10)); });
    var counterIo = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        runCounter(entry.target);
        counterIo.unobserve(entry.target);
      });
    }, { threshold: 0.5 });
    counters.forEach(function (el) { counterIo.observe(el); });
  }

  /* ---------- Footer year ---------- */
  document.querySelectorAll("[data-year]").forEach(function (el) {
    el.textContent = String(new Date().getFullYear());
  });

  /* ---------- Contact form ---------- */
  var form = document.querySelector("[data-contact-form]");
  if (form) {
    var status = form.querySelector("[data-form-status]");
    var submitButton = form.querySelector("[type=submit]");
    var required = form.querySelectorAll("[required]");

    var setError = function (field, message) {
      var error = document.getElementById(field.id + "-error");
      field.setAttribute("aria-invalid", message ? "true" : "false");
      if (error) error.textContent = message || "";
    };
    var validate = function (field) {
      var message = "";
      if (field.type === "checkbox") {
        if (!field.checked) message = "Please confirm to continue.";
      } else if (!field.value.trim()) {
        message = "This field is required.";
      } else if (field.type === "email" && !field.validity.valid) {
        message = "Please enter a valid email address.";
      }
      setError(field, message);
      return !message;
    };
    var setStatus = function (state, text) {
      status.setAttribute("data-state", state);
      status.textContent = text;
    };

    required.forEach(function (field) {
      field.addEventListener("blur", function () {
        if (field.getAttribute("aria-invalid") === "true" || field.value) validate(field);
      });
      field.addEventListener("input", function () {
        if (field.getAttribute("aria-invalid") === "true") validate(field);
      });
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var firstInvalid = null;
      required.forEach(function (field) {
        if (!validate(field) && !firstInvalid) firstInvalid = field;
      });
      if (firstInvalid) {
        setStatus("error", "Please check the highlighted fields.");
        firstInvalid.focus();
        return;
      }

      var data = new FormData(form);
      var get = function (name) { return (data.get(name) || "").toString().trim(); };
      var subject = "Website enquiry: " + (get("interest") || "General") + " (" + (get("company") || get("name")) + ")";
      var endpoint = (form.getAttribute("action") || "").trim();
      var thanks = "Thank you. Your message has been sent and our team will be in touch shortly.";

      // Spam trap: real visitors never see this field, so a value means a bot.
      if (get("_gotcha")) {
        form.reset();
        setStatus("success", thanks);
        return;
      }

      // With a form endpoint in the action attribute (Formspree), post the data.
      if (endpoint) {
        data.append("_subject", subject);
        submitButton.disabled = true;
        setStatus("pending", "Sending your message...");
        fetch(endpoint, { method: "POST", body: data, headers: { Accept: "application/json" } })
          .then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (json) {
              if (res.ok) return;
              var detail = json && json.errors ? json.errors.map(function (err) { return err.message; }).join(" ") : "";
              throw new Error(detail);
            });
          })
          .then(function () {
            form.reset();
            required.forEach(function (field) { setError(field, ""); });
            setStatus("success", thanks);
          })
          .catch(function (err) {
            var detail = err && err.message ? err.message + " " : "";
            setStatus("error", "Sorry, your message could not be sent. " + detail + "Please try again or email office@albi-tech.net directly.");
          })
          .then(function () { submitButton.disabled = false; });
        return;
      }

      // Without an endpoint, hand the enquiry to the visitor's email app.
      var body = [
        "Name: " + get("name"),
        "Company: " + get("company"),
        "Email: " + get("email"),
        "Phone: " + (get("phone") || "-"),
        "Service of interest: " + (get("interest") || "-"),
        "Monthly volume: " + (get("volume") || "-"),
        "",
        get("message")
      ].join("\n");
      window.location.href = "mailto:office@albi-tech.net?subject=" + encodeURIComponent(subject) + "&body=" + encodeURIComponent(body);
      setStatus("success", "Your email app should now open with your message ready to send. If it does not, write to office@albi-tech.net.");
    });
  }
})();
