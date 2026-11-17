/* Albitech admin: add, remove and reorder rows, live previews, unsaved-change warning. */
(function () {
  "use strict";

  var form = document.getElementById("content-form");

  // Confirm before restoring an older version.
  document.querySelectorAll("form[data-confirm]").forEach(function (f) {
    f.addEventListener("submit", function (e) {
      if (!window.confirm(f.getAttribute("data-confirm"))) e.preventDefault();
    });
  });

  if (!form) return;

  var nextIndex = Date.now();
  var dirty = false;
  var status = form.querySelector("[data-dirty-status]");

  function markDirty() {
    if (dirty) return;
    dirty = true;
    if (status) {
      status.textContent = "You have unsaved changes.";
      status.classList.add("is-dirty");
    }
  }

  function listOf(name) { return form.querySelector('[data-list="' + name + '"]'); }
  function rowsOf(list) { return list.querySelectorAll("[data-row]"); }

  function refreshButtons(list) {
    var rows = rowsOf(list);
    var min = parseInt(list.getAttribute("data-min") || "0", 10);
    var max = parseInt(list.getAttribute("data-max") || "99", 10);
    rows.forEach(function (row, i) {
      row.querySelector('[data-move="-1"]').disabled = i === 0;
      row.querySelector('[data-move="1"]').disabled = i === rows.length - 1;
      row.querySelector("[data-remove]").disabled = rows.length <= min;
    });
    var add = form.querySelector('[data-add="' + list.getAttribute("data-list") + '"]');
    if (add) add.disabled = rows.length >= max;
  }

  // Add a row from its template. Each new row gets a fresh index; the server
  // keeps the rows in the order they appear on the page.
  form.querySelectorAll("[data-add]").forEach(function (button) {
    var name = button.getAttribute("data-add");
    var list = listOf(name);
    var template = form.querySelector('template[data-template="' + name + '"]');
    button.addEventListener("click", function () {
      var html = template.innerHTML.replace(/__i__/g, String(nextIndex++));
      list.insertAdjacentHTML("beforeend", html);
      var rows = rowsOf(list);
      var row = rows[rows.length - 1];
      refreshButtons(list);
      markDirty();
      updatePreviews();
      var first = row.querySelector("input");
      if (first) first.focus();
    });
  });

  // Move and remove buttons.
  form.addEventListener("click", function (e) {
    var button = e.target.closest("[data-move], [data-remove]");
    if (!button || button.disabled) return;
    var row = button.closest("[data-row]");
    var list = row.parentElement;
    if (button.hasAttribute("data-remove")) {
      var rows = Array.prototype.slice.call(rowsOf(list));
      var i = rows.indexOf(row);
      row.remove();
      var left = rowsOf(list);
      var focusRow = left[Math.min(i, left.length - 1)];
      if (focusRow) focusRow.querySelector("input").focus();
    } else {
      var dir = parseInt(button.getAttribute("data-move"), 10);
      var sibling = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
      if (!sibling || !sibling.hasAttribute("data-row")) return;
      if (dir < 0) list.insertBefore(row, sibling); else list.insertBefore(sibling, row);
      button.focus();
    }
    refreshButtons(list);
    markDirty();
    updatePreviews();
  });

  form.querySelectorAll("[data-list]").forEach(refreshButtons);

  // Live previews: how the numbers and the footer address will read.
  function val(el) { return el ? el.value.trim() : ""; }

  function updatePreviews() {
    var statsPreview = form.querySelector('[data-preview="stats"]');
    if (statsPreview) {
      var parts = [];
      rowsOf(listOf("stats")).forEach(function (row) {
        var v = val(row.querySelector('[name$="[value]"]'));
        var u = val(row.querySelector('[name$="[suffix]"]'));
        var l = val(row.querySelector('[name$="[label]"]'));
        if (v || l) parts.push(v + u + " " + l);
      });
      statsPreview.textContent = parts.length ? "Preview: " + parts.join("  ·  ") : "";
    }
    var addressPreview = form.querySelector('[data-preview="address"]');
    if (addressPreview) {
      var c = function (key) { return val(form.querySelector('[data-contact="' + key + '"]')); };
      var street = [c("street"), c("number")].filter(Boolean).join(" ");
      var town = [c("postalCode"), c("town")].filter(Boolean).join(" ");
      addressPreview.textContent = [street, town, c("country")].filter(Boolean).join(", ");
    }
    form.querySelectorAll("[data-count-lines]").forEach(function (el) {
      var area = document.getElementById(el.getAttribute("data-count-lines"));
      var n = area.value.split(/\n|,/).filter(function (s) { return s.trim(); }).length;
      el.textContent = n === 1 ? "1 country listed." : n + " countries listed.";
    });
  }

  form.addEventListener("input", function () { markDirty(); updatePreviews(); });
  form.addEventListener("change", markDirty);
  updatePreviews();

  // Warn before leaving with unsaved changes.
  form.addEventListener("submit", function () { dirty = false; });
  window.addEventListener("beforeunload", function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = "";
  });
})();
