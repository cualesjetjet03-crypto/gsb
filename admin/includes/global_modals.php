<!-- =========================================================
     GLOBAL CUSTOM ALERT & CONFIRM MODALS
========================================================== -->

<!-- CUSTOM ALERT MODAL -->
<div class="modal" id="globalAlertModal" style="z-index: 999999;">
    <div class="modal-card confirm-modal-card" style="padding: 30px 20px; max-width: 400px; text-align: center;">
        <button type="button" class="modal-close" onclick="closeGlobalAlert()"><i class="fa-solid fa-xmark"></i></button>
        <div style="font-size: 45px; color: #3b82f6; margin-bottom: 15px;"><i class="fa-solid fa-circle-info"></i></div>
        <h2 style="margin-bottom: 10px; font-size: 22px; color: #1e293b;">Notice</h2>
        <p id="globalAlertMessage" style="color: #64748b; font-size: 15px; margin-bottom: 25px; line-height: 1.5;"></p>
        <button type="button" class="primary-btn" onclick="closeGlobalAlert()" style="width: 100%; justify-content: center;">OK</button>
    </div>
</div>

<!-- CUSTOM CONFIRM MODAL -->
<div class="modal" id="globalConfirmModal" style="z-index: 999999;">
    <div class="modal-card confirm-modal-card" style="padding: 30px 20px; max-width: 400px; text-align: center;">
        <button type="button" class="modal-close" onclick="closeGlobalConfirm()"><i class="fa-solid fa-xmark"></i></button>
        <div style="font-size: 45px; color: #ef4444; margin-bottom: 15px;"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h2 style="margin-bottom: 10px; font-size: 22px; color: #1e293b;">Please Confirm</h2>
        <p id="globalConfirmMessage" style="color: #64748b; font-size: 15px; margin-bottom: 25px; line-height: 1.5;"></p>
        <div class="modal-actions" style="justify-content: center; gap: 15px; border-top: none; padding-top: 0; margin-top: 0;">
            <button type="button" class="outline-btn" onclick="closeGlobalConfirm()">Cancel</button>
            <button type="button" class="primary-btn" id="globalConfirmBtn" style="background: #ef4444; border-color: #ef4444;">Yes, Proceed</button>
        </div>
    </div>
</div>

<script>
// ==========================================
// OVERRIDE DEFAULT BROWSER ALERT & CONFIRM
// ==========================================

// Override window.alert (Usage stays exactly the same: alert("Hello"); )
window.alert = function(message) {
    document.getElementById('globalAlertMessage').textContent = message;
    document.getElementById('globalAlertModal').classList.add('show');
};

function closeGlobalAlert() {
    document.getElementById('globalAlertModal').classList.remove('show');
}

// Custom Confirm Function
// Usage on links: onclick="event.preventDefault(); customConfirm('Are you sure?', this.href);"
// Usage on forms: onclick="event.preventDefault(); customConfirm('Submit?', this.closest('form'));"
function customConfirm(message, actionUrlOrForm) {
    document.getElementById('globalConfirmMessage').textContent = message;
    document.getElementById('globalConfirmModal').classList.add('show');
    
    document.getElementById('globalConfirmBtn').onclick = function() {
        if (typeof actionUrlOrForm === 'string') {
            window.location.href = actionUrlOrForm; // Redirect if it's a URL
        } else if (actionUrlOrForm && actionUrlOrForm.submit) {
            actionUrlOrForm.submit(); // Submit if it's a form element
        }
        closeGlobalConfirm();
    };
}

function closeGlobalConfirm() {
    document.getElementById('globalConfirmModal').classList.remove('show');
}

// Close modals when clicking outside
document.addEventListener("click", function (e) {
    const globalAlertModal = document.getElementById("globalAlertModal");
    const globalConfirmModal = document.getElementById("globalConfirmModal");

    if (globalAlertModal && e.target === globalAlertModal) closeGlobalAlert();
    if (globalConfirmModal && e.target === globalConfirmModal) closeGlobalConfirm();
});

// Close modals on Escape key
document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
        closeGlobalAlert();
        closeGlobalConfirm();
    }
});
</script>