(function () {
  document.addEventListener("DOMContentLoaded", function () {
    var root = document.querySelector(".nexoflow-wrap");
    if (!root) {
      return;
    }
    var forms = root.querySelectorAll("form");
    Array.prototype.forEach.call(forms, function (form) {
      form.addEventListener("submit", function () {
        var controls = Array.prototype.slice.call(form.querySelectorAll("button, input[type='submit']"));
        // A button can sit outside its form and point at it, like Disconnect beside Save.
        if (form.id) {
          controls = controls.concat(Array.prototype.slice.call(root.querySelectorAll('button[form="' + form.id + '"]')));
        }
        controls.forEach(function (control) {
          control.disabled = true;
        });
      });
    });

    // The result of the last action sits in its own card and the page comes back scrolled to it.
    // Screen readers and the keyboard start from it too.
    var notice = root.querySelector(".nexoflow-notice");
    if (notice) {
      notice.focus({ preventScroll: true });
    }

    var log = root.querySelector("[data-nexoflow-log]");
    var problems = root.querySelector("[data-nexoflow-problems]");
    if (log && problems) {
      problems.addEventListener("change", function () {
        log.classList.toggle("is-problems-only", problems.checked);
      });
    }

    // One press turns on Elementor's own MCP switch, by asking Elementor the way its settings page does.
    var mcp = root.querySelector("[data-nexoflow-elementor-mcp]");
    if (mcp) {
      mcp.addEventListener("click", function () {
        var problem = root.querySelector("[data-nexoflow-elementor-problem]");
        var label = mcp.textContent;
        var failed = function () {
          mcp.disabled = false;
          mcp.textContent = label;
          if (problem) {
            problem.textContent = mcp.getAttribute("data-failed") || "";
            problem.hidden = false;
          }
        };
        mcp.disabled = true;
        mcp.textContent = mcp.getAttribute("data-working") || label;
        if (problem) {
          problem.hidden = true;
        }
        window
          .fetch(mcp.getAttribute("data-nexoflow-elementor-mcp"), {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-WP-Nonce": mcp.getAttribute("data-nonce") || "" },
            body: JSON.stringify({ enabled: true }),
          })
          .then(function (response) {
            return response.ok ? response.json() : null;
          })
          .then(function (body) {
            if (body && body.success && body.data && body.data.enabled === true) {
              // Back to this box, which now reads what the site says.
              window.location.hash = "nexoflow-elementor";
              window.location.reload();
            } else {
              failed();
            }
          })
          .catch(failed);
      });
    }

    var copy = root.querySelector("[data-nexoflow-copy]");
    if (copy) {
      copy.addEventListener("click", function () {
        var report = document.getElementById(copy.getAttribute("data-nexoflow-copy"));
        if (!report) {
          return;
        }
        var label = copy.textContent;
        var done = function () {
          copy.textContent = copy.getAttribute("data-copied") || label;
          window.setTimeout(function () {
            copy.textContent = label;
          }, 2000);
        };
        // Without clipboard access the report is shown, selected, to copy by hand.
        var byHand = function () {
          report.classList.add("is-shown");
          report.focus();
          report.select();
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(report.value).then(done, byHand);
        } else {
          byHand();
        }
      });
    }
  });
})();
