document.addEventListener("DOMContentLoaded", () => {
    initClock();
    initCounterAnimation();
    initRevealAnimation();
    initCardHover();
    initToastAutoHide();
    initBetterConfirm();
});

/* Đồng hồ nhỏ ở góc phải topbar */
function initClock() {
    const topbar = document.querySelector(".topbar");
    if (!topbar || document.querySelector(".topbar-clock")) return;

    const clock = document.createElement("div");
    clock.className = "topbar-clock";
    topbar.appendChild(clock);

    const updateClock = () => {
        const now = new Date();
        clock.innerHTML = `
            <span>${now.toLocaleDateString("vi-VN")}</span>
            <strong>${now.toLocaleTimeString("vi-VN", {
                hour: "2-digit",
                minute: "2-digit"
            })}</strong>
        `;
    };

    updateClock();
    setInterval(updateClock, 1000);
}

/* Hiệu ứng chạy số ở các thẻ thống kê */
function initCounterAnimation() {
    const numbers = document.querySelectorAll(".stat-card strong");

    numbers.forEach((number) => {
        const rawText = number.textContent.trim();
        const numericValue = Number(rawText.replace(/[^\d]/g, ""));

        if (!numericValue || numericValue <= 0) return;

        let current = 0;
        const duration = 850;
        const startTime = performance.now();
        const hasCurrency = rawText.includes("đ");

        const animate = (time) => {
            const progress = Math.min((time - startTime) / duration, 1);
            current = Math.floor(numericValue * progress);

            number.textContent = hasCurrency
                ? current.toLocaleString("vi-VN") + " đ"
                : current.toLocaleString("vi-VN");

            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };

        requestAnimationFrame(animate);
    });
}

/* Card xuất hiện mềm khi vào trang */
function initRevealAnimation() {
    const items = document.querySelectorAll(
        ".hero-panel, .stat-card, .panel, .product-card, .invoice-paper"
    );

    items.forEach((item, index) => {
        item.classList.add("reveal-item");
        item.style.transitionDelay = `${Math.min(index * 45, 260)}ms`;
    });

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add("is-visible");
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.12 }
    );

    items.forEach((item) => observer.observe(item));
}

/* Hover card nổi nhẹ theo chuột */
function initCardHover() {
    const cards = document.querySelectorAll(".stat-card, .product-card");

    cards.forEach((card) => {
        card.addEventListener("mousemove", (event) => {
            const rect = card.getBoundingClientRect();
            const x = event.clientX - rect.left;
            const y = event.clientY - rect.top;

            const rotateX = ((y / rect.height) - 0.5) * -5;
            const rotateY = ((x / rect.width) - 0.5) * 5;

            card.style.transform = `translateY(-4px) rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
        });

        card.addEventListener("mouseleave", () => {
            card.style.transform = "";
        });
    });
}

/* Alert tự biến mất giống toast */
function initToastAutoHide() {
    const alerts = document.querySelectorAll(".alert");

    alerts.forEach((alert) => {
        alert.classList.add("toast-alert");

        setTimeout(() => {
            alert.classList.add("hide");
            setTimeout(() => alert.remove(), 350);
        }, 3600);
    });
}

/* Popup confirm đẹp hơn confirm mặc định */
function initBetterConfirm() {
    const buttons = document.querySelectorAll("[data-confirm]");

    buttons.forEach((button) => {
        button.addEventListener("click", (event) => {
            const message = button.dataset.confirm || "Bạn có chắc chắn không?";

            event.preventDefault();

            showConfirmModal(message, () => {
                const form = button.closest("form");

                if (form) {
                    form.submit();
                } else if (button.href) {
                    window.location.href = button.href;
                }
            });
        });
    });
}

function showConfirmModal(message, onConfirm) {
    const oldModal = document.querySelector(".confirm-backdrop");
    if (oldModal) oldModal.remove();

    const modal = document.createElement("div");
    modal.className = "confirm-backdrop";
    modal.innerHTML = `
        <div class="confirm-box">
            <div class="confirm-icon">⚠️</div>
            <h3>Xác nhận thao tác</h3>
            <p>${escapeHtml(message)}</p>
            <div class="confirm-actions">
                <button type="button" class="btn ghost" data-close>Hủy</button>
                <button type="button" class="btn danger" data-ok>Đồng ý</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);

    modal.querySelector("[data-close]").addEventListener("click", () => {
        modal.remove();
    });

    modal.querySelector("[data-ok]").addEventListener("click", () => {
        modal.remove();
        onConfirm();
    });

    modal.addEventListener("click", (event) => {
        if (event.target === modal) {
            modal.remove();
        }
    });
}

function escapeHtml(text) {
    return String(text)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}