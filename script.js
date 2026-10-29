(function () {
    var form = document.querySelector(".search-form");
    var messageBox = document.querySelector(".form-messages");
    var navToggle = document.querySelector(".nav-toggle");
    var siteNav = document.querySelector(".site-nav");
    var fallbackImage =
        "data:image/svg+xml," +
        encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800">' +
                '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' +
                '<stop offset="0%" stop-color="#0b1d36"/>' +
                '<stop offset="55%" stop-color="#1c3b66"/>' +
                '<stop offset="100%" stop-color="#c9a227"/>' +
                "</linearGradient></defs>" +
                '<rect width="100%" height="100%" fill="url(#g)"/>' +
                '<text x="50%" y="50%" fill="#f6f3ea" font-size="42" font-family="Georgia, serif" text-anchor="middle">The Grand Aurelia</text>' +
            "</svg>"
        );

    function todayText() {
        var now = new Date();
        var month = String(now.getMonth() + 1).padStart(2, "0");
        var day = String(now.getDate()).padStart(2, "0");
        return now.getFullYear() + "-" + month + "-" + day;
    }

    function showClientErrors(errors) {
        if (!messageBox) {
            return;
        }

        var list = document.createElement("ul");
        list.className = "alert alert-error";

        errors.forEach(function (error) {
            var item = document.createElement("li");
            item.textContent = error;
            list.appendChild(item);
        });

        messageBox.innerHTML = "";
        messageBox.appendChild(list);
    }

    document.querySelectorAll("img").forEach(function (image) {
        image.addEventListener("error", function () {
            if (image.dataset.fallbackApplied === "true") {
                return;
            }
            image.dataset.fallbackApplied = "true";
            image.classList.add("is-fallback");
            image.src = fallbackImage;
        });
    });

    if (navToggle && siteNav) {
        navToggle.addEventListener("click", function () {
            var isOpen = siteNav.classList.toggle("is-open");
            navToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
        });
    }

    if (!form) {
        return;
    }

    form.addEventListener("submit", function (event) {
        var checkIn = document.getElementById("check_in").value;
        var checkOut = document.getElementById("check_out").value;
        var guests = document.getElementById("guests").value;
        var errors = [];
        var guestCount = Number(guests);

        if (!checkIn) {
            errors.push("Please choose a check-in date.");
        }

        if (!checkOut) {
            errors.push("Please choose a check-out date.");
        }

        if (!guests || Number.isNaN(guestCount) || guestCount < 1) {
            errors.push("Please enter at least 1 guest.");
        } else if (guestCount > 10) {
            errors.push("Guest search is limited to 10 people for now.");
        }

        if (checkIn && checkIn < todayText()) {
            errors.push("Check-in cannot be in the past.");
        }

        if (checkIn && checkOut && checkOut <= checkIn) {
            errors.push("Check-out must be after check-in.");
        }

        if (errors.length > 0) {
            event.preventDefault();
            showClientErrors(errors);
        }
    });
})();
