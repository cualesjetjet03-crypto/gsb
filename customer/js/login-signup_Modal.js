// ------------ LOGIN & SIGNUP -------------------- 
document.getElementById("openLogin").onclick = () => {
    document.getElementById("loginModal").style.display = "flex";
  };
  
  document.getElementById("openSignupFromLogin").onclick = () => {
    document.getElementById("loginModal").style.display = "none";
    document.getElementById("signupModal").style.display = "flex";
  };
  
  document.getElementById("openLoginFromSignup").onclick = () => {
    document.getElementById("signupModal").style.display = "none";
    document.getElementById("loginModal").style.display = "flex";
  };
  
  document.querySelectorAll(".close").forEach(btn => {
    btn.onclick = () => {
      document.getElementById(btn.dataset.close).style.display = "none";
    };
  });
  
  /* Close modal when clicking outside */
  window.onclick = (e) => {
    if (e.target.classList.contains("modal")) {
      e.target.style.display = "none";
    }
  };
  
/* =============================
   CONTACT NUMBER VALIDATION
============================= */
document.getElementById("contactNumber").addEventListener("input", function () {
    this.value = this.value.replace(/[^0-9]/g, ""); // only numbers allowed

    const contactError = document.getElementById("contactError");

    if (this.value.length === 11) {
        this.style.border = "2px solid green";
        contactError.style.display = "none";
    } else {
        this.style.border = "2px solid red";
        contactError.style.display = "block";
    }
});

/* =============================
      ADDRESS VALIDATION
============================= */
document.getElementById("addressField").addEventListener("input", function () {
    const addressPattern = /^[A-Za-z0-9 ,.\-]+$/;
    const addressError = document.getElementById("addressError");

    if (addressPattern.test(this.value)) {
        this.style.border = "2px solid green";
        addressError.style.display = "none";
    } else {
        this.style.border = "2px solid red";
        addressError.style.display = "block";
    }
});

/* =============================
      FORM SUBMISSION VALIDATION
============================= */
document.getElementById("signupForm").addEventListener("submit", function (e) {
    const contact = document.getElementById("contactNumber").value;
    const address = document.getElementById("addressField").value;
    const addressPattern = /^[A-Za-z0-9 ,.\-]+$/;

    if (contact.length !== 11 || !addressPattern.test(address)) {
        e.preventDefault(); // stop form submission
        alert("Please fix the errors in the form.");
    }
});
