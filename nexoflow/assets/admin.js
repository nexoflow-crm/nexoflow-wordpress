(function () {
  document.addEventListener("DOMContentLoaded", function () {
    var root = document.querySelector(".nexoflow-wrap");
    if (!root) {
      return;
    }
    var forms = root.querySelectorAll("form");
    Array.prototype.forEach.call(forms, function (form) {
      form.addEventListener("submit", function () {
        var controls = form.querySelectorAll("button, input[type='submit']");
        Array.prototype.forEach.call(controls, function (control) {
          control.disabled = true;
        });
      });
    });
  });
})();
