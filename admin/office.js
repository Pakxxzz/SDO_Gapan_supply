// admin/office.js
lucide.createIcons();

// Function to open edit modal with user data

function editItem(data) {
  document.getElementById("offId").value = data.OFF_ID;
  document.getElementById("offCode").value = data.OFF_CODE;
  document.getElementById("offName").value = data.OFF_NAME;
  document.getElementById("modalText").innerText = "Edit Office";
  document.getElementById("modal").classList.remove("hidden");

  document.getElementById("offCode").focus();
}

// Open modal for adding a new item
document.getElementById("openModal").addEventListener("click", function () {
  document.getElementById("form").reset();
  document.getElementById("offId").value = "";
  document.getElementById("modal").classList.remove("hidden");
  document.getElementById("offCode").focus();
  document.getElementById("modalText").innerText = "Add New Office";
});
// Close modal
document.getElementById("closeModal").addEventListener("click", function () {
  document.getElementById("modal").classList.add("hidden");
  document.getElementById("form").reset(); // Clear form
});

// Function to prompt for admin password
async function promptPassword() {
  const { value: password } = await Swal.fire({
    title: "Verification Required",
    input: "password",
    inputLabel: "Enter your password to continue",
    inputPlaceholder: "Password",
    showCancelButton: true,
    reverseButtons: true,
    inputValidator: (value) => {
      if (!value) {
        return "Password is required!";
      }
    },
  });

  return password;
}

// Form submission (Add/Edit)
document
  .getElementById("form")
  .addEventListener("submit", async function (event) {
    event.preventDefault();

    const formData = {
      off_id: document.getElementById("offId").value.trim(),
      offCode: document.getElementById("offCode").value.trim(),
      offName: document.getElementById("offName").value.trim(),
      action: document.getElementById("offId").value ? "edit" : "add",
    };

    // Validate required fields
    if (!formData.offCode || !formData.offName) {
      Swal.fire({
        icon: "warning",
        title: "Missing Fields",
        text: "Please fill in all required fields before submitting.",
      });
      return;
    }

    // const password = await promptPassword();
    // if (!password) return;

    // Show loading state
    Swal.fire({
      title: "Updating...",
      text: "Please wait while we update the item",
      allowOutsideClick: false,
      didOpen: () => {
        Swal.showLoading();
      },
    });

    fetch("office-php.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        ...formData,
        // password: password,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.status === "success") {
          Swal.fire({
            icon: "success",
            title: "Success!",
            text: data.message,
          }).then(() => {
            document.getElementById("form").reset();
            document.getElementById("modal").classList.add("hidden");
            setTimeout(() => location.reload(), 100);
          });
        } else {
          Swal.fire({ icon: "error", title: "Error", text: data.message });
        }
      })
      .catch((error) => {
        console.error("Fetch error:", error);
        Swal.fire({
          icon: "error",
          title: "Request Failed",
          text: "An error occurred. Please try again.",
        });
        console.log(formData);
      });
  });

// Delete user function
async function deleteItem(offId) {
  if (!offId) {
    Swal.fire({
      icon: "warning",
      title: "Something is missing",
      text: "Office ID is required to archive a office.",
    });
    return;
  }

  Swal.fire({
    title: "Are you sure?",
    text: "This action cannot be undone!",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Yes, archive it!",
    reverseButtons: true,
  }).then(async (result) => {
    if (result.isConfirmed) {
      // Show loading state

      const password = await promptPassword();
      if (!password) return;
      Swal.fire({
        title: "Updating...",
        text: "Please wait while we update the item",
        allowOutsideClick: false,
        didOpen: () => {
          Swal.showLoading();
        },
      });
      fetch("office-php.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          off_id: offId,
          action: "archive",
          sa_email: password.email,
          password: password,
        }),
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.status === "success") {
            Swal.fire({
              icon: "success",
              title: "Archived!",
              text: data.message,
            }).then(() => location.reload());
          } else {
            Swal.fire({ icon: "error", title: "Error", text: data.message });
          }
        })
        .catch((error) => {
          console.error("Error:", error);
          Swal.fire({
            icon: "error",
            title: "Request Failed",
            text: "An error occurred. Please try again.",
          });
        });
    }
  });
}
